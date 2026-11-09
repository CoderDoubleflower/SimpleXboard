<?php

namespace Tests\Feature\Server;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\ServerOutbound;
use App\Services\NodeSyncService;
use App\Services\OutboundCapabilityService as Capability;
use App\Services\ServerService;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OutboundCapabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        admin_setting(['server_token' => 'outbound-agent-test-token']);
    }

    public function test_only_authenticated_requests_can_advertise_or_replace_machine_capability(): void
    {
        $machine = ServerMachine::create(['name' => 'Exit-capable VPS', 'token' => 'machine-test-token', 'is_active' => true]);
        $this->postJson('/api/v2/server/machine/nodes', ['machine_id' => $machine->id, 'token' => 'wrong'],
            [Capability::HEADER => Capability::FEATURE])->assertForbidden();
        $this->assertFalse(Capability::supportsMachine($machine->id));
        $this->postJson('/api/v2/server/machine/nodes', ['machine_id' => $machine->id, 'token' => $machine->token],
            [Capability::HEADER => 'other-feature, ' . Capability::FEATURE])->assertOk();
        $this->assertTrue(Capability::supportsMachine($machine->id));
        $this->postJson('/api/v2/server/machine/nodes', ['machine_id' => $machine->id, 'token' => 'wrong'])->assertForbidden();
        $this->assertTrue(Capability::supportsMachine($machine->id));
        $this->postJson('/api/v2/server/machine/nodes', ['machine_id' => $machine->id, 'token' => $machine->token])->assertOk();
        $this->assertFalse(Capability::supportsMachine($machine->id));
    }

    public function test_managed_node_denies_old_config_and_revokes_users_then_recovers_after_upgrade(): void
    {
        $node = $this->managedNode();
        $member = app(UserService::class)->createUser(['name' => 'Exit user']);
        $member->save();
        $query = '?' . http_build_query(['node_id' => $node->id, 'token' => 'outbound-agent-test-token']);
        $this->getJson('/api/v2/server/config' . $query)->assertStatus(409);
        $this->assertSame('error', $node->core_status['state']);
        $this->assertFalse($node->core_ready);
        $this->getJson('/api/v2/server/user' . $query)->assertOk()->assertJsonCount(0, 'users');
        $this->getJson('/api/v2/server/config' . $query, [Capability::HEADER => Capability::FEATURE])
            ->assertOk()->assertJsonPath('default_outbound', 'sx-outbound-' . $node->outbound_id);
        $this->getJson('/api/v2/server/user' . $query, [Capability::HEADER => Capability::FEATURE])
            ->assertOk()->assertJsonCount(1, 'users')->assertJsonPath('users.0.uuid', $member->uuid);
        $this->assertTrue($node->managed_outbound_supported);
        $this->getJson('/api/v2/server/user' . $query)->assertOk()->assertJsonCount(0, 'users');
        $this->assertFalse($node->managed_outbound_supported);
    }

    public function test_incompatible_node_never_receives_incremental_user_grants(): void
    {
        $node = $this->managedNode();
        $member = app(UserService::class)->createUser(['name' => 'Delta user']);
        $member->save();
        Cache::put('node_ws_alive:' . $node->id, true, 120);
        $messages = [];
        Redis::shouldReceive('publish')->andReturnUsing(function ($channel, $json) use (&$messages) {
            $messages[] = json_decode($json, true);
            return 1;
        });
        NodeSyncService::notifyUserChanged($member);
        $this->assertNotEmpty($messages);
        $this->assertSame(['remove'], array_column(array_column($messages, 'data'), 'action'));
        $this->assertCount(0, ServerService::getAvailableUsers($node));
        Capability::remember($node, null, true);
        $messages = [];
        NodeSyncService::notifyUserChanged($member);
        $this->assertSame(['add'], array_column(array_column($messages, 'data'), 'action'));
        $this->assertCount(1, ServerService::getAvailableUsers($node));
    }

    public function test_new_binding_requires_capability_but_unchanged_offline_binding_and_direct_are_editable(): void
    {
        $node = $this->managedNode();
        Capability::validateSelection($node, $node->outbound_id, null);
        Capability::validateSelection($node, null, null);
        try {
            Capability::validateSelection(null, $node->outbound_id, null);
            $this->fail('New independent node accepted an unverified agent.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('outbound_id', $error->errors());
        }
        $machine = ServerMachine::create(['name' => 'New machine', 'token' => 'machine-token', 'is_active' => true]);
        Capability::remember(null, $machine, true);
        Capability::validateSelection(null, $node->outbound_id, $machine->id);
        Capability::remember($node, null, true);
        $node->machine_id = $machine->id;
        $this->assertFalse(Capability::supportsNode($node), 'Moving a node must invalidate its prior advertisement.');
        Capability::remember($node, $machine, true);
        $this->assertTrue(Capability::supportsNode($node));
        try {
            Capability::validateSelection($node, $node->outbound_id, null);
            $this->fail('A machine advertisement authorized a different standalone identity.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('outbound_id', $error->errors());
        }
    }

    private function managedNode(): Server
    {
        $outbound = ServerOutbound::create(['name' => 'SOCKS exit', 'type' => 'socks',
            'settings' => ['server' => 'exit.example.test', 'server_port' => 1080, 'version' => '5']]);
        return Server::withoutEvents(fn () => Server::create([
            'name' => 'Managed ingress', 'type' => 'vless', 'host' => 'entry.example.test',
            'port' => '443', 'server_port' => 443, 'enabled' => true, 'show' => true,
            'protocol_settings' => ['tls' => 0, 'network' => 'tcp'], 'outbound_id' => $outbound->id,
        ]));
    }
}
