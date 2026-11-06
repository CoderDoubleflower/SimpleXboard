<?php

namespace Tests\Feature;

use App\Console\Commands\XboardInstall;
use App\Jobs\NodeUserSyncJob;
use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\User;
use App\Services\ServerService;
use App\Services\TrafficResetService;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PrivateMembersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        admin_setting(['server_token' => 'test-node-token', 'show_info_to_server_enable' => 0]);
    }

    public function test_fresh_install_admin_can_create_usable_members_without_orders(): void
    {
        $this->assertTrue(XboardInstall::registerAdmin('admin@example.com', 'test-password'));
        Sanctum::actingAs(User::byEmail('admin@example.com')->first());

        $response = $this->postJson($this->adminPath('user/create'), [
            'email' => 'Member@EXAMPLE.COM',
            'password' => 'member-password',
        ])->assertOk()->assertJsonPath('data.email', 'member@example.com');

        $member = User::byEmail('member@example.com')->firstOrFail();
        $other = $this->member('other@example.com');
        $this->assertTrue($member->isAvailable());
        $this->assertSame(1024 * 1073741824, $member->transfer_enable);
        $this->assertNull($member->expired_at);
        $this->assertNotEmpty($member->next_reset_at);
        $this->assertNotSame($member->uuid, $other->uuid);
        $this->assertNotSame($member->token, $other->token);
        $this->assertDatabaseCount('v2_order', 0);
        $response->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.balance');
        Bus::assertDispatched(NodeUserSyncJob::class);
    }

    public function test_member_creation_requires_an_admin_and_positive_quota(): void
    {
        Sanctum::actingAs($this->member());
        $data = ['email' => 'new@example.com', 'password' => 'member-password'];
        $this->postJson($this->adminPath('user/create'), $data)->assertForbidden();

        $this->asAdmin();
        $this->postJson($this->adminPath('user/create'), $data + ['transfer_enable' => 0])
            ->assertUnprocessable();
        $this->postJson($this->adminPath('user/create'), $data + ['plan_id' => 999999])
            ->assertUnprocessable();
        $this->assertDatabaseMissing('v2_user', ['email' => 'new@example.com']);
    }

    public function test_legacy_admin_generation_uses_default_configuration_without_purchase(): void
    {
        $this->asAdmin();
        $this->postJson($this->adminPath('user/generate'), [
            'email_prefix' => 'friend', 'email_suffix' => 'example.com',
            'password' => 'friend-password',
        ])->assertOk();
        $this->assertTrue(User::byEmail('friend@example.com')->firstOrFail()->isAvailable());
        $this->assertDatabaseCount('v2_order', 0);
    }

    public function test_upgrade_preserves_existing_credentials_quotas_and_node_scope(): void
    {
        $legacyGroup = new ServerGroup();
        $legacyGroup->name = 'legacy';
        $legacyGroup->save();
        $legacyPlan = Plan::create([
            'name' => 'legacy', 'group_id' => $legacyGroup->id, 'transfer_enable' => 40,
            'reset_traffic_method' => Plan::RESET_TRAFFIC_NEVER,
        ]);
        $member = app(UserService::class)->createUser([
            'email' => 'legacy@example.com', 'plan_id' => $legacyPlan->id,
            'expired_at' => time() + 86400,
        ]);
        $member->save();
        $node = $this->node($legacyGroup->id);
        $before = $member->getAttributes();

        $migration = require base_path('database/migrations/2026_11_06_000001_prepare_private_members.php');
        $migration->up();

        $this->assertSame($before, $member->fresh()->getAttributes());
        $this->assertSame([$node->id], array_column(ServerService::getAvailableServers($member), 'id'));
        $this->assertCount(1, ServerService::getAvailableUsers($node));
        $this->assertSame(1, Plan::where('is_internal', true)->count());

        // Editing an unrelated field must not repair/reassign a legacy group silently.
        $member->group_id = Plan::internalDefault()->group_id;
        $member->save();
        $this->asAdmin();
        $this->postJson($this->adminPath('user/update'), [
            'id' => $member->id, 'plan_id' => $legacyPlan->id, 'remarks' => 'retained',
        ])->assertOk();
        $this->assertSame(Plan::internalDefault()->group_id, $member->fresh()->group_id);
    }

    public function test_long_lived_internal_members_reset_monthly_without_changing_legacy_lifetime_plans(): void
    {
        $member = $this->member();
        $service = app(TrafficResetService::class);
        $next = $service->calculateNextResetTime($member);
        $this->assertSame(now()->addMonth()->startOfMonth()->timestamp, $next->timestamp);

        $member->update(['u' => 123, 'd' => 456]);
        $this->assertTrue($service->manualReset($member));
        $this->assertSame(0, $member->fresh()->getTotalUsedTraffic());
        $this->assertDatabaseHas('v2_traffic_reset_logs', ['user_id' => $member->id, 'old_total' => 579]);

        $plan = Plan::create([
            'name' => 'legacy lifetime', 'group_id' => $member->group_id, 'transfer_enable' => 5,
            'reset_traffic_method' => Plan::RESET_TRAFFIC_FIRST_DAY_MONTH,
        ]);
        $member->plan_id = $plan->id;
        $member->unsetRelation('plan');
        $this->assertNull($service->calculateNextResetTime($member));
    }

    public function test_ban_unban_and_secret_reset_reach_node_sync_and_revoke_subscription(): void
    {
        $member = $this->member();
        $node = $this->node($member->group_id);
        $oldToken = $member->token;
        $oldUuid = $member->uuid;
        $member->createToken('member');
        $this->asAdmin();
        Bus::fake();

        $this->postJson($this->adminPath('user/ban'), ['scope' => 'selected', 'user_ids' => [$member->id]])
            ->assertOk();
        $this->assertTrue($member->fresh()->banned);
        $this->assertCount(0, ServerService::getAvailableUsers($node));
        $this->assertSame(0, $member->tokens()->count());
        Bus::assertDispatched(NodeUserSyncJob::class, fn ($job) => $job->afterCommit === true);
        $this->get('/s/' . $oldToken)->assertForbidden();

        $this->asAdmin();
        $this->postJson($this->adminPath('user/update'), ['id' => $member->id, 'banned' => false])->assertOk();
        $this->assertCount(1, ServerService::getAvailableUsers($node));
        $this->postJson($this->adminPath('user/resetSecret'), ['id' => $member->id])->assertOk();
        $member->refresh();
        $this->assertNotSame($oldToken, $member->token);
        $this->assertNotSame($oldUuid, $member->uuid);
        $this->get('/s/' . $oldToken)->assertForbidden();
        $this->get('/s/' . $member->token)->assertOk();
        $this->assertSame($member->uuid, ServerService::getAvailableUsers($node)->first()->uuid);
    }

    public function test_group_change_sends_removal_to_old_nodes_and_addition_to_new_nodes(): void
    {
        $member = $this->member();
        $oldNode = $this->node($member->group_id);
        $group = new ServerGroup();
        $group->name = 'new group';
        $group->save();
        $newNode = $this->node($group->id);
        Cache::put('node_ws_alive:' . $oldNode->id, true);
        Cache::put('node_ws_alive:' . $newNode->id, true);
        Bus::fake();

        DB::transaction(fn () => $member->update(['group_id' => $group->id]));

        $messages = [];
        Redis::shouldReceive('publish')->twice()->withArgs(function ($channel, $json) use (&$messages) {
            $messages[] = json_decode($json, true);
            return $channel === 'node:push';
        })->andReturn(1);
        Bus::assertDispatched(NodeUserSyncJob::class, function ($job) {
            $job->handle();
            return true;
        });
        $this->assertSame($oldNode->id, $messages[0]['node_id']);
        $this->assertSame('remove', $messages[0]['data']['action']);
        $this->assertSame($newNode->id, $messages[1]['node_id']);
        $this->assertSame('add', $messages[1]['data']['action']);
    }

    public function test_optional_commerce_cleanup_keeps_core_management_and_subscriptions_usable(): void
    {
        $member = $this->member();
        $this->node($member->group_id);
        $identity = [$member->token, $member->uuid, $member->plan_id, $member->group_id];
        $migration = require base_path('database/cleanup/2026_11_06_000001_remove_retired_commerce.php');
        $migration->up();

        $this->asAdmin();
        $this->getJson($this->adminPath('user/fetch'))->assertOk();
        $this->getJson($this->adminPath('plan/fetch'))->assertOk();
        $this->getJson($this->adminPath('stat/getOverride'))->assertOk();
        $this->getJson($this->adminPath('stat/getStats'))->assertOk();
        $this->postJson($this->adminPath('user/create'), [
            'email' => 'after-cleanup@example.com', 'password' => 'member-password',
        ])->assertOk();

        Sanctum::actingAs($member);
        $this->getJson('/api/v1/user/info')->assertOk();
        $this->getJson('/api/v1/user/getStat')->assertOk()->assertJsonPath('data', [0, 0, 0]);
        $this->getJson('/api/v1/user/getSubscribe')->assertOk();
        $this->get('/s/' . $member->token)->assertOk();
        $member->refresh();
        $this->assertSame($identity, [$member->token, $member->uuid, $member->plan_id, $member->group_id]);
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('v2_order'));
    }

    public function test_retired_filters_cannot_expand_a_bulk_ban_to_all_members(): void
    {
        $member = $this->member();
        $other = $this->member('other@example.com');
        $member->createToken('member');
        $this->asAdmin();

        $this->postJson($this->adminPath('user/ban'), [
            'scope' => 'filtered',
            'filter' => [['id' => 'balance', 'value' => '>100']],
        ])->assertUnprocessable();

        $this->assertFalse($member->fresh()->banned);
        $this->assertFalse($other->fresh()->banned);
        $this->assertSame(1, $member->tokens()->count());
    }

    public function test_internal_configuration_survives_migration_rollback_and_reapply(): void
    {
        $member = $this->member();
        $planId = $member->plan_id;
        $groupId = $member->group_id;
        $migration = require base_path('database/migrations/2026_11_06_000001_prepare_private_members.php');
        $migration->down();
        $migration->up();

        $this->assertSame($planId, Plan::internalDefault()->id);
        $this->assertSame($groupId, Plan::internalDefault()->group_id);
        $this->assertSame(1, Plan::where('is_internal', true)->count());
        $this->assertNotNull(app(TrafficResetService::class)->calculateNextResetTime($member->fresh()));
    }

    public function test_force_updating_a_configuration_keeps_omitted_speed_and_device_limits(): void
    {
        $plan = Plan::internalDefault();
        $plan->update(['speed_limit' => 10, 'device_limit' => 2]);
        $member = $this->member();
        $this->asAdmin();

        $this->postJson($this->adminPath('plan/save'), [
            'id' => $plan->id, 'name' => $plan->name, 'group_id' => $plan->group_id,
            'transfer_enable' => 128, 'force_update' => true,
        ])->assertOk();

        $member->refresh();
        $this->assertSame(128 * 1073741824, $member->transfer_enable);
        $this->assertSame(10, $member->speed_limit);
        $this->assertSame(2, $member->device_limit);
        $this->assertSame(10, $plan->fresh()->speed_limit);
    }

    private function member(string $email = 'member@example.com'): User
    {
        $user = app(UserService::class)->createUser(['email' => $email, 'password' => 'member-password']);
        $user->save();
        return $user->refresh();
    }

    private function asAdmin(): void
    {
        if (!User::byEmail('admin@example.com')->exists()) {
            XboardInstall::registerAdmin('admin@example.com', 'test-password');
        }
        $admin = User::byEmail('admin@example.com')->firstOrFail();
        Sanctum::actingAs($admin);
    }

    private function adminPath(string $path): string
    {
        return '/api/v2/' . hash('crc32b', config('app.key')) . '/' . $path;
    }

    private function node(int $groupId): Server
    {
        return Server::create([
            'name' => 'private node', 'type' => Server::TYPE_VLESS,
            'host' => 'node.example.com', 'port' => 443, 'server_port' => 443,
            'rate' => '1', 'group_ids' => [(string) $groupId], 'show' => true, 'enabled' => true,
            'protocol_settings' => ['tls' => 0, 'network' => 'tcp'],
        ]);
    }
}
