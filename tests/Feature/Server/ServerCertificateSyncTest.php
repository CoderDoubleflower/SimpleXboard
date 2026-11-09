<?php

namespace Tests\Feature\Server;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\NodeSyncService;
use App\Services\ServerService;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServerCertificateSyncTest extends TestCase
{
    private array $messages = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Certificate repair must publish the new instance set only after commit.
        Artisan::call('migrate:fresh', ['--force' => true]);
        Bus::fake();
        Redis::shouldReceive('publish')->andReturnUsing(function ($channel, $payload) {
            $this->assertSame('node:push', $channel);
            $this->messages[] = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
            return 1;
        });
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_repair_reintroduces_the_machine_instance_only_after_commit(): void
    {
        $machine = $this->machine();
        $invalid = $this->node($machine, null);
        $valid = $this->node($machine, ['cert_mode' => 'self']);
        Cache::put('node_ws_alive:' . $invalid->id, true, 120);
        $this->assertSame([$valid->id], ServerService::getMachineNodes($machine)->pluck('id')->all());

        DB::beginTransaction();
        $invalid->update(['cert_config' => ['cert_mode' => 'self']]);
        $this->assertSame([], $this->messages);
        DB::rollBack();
        $this->assertSame([], $this->messages);
        $this->assertNull($invalid->fresh()->cert_config);

        DB::transaction(fn () => $invalid->fresh()->update(['cert_config' => ['cert_mode' => 'self']]));
        $config = collect($this->messages)->firstWhere('event', 'sync.config');
        $this->assertSame($invalid->id, $config['node_id']);
        $this->assertSame('self', $config['data']['config']['cert_config']['cert_mode']);
        $instances = collect($this->messages)->firstWhere('event', 'sync.nodes');
        $this->assertSame($machine->id, $instances['machine_id']);
        $this->assertEqualsCanonicalizing([$invalid->id, $valid->id], array_column($instances['data']['nodes'], 'id'));
        $this->assertSame([], app(\App\Services\ServerCertificateService::class)->errorsForNode($invalid->fresh()));
    }

    public function test_disabling_a_legacy_invalid_node_commits_without_interrupting_healthy_instances(): void
    {
        $admin = app(UserService::class)->createUser(['name' => 'Administrator']);
        $admin->is_admin = true;
        $admin->save();
        Sanctum::actingAs($admin);
        $machine = $this->machine();
        $invalid = $this->node($machine, null);
        $valid = $this->node($machine, ['cert_mode' => 'self']);
        Cache::put('node_ws_alive:' . $invalid->id, true, 120);

        $base = '/api/v2/' . admin_setting('secure_path', hash('crc32b', config('app.key'))) . '/server/manage';
        $this->postJson($base . '/update', ['id' => $invalid->id, 'enabled' => false])->assertOk();
        $this->assertFalse($invalid->fresh()->enabled);
        $this->assertTrue($valid->fresh()->enabled);
        $this->assertNotContains('sync.config', array_column($this->messages, 'event'));
        $revocation = collect($this->messages)->firstWhere('event', 'sync.users');
        $this->assertSame($invalid->id, $revocation['node_id']);
        $this->assertSame([], $revocation['data']['users']);
        $instances = collect($this->messages)->firstWhere('event', 'sync.nodes');
        $this->assertSame($machine->id, $instances['machine_id']);
        $this->assertSame([$valid->id], array_column($instances['data']['nodes'], 'id'));
    }

    public function test_standalone_certificate_repair_restores_users_with_the_configuration(): void
    {
        $node = $this->node(null, null);
        $member = app(UserService::class)->createUser(['name' => 'Authorized member']);
        $member->save();
        Cache::put('node_ws_alive:' . $node->id, true, 120);

        // A connected legacy instance was intentionally emptied when its source
        // failed validation. With WS connected it will not poll users via REST.
        NodeSyncService::notifyFullSync($node->id);
        $revocation = collect($this->messages)->firstWhere('event', 'sync.users');
        $this->assertSame([], $revocation['data']['users']);
        $this->messages = [];

        DB::transaction(fn () => $node->update(['cert_config' => ['cert_mode' => 'self']]));
        $this->assertSame(['sync.config', 'sync.users'], array_column($this->messages, 'event'));
        $this->assertSame('self', $this->messages[0]['data']['config']['cert_config']['cert_mode']);
        $this->assertSame([$member->id], array_column($this->messages[1]['data']['users'], 'id'));
        $this->assertSame($member->uuid, $this->messages[1]['data']['users'][0]['uuid']);
    }

    private function machine(): ServerMachine
    {
        return ServerMachine::create(['name' => 'Machine', 'token' => 'machine-token', 'is_active' => true]);
    }

    private function node(?ServerMachine $machine, ?array $certificate): Server
    {
        return Server::withoutEvents(fn () => Server::create([
            'name' => 'Hysteria2', 'type' => 'hysteria', 'host' => 'hy.example.com', 'port' => 443, 'server_port' => 8443,
            'machine_id' => $machine?->id, 'cert_config' => $certificate,
            'protocol_settings' => ['version' => 2, 'tls' => ['server_name' => 'hy.example.com', 'allow_insecure' => false],
                'bandwidth' => ['up' => 100, 'down' => 100], 'obfs' => ['open' => false]],
        ]));
    }
}
