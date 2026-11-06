<?php

namespace Tests\Feature\Server;

use App\Jobs\NodeUserSyncJob;
use App\Jobs\StatServerJob;
use App\Jobs\StatUserJob;
use App\Jobs\TrafficFetchJob;
use App\Models\Server;
use App\Models\User;
use App\Services\ServerService;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class MemberNodeLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // These tests need real commit boundaries, without RefreshDatabase's outer transaction.
        // Fresh schema isolation also avoids exercising unrelated historical down migrations.
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    private array $messages = [];

    public function test_rolled_back_member_change_never_reaches_nodes_and_commit_revokes_hidden_nodes_too(): void
    {
        $visible = $this->node();
        $hidden = $this->node(['show' => false]);
        $member = $this->member();
        $this->listen([$visible, $hidden]);

        DB::beginTransaction();
        $member->update(['banned' => true]);
        $this->assertSame([], $this->messages);
        DB::rollBack();
        $this->assertSame([], $this->messages);
        $this->assertFalse($member->fresh()->banned);

        DB::transaction(fn () => $member->fresh()->update(['banned' => true]));
        $this->assertSame([$visible->id, $hidden->id], array_column($this->messages, 'node_id'));
        foreach ($this->messages as $message) {
            $this->assertSame('remove', $message['data']['action']);
            $this->assertSame([['id' => $member->id]], $message['data']['users']);
        }
    }

    public function test_rotation_removes_old_credentials_before_adding_current_ones_and_old_jobs_cannot_resurrect_deleted_members(): void
    {
        $visible = $this->node();
        $hidden = $this->node(['show' => false]);
        $disabled = $this->node(['enabled' => false]);
        $member = $this->member();
        $oldUuid = $member->uuid;
        $this->listen([$visible, $hidden, $disabled]);
        DB::transaction(fn () => $member->update(['uuid' => 'f4d45c46-eab4-4d33-8fe7-c99b7eacdd99']));
        foreach ([$visible, $hidden] as $node) {
            $events = array_values(array_filter($this->messages, fn ($m) => $m['node_id'] === $node->id));
            $this->assertSame(['remove', 'add'], array_column(array_column($events, 'data'), 'action'));
            $this->assertSame($member->uuid, $events[1]['data']['users'][0]['uuid']);
        }
        $this->assertStringNotContainsString($oldUuid, json_encode($this->messages));
        $stale = new NodeUserSyncJob($member->id, 'updated', true);
        DB::transaction(fn () => $member->delete());
        $this->messages = [];
        $stale->handle();
        $this->assertCount(3, $this->messages);
        foreach ($this->messages as $message) $this->assertSame('remove', $message['data']['action']);
    }

    public function test_revoked_direct_grants_are_removed_from_nodes_that_are_no_longer_related(): void
    {
        $a = $this->node();
        $b = $this->node();
        $member = $this->member(['access_mode' => 'restricted']);
        $member->servers()->sync([$a->id]);
        $this->listen([$a, $b]);
        DB::transaction(fn () => app(UserService::class)->syncServerAccess($member, [$b->id]));
        $events = collect($this->messages)->keyBy('node_id');
        $this->assertSame('remove', $events[$a->id]['data']['action']);
        $this->assertSame('add', $events[$b->id]['data']['action']);
        $this->assertFalse(ServerService::getAvailableUsers($a)->contains('id', $member->id));
        $this->assertTrue(ServerService::getAvailableUsers($b)->contains('id', $member->id));
    }

    public function test_actual_bytes_ignore_old_job_rate_and_quota_or_expiry_revocations_run_without_plan_groups(): void
    {
        $node = $this->node();
        $member = $this->member(['transfer_enable' => 50]);
        $this->listen([$node]);
        Redis::shouldReceive('sadd')->once()->with('traffic:pending_check', $member->id)->andReturn(1);
        $snapshot = $node->toArray() + ['rate' => 9]; // Legacy serialized jobs must not multiply new bytes.
        (new TrafficFetchJob($snapshot, [$member->id => [20, 30]], 'vless', time()))->handle();
        (new StatUserJob($snapshot, [$member->id => [20, 30]], 'vless'))->handle();
        $this->assertSame(20, $member->fresh()->u);
        $this->assertSame(30, $member->fresh()->d);
        $this->assertDatabaseHas('v2_stat_user', ['user_id' => $member->id, 'u' => 20, 'd' => 30]);
        Redis::shouldReceive('scard')->once()->with('traffic:pending_check')->andReturn(1);
        Redis::shouldReceive('spop')->once()->with('traffic:pending_check', 1)->andReturn([(string) $member->id]);
        $this->artisan('check:traffic-exceeded')->assertSuccessful();
        $this->assertSame('remove', $this->messages[0]['data']['action']);
        $this->assertSame([], ServerService::getAvailableUsers($node)->all());

        DB::table('v2_user')->where('id', $member->id)->update(['transfer_enable' => null, 'expired_at' => time() - 1]);
        $this->messages = [];
        Redis::shouldReceive('scard')->once()->with('traffic:pending_check')->andReturn(0);
        $this->artisan('check:traffic-exceeded')->assertSuccessful();
        $this->assertSame('remove', $this->messages[0]['data']['action']);
    }

    public function test_node_quota_disable_reenable_and_delete_send_fresh_full_user_state(): void
    {
        $node = $this->node(['transfer_enable' => 50]);
        $member = $this->member();
        $this->listen([$node]);
        (new StatServerJob($node->toArray(), [$member->id => [20, 30]], 'vless'))->handle();
        $this->assertSame([], $this->lastUsers());
        $this->assertSame([], ServerService::getAvailableUsers($node->fresh())->all());
        DB::transaction(fn () => $node->fresh()->update(['u' => 0, 'd' => 0]));
        $this->assertSame($member->id, $this->lastUsers()[0]['id']);
        DB::transaction(fn () => $node->fresh()->update(['enabled' => false]));
        $this->assertSame([], $this->lastUsers());
        DB::transaction(fn () => $node->fresh()->update(['enabled' => true, 'show' => false]));
        $this->assertSame($member->id, $this->lastUsers()[0]['id']);
        $this->assertSame([], ServerService::getAvailableServers($member));
        DB::transaction(fn () => $node->delete());
        $this->assertSame([], $this->lastUsers());
    }

    private function lastUsers(): array
    {
        return collect($this->messages)->where('event', 'sync.users')->last()['data']['users'];
    }

    private function listen(array $nodes): void
    {
        foreach ($nodes as $node) Cache::put('node_ws_alive:' . $node->id, true, 600);
        Redis::shouldReceive('publish')->andReturnUsing(function ($channel, $payload) {
            $this->assertSame('node:push', $channel);
            $this->messages[] = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
            return 1;
        });
    }

    private function member(array $attributes = []): User
    {
        $member = app(UserService::class)->createUser(['name' => 'Member']);
        $member->fill($attributes)->save();
        return $member;
    }

    private function node(array $attributes = []): Server
    {
        return Server::create($attributes + [
            'name' => 'Node', 'type' => 'vless', 'host' => 'node.example.test',
            'port' => '443', 'server_port' => 443,
            'protocol_settings' => ['tls' => 2, 'network' => 'tcp'],
        ]);
    }
}
