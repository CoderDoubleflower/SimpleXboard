<?php

namespace Tests\Feature;

use App\Jobs\NodeUserSyncJob;
use App\Models\Server;
use App\Models\User;
use App\Services\ServerService;
use App\Services\TrafficResetService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PrivateMembersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    public function test_admin_creates_named_or_email_members_without_passwords_plans_or_orders(): void
    {
        $this->asAdmin();
        $named = $this->postJson($this->path('user/create'), ['name' => 'Family'])->assertOk()
            ->assertJsonPath('data.name', 'Family')->assertJsonPath('data.email', null)
            ->assertJsonPath('data.transfer_enable', null)->assertJsonPath('data.access_mode', 'all')
            ->assertJsonPath('data.eligible', true)->assertJsonPath('data.server_ids', [])
            ->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.plan_id');
        $email = $this->postJson($this->path('user/create'), ['email' => '  FRIEND@Example.com  '])
            ->assertOk()->assertJsonPath('data.email', 'friend@example.com');
        $first = User::findOrFail($named->json('data.id'));
        $second = User::findOrFail($email->json('data.id'));
        $this->assertNull($first->password);
        $this->assertNull($second->password);
        $this->assertNull($first->expired_at);
        $this->assertNotSame($first->uuid, $second->uuid);
        $this->assertNotSame($first->token, $second->token);
        foreach (['v2_plan', 'v2_server_group', 'v2_order'] as $table) $this->assertFalse(Schema::hasTable($table));
        Bus::assertDispatched(NodeUserSyncJob::class, fn ($job) => $job->afterCommit === true);
    }

    public function test_member_validation_keeps_zero_closed_and_protects_identity_and_admin_fields(): void
    {
        $this->asAdmin();
        $this->postJson($this->path('user/create'), [])->assertUnprocessable();
        $this->postJson($this->path('user/create'), ['name' => 'negative', 'transfer_enable' => -1])->assertUnprocessable();
        $this->postJson($this->path('user/create'), ['name' => 'portal', 'password' => 'not-allowed'])->assertUnprocessable();
        $this->postJson($this->path('user/create'), ['name' => 'role', 'is_admin' => true])->assertUnprocessable();
        $response = $this->postJson($this->path('user/create'), ['name' => 'Zero', 'transfer_enable' => 0])
            ->assertOk()->assertJsonPath('data.eligible', false)->assertJsonPath('data.remaining_traffic', 0);
        $this->postJson($this->path('user/update'), ['id' => $response->json('data.id'), 'name' => null])
            ->assertUnprocessable();
        $this->postJson($this->path('user/update'), ['id' => $response->json('data.id'), 'transfer_enable' => null])
            ->assertOk()->assertJsonPath('data.eligible', true)->assertJsonPath('data.remaining_traffic', null);
    }

    public function test_subscription_full_node_pull_and_model_share_every_quota_boundary(): void
    {
        $visible = $this->node();
        $hidden = $this->node(['name' => 'Hidden', 'show' => false]);
        $disabled = $this->node(['name' => 'Disabled', 'enabled' => false]);
        $fullNode = $this->node(['name' => 'Full', 'transfer_enable' => 10, 'u' => 10]);
        $cases = [
            [['transfer_enable' => null, 'u' => 1000, 'd' => 9000], true],
            [['transfer_enable' => 0], false],
            [['transfer_enable' => 100, 'u' => 49, 'd' => 50], true],
            [['transfer_enable' => 100, 'u' => 50, 'd' => 50], false],
            [['transfer_enable' => 100, 'u' => 101], false],
            [['expired_at' => time() - 1], false],
            [['banned' => true], false],
            [['is_admin' => true], false],
        ];
        foreach ($cases as [$attributes, $expected]) {
            $member = $this->member($attributes);
            $this->assertSame($expected, $member->isAvailable());
            $this->assertSame($expected, app(UserService::class)->isAvailable($member));
            $this->assertSame($expected, User::eligible()->whereKey($member->id)->exists());
            $this->assertSame($expected, ServerService::getAvailableUsers($visible)->contains('id', $member->id));
            $this->assertSame($expected, ServerService::getAvailableUsers($hidden)->contains('id', $member->id));
            $this->assertSame([], ServerService::getAvailableUsers($disabled)->all());
            $this->assertSame([], ServerService::getAvailableUsers($fullNode)->all());
            $this->assertSame($expected ? [$visible->id] : [], array_column(ServerService::getAvailableServers($member), 'id'));
        }
    }

    public function test_direct_access_is_bidirectional_and_all_does_not_depend_on_visibility(): void
    {
        $a = $this->node(['name' => 'A']);
        $b = $this->node(['name' => 'B']);
        $hidden = $this->node(['name' => 'Hidden', 'show' => false]);
        $member = $this->member(['access_mode' => 'restricted']);
        $member->servers()->sync([$a->id, $hidden->id]);
        $this->assertSame([$a->id], array_column(ServerService::getAvailableServers($member), 'id'));
        $this->assertTrue(ServerService::getAvailableUsers($a)->contains('id', $member->id));
        $this->assertTrue(ServerService::getAvailableUsers($hidden)->contains('id', $member->id));
        $this->assertFalse(ServerService::getAvailableUsers($b)->contains('id', $member->id));
        $member->update(['access_mode' => 'all']);
        $this->assertSame([$a->id, $b->id], array_column(ServerService::getAvailableServers($member), 'id'));
        $this->assertTrue(ServerService::getAvailableUsers($b)->contains('id', $member->id));
    }

    public function test_admin_updates_direct_access_and_no_admin_is_editable_through_member_actions(): void
    {
        $admin = $this->asAdmin();
        $member = $this->member();
        $node = $this->node();
        $this->postJson($this->path('user/update'), [
            'id' => $member->id, 'access_mode' => 'restricted', 'server_ids' => [$node->id],
        ])->assertOk()->assertJsonPath('data.server_ids', [$node->id]);
        $this->postJson($this->path('user/update'), [
            'id' => $member->id, 'server_ids' => [],
        ])->assertOk()->assertJsonPath('data.server_ids', []);
        $this->assertSame([], ServerService::getAvailableServers($member->fresh()));
        $this->getJson($this->path('user/fetch'))->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $member->id);
        foreach (['user/update', 'user/ban', 'user/resetSecret', 'user/destroy'] as $action) {
            $this->postJson($this->path($action), ['id' => $admin->id, 'banned' => true])->assertNotFound();
        }
        $this->assertTrue($admin->fresh()->is_admin);
        $this->assertFalse($admin->fresh()->banned);
    }

    public function test_disable_rotation_and_deletion_keep_subscription_identity_lifecycle_consistent(): void
    {
        $this->asAdmin();
        $member = $this->member();
        $oldToken = $member->token;
        $oldUuid = $member->uuid;
        $this->postJson($this->path('user/ban'), ['id' => $member->id, 'banned' => true])
            ->assertOk()->assertJsonPath('data.eligible', false);
        $this->postJson($this->path('user/ban'), ['id' => $member->id, 'banned' => false])
            ->assertOk()->assertJsonPath('data.eligible', true);
        $response = $this->postJson($this->path('user/resetSecret'), ['id' => $member->id])->assertOk();
        $this->assertNotSame($oldToken, $response->json('data.token'));
        $this->assertNotSame($oldUuid, $response->json('data.uuid'));
        $this->assertNull(User::where('token', $oldToken)->first());
        $this->postJson($this->path('user/destroy'), ['id' => $member->id])->assertOk();
        $this->assertNull(User::find($member->id));
        Bus::assertDispatched(NodeUserSyncJob::class, fn ($job) => $job->afterCommit === true);
    }

    public function test_manual_reset_recovers_a_hard_limit_without_reclassifying_legacy_bytes(): void
    {
        $member = $this->member([
            'transfer_enable' => 100, 'u' => 80, 'd' => 20, 'legacy_u' => 50, 'legacy_d' => 20,
            'actual_u' => 30, 'actual_d' => 0,
        ]);
        $this->assertFalse($member->isAvailable());
        $this->assertSame(30, $member->getActualUpload());
        $this->assertSame(0, $member->getActualDownload());
        $this->assertTrue(app(TrafficResetService::class)->manualReset($member, ['reason' => 'manual allowance reset']));
        $this->assertTrue($member->isAvailable());
        $this->assertSame(0, $member->getTotalUsedTraffic());
        $this->assertSame(0, $member->legacy_u);
        $this->assertDatabaseHas('v2_traffic_reset_logs', ['user_id' => $member->id, 'old_total' => 100, 'reset_type' => 'manual']);
        $this->assertSame(50, $member->trafficResetLogs()->first()->metadata['legacy_upload']);
    }

    public function test_export_and_search_do_not_expose_login_secrets_or_legacy_roles(): void
    {
        $this->asAdmin();
        $member = $this->member(['name' => '=Shared', 'transfer_enable' => null]);
        $this->getJson($this->path('user/fetch') . '?search=Shared')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonMissingPath('data.0.password')->assertJsonMissingPath('data.0.plan_id')
            ->assertJsonMissingPath('data.0.is_staff');
        $response = $this->postJson($this->path('user/dumpCSV'), ['scope' => 'selected', 'user_ids' => [$member->id]])->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString("'=Shared", $csv);
        $this->assertStringContainsString('unlimited', $csv);
        $this->assertStringContainsString(Helper::getSubscribeUrl($member->token), $csv);
        $this->assertStringNotContainsString('password', $csv);
        $this->postJson($this->path('user/generate'), ['generate_count' => 3])->assertNotFound();
    }

    private function member(array $attributes = []): User
    {
        $member = app(UserService::class)->createUser(['name' => 'Member ' . Helper::randomChar(6)]);
        $member->fill($attributes);
        $member->save();
        return $member;
    }

    private function asAdmin(): User
    {
        $admin = new User([
            'email' => 'admin@example.com', 'name' => 'Administrator', 'is_admin' => true,
            'password' => password_hash('admin-password', PASSWORD_DEFAULT), 'uuid' => Helper::guid(true), 'token' => Helper::guid(),
        ]);
        $admin->save();
        Sanctum::actingAs($admin);
        return $admin;
    }

    private function node(array $attributes = []): Server
    {
        return Server::create($attributes + [
            'name' => 'Visible', 'type' => 'vless', 'host' => 'node.example.test',
            'port' => '443', 'server_port' => 443, 'show' => true, 'enabled' => true,
            'protocol_settings' => ['tls' => 2, 'network' => 'tcp'],
        ]);
    }

    private function path(string $path): string
    {
        return '/api/v2/' . admin_setting('secure_path', hash('crc32b', config('app.key'))) . '/' . $path;
    }
}
