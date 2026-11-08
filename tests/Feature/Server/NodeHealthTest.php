<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V2\Admin\StatController;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\NodeHealthService;
use App\Services\NodeRegistry;
use App\Services\ServerService;
use App\Services\UserService;
use App\WebSocket\NodeWorker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;
use Workerman\Connection\TcpConnection;

class NodeHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        admin_setting(['server_token' => 'server-token', 'server_push_interval' => 60]);
    }

    public function test_machine_heartbeat_and_user_pulls_never_imply_core_readiness(): void
    {
        $machine = $this->machine();
        $node = $this->node(['machine_id' => $machine->id]);
        $this->postJson('/api/v2/server/machine/status', [
            'machine_id' => $machine->id, 'token' => 'machine-token',
            'cpu' => 1, 'mem' => ['total' => 10, 'used' => 2],
        ])->assertOk();
        $this->getJson('/api/v2/server/user?' . http_build_query([
            'machine_id' => $machine->id, 'token' => 'machine-token', 'node_id' => $node->id,
        ]))->assertOk()->assertJsonCount(0, 'users');
        $node = $node->fresh();
        $this->assertTrue($node->machine_online);
        $this->assertTrue($node->control_recent);
        $this->assertFalse($node->control_online);
        $this->assertSame('unknown', $node->core_status['state']);
        $this->assertFalse($node->core_ready);
        $this->assertSame(0, $node->is_online);
        $this->assertSame(Server::STATUS_ONLINE_NO_PUSH, $node->available_status);
    }

    public function test_rest_reports_support_waiting_running_error_and_recovery_without_traffic(): void
    {
        $node = $this->node();
        foreach ([
            $this->core('waiting_users', false, 0, 0),
            $this->core('running', true, 1, 1),
            $this->core('waiting_users', false, 0, 0),
            $this->core('error', false, 1, 0, 'listen tcp :17881: address already in use'),
            $this->core('running', true, 1, 1),
        ] as $core) {
            $this->report($node, ['metrics' => ['core_status' => $core]])->assertOk();
            $this->assertSame($core['state'], $node->core_status['state']);
            $this->assertSame($core['ready'], $node->core_ready);
            $this->assertSame($core['error'], $node->core_status['error']);
            $this->assertSame($core['ready'] ? 1 : 0, $node->is_online);
        }
        $this->assertNull($node->last_push_at);
        $this->assertSame(Server::STATUS_ONLINE, $node->available_status);
    }

    public function test_legacy_explicit_kernel_boolean_is_supported_but_missing_and_strings_are_unknown(): void
    {
        $node = $this->node();
        foreach ([[], ['cpu' => 1], ['kernel_status' => 'false'], ['kernel_status' => 1]] as $metrics) {
            $this->report($node, ['metrics' => $metrics])->assertOk();
            $this->assertSame('unknown', $node->core_status['state']);
            $this->assertFalse($node->core_ready);
        }
        $this->report($node, ['metrics' => ['kernel_status' => false]])->assertOk();
        $this->assertSame('stopped', $node->core_status['state']);
        $this->assertFalse($node->core_ready);
        $this->report($node, ['metrics' => ['kernel_status' => true]])->assertOk();
        $this->assertTrue($node->core_ready);
        $this->assertSame('kernel_status', $node->core_status['source']);
        $this->report($node, ['status' => ['kernel_status' => false]])->assertOk();
        $this->assertFalse($node->core_ready);
    }

    public function test_structured_report_needs_running_and_applied_desired_users_to_agree(): void
    {
        $node = $this->node();
        foreach ([
            $this->core('running', true, 1, 0),
            $this->core('running', true, 0, 0),
            $this->core('starting', true, 1, 1),
            $this->core('running', false, 1, 1),
            ['state' => 'running', 'ready' => true],
        ] as $core) {
            $this->report($node, ['metrics' => ['core_status' => $core, 'kernel_status' => true]])->assertOk();
            $this->assertFalse($node->core_ready);
        }
    }

    public function test_structured_status_wins_over_legacy_metrics_in_the_same_rest_report(): void
    {
        $node = $this->node();
        $this->report($node, [
            'status' => ['core_status' => $this->core('waiting_users', false, 0, 0)],
            'metrics' => ['kernel_status' => true],
        ])->assertOk();
        $this->assertFalse($node->core_ready);
        $this->assertSame('waiting_users', $node->core_status['state']);
    }

    public function test_heartbeats_and_metrics_without_core_status_do_not_refresh_core_report_age(): void
    {
        $node = $this->node();
        $this->report($node, ['metrics' => ['core_status' => $this->core('running', true, 1, 1)]])->assertOk();
        $received = $node->core_status['updated_at'];
        $this->travel(301)->seconds();
        NodeHealthService::touchControl($node->id);
        $this->report($node, ['metrics' => ['total_users' => 1, 'uptime' => 400]])->assertOk();
        $this->assertSame($received, $node->core_status['updated_at']);
        $this->assertSame('stale', $node->core_status['state']);
        $this->assertFalse($node->core_ready);
        $this->assertTrue($node->control_online);
        $this->assertSame(0, $node->is_online);
    }

    public function test_report_freshness_uses_panel_receipt_time_and_configured_push_interval(): void
    {
        admin_setting(['server_push_interval' => 180]);
        $node = $this->node();
        $this->report($node, ['metrics' => ['core_status' => $this->core('running', true, 1, 1) + ['updated_at' => PHP_INT_MAX]]])->assertOk();
        $this->assertSame(now()->timestamp, $node->core_status['updated_at']);
        $this->travel(301)->seconds();
        $this->assertTrue($node->core_ready);
        $this->travel(240)->seconds();
        $this->assertFalse($node->core_ready);
    }

    public function test_machine_websocket_routes_status_to_only_its_reported_node_and_pongs_do_not_start_cores(): void
    {
        $a = $this->node();
        $b = $this->node();
        $conn = Mockery::mock(TcpConnection::class);
        $conn->machineId = 99;
        $conn->machineNodeIds = [$a->id, $b->id];
        $worker = new NodeWorker('127.0.0.1', 0);
        $worker->onMessage($conn, json_encode(['event' => 'pong']));
        $this->assertTrue($a->control_online);
        $this->assertTrue($b->control_online);
        $this->assertFalse($a->core_ready);
        $this->assertFalse($b->core_ready);
        $worker->onMessage($conn, json_encode([
            'event' => 'node.status',
            'data' => ['node_id' => $a->id, 'core_status' => $this->core('running', true, 1, 1)],
        ]));
        $this->assertTrue($a->core_ready);
        $this->assertFalse($b->core_ready);
        $worker->onMessage($conn, json_encode([
            'event' => 'node.status',
            'data' => ['node_id' => 999999, 'core_status' => $this->core('running', true, 1, 1)],
        ]));
        $this->travel(NodeHealthService::CONTROL_TTL + 1)->seconds();
        $this->assertFalse($a->control_online);
        $this->assertFalse($b->control_online);
    }

    public function test_v2_standalone_status_endpoint_preserves_core_report(): void
    {
        $node = $this->node();
        $this->postJson('/api/v2/server/status', [
            'token' => 'server-token', 'node_id' => $node->id, 'cpu' => 0,
            'mem' => ['total' => 0, 'used' => 0], 'swap' => ['total' => 0, 'used' => 0],
            'disk' => ['total' => 0, 'used' => 0],
            'core_status' => $this->core('running', true, 1, 1),
        ])->assertOk();
        $this->assertTrue($node->core_ready);
    }

    public function test_monitoring_fields_do_not_remove_authorized_nodes_from_subscriptions(): void
    {
        $node = $this->node();
        $user = app(UserService::class)->createUser(['name' => 'Member']);
        $this->assertSame([$node->id], array_column(ServerService::getAvailableServers($user), 'id'));
        $this->report($node, ['metrics' => ['core_status' => $this->core('error', false, 1, 0, 'start failed')]])->assertOk();
        $this->assertSame([$node->id], array_column(ServerService::getAvailableServers($user), 'id'));
    }

    public function test_machine_token_cannot_report_another_machines_core_and_admin_fields_are_explicit(): void
    {
        $machine = $this->machine();
        $node = $this->node();
        $this->postJson('/api/v2/server/report', [
            'machine_id' => $machine->id, 'token' => 'machine-token', 'node_id' => $node->id,
            'metrics' => ['core_status' => $this->core('running', true, 1, 1)],
        ])->assertStatus(400);
        $this->assertFalse($node->core_ready);
        $data = ServerService::getAllServers()->first()->toArray();
        $this->assertSame('unknown', $data['core_status']['state']);
        $this->assertFalse($data['core_ready']);
        $this->assertFalse($data['control_online']);
        $this->assertFalse($data['control_recent']);
        $this->assertNull($data['machine_online']);
    }

    public function test_overview_counts_only_fresh_ready_cores(): void
    {
        $ready = $this->node();
        $waiting = $this->node();
        $unknown = $this->node();
        $this->report($ready, ['metrics' => ['core_status' => $this->core('running', true, 1, 1)]])->assertOk();
        $this->report($waiting, ['metrics' => ['core_status' => $this->core('waiting_users', false, 0, 0)]])->assertOk();
        ServerService::touchNode($unknown);
        $stats = app(StatController::class)->getStats()->getData(true)['data'];
        $this->assertSame(3, $stats['totalNodes']);
        $this->assertSame(1, $stats['onlineNodes']);
        $this->assertSame(1, $stats['readyNodes']);
    }

    public function test_machine_control_lifecycle_handles_empty_sets_removed_nodes_and_replaced_sockets(): void
    {
        $node = $this->node();
        $old = Mockery::mock(TcpConnection::class);
        $old->machineId = 99;
        $old->machineNodeIds = [$node->id];
        $old->shouldReceive('close')->andReturnNull();
        $new = Mockery::mock(TcpConnection::class);
        $new->machineId = 99;
        $new->machineNodeIds = [$node->id];
        NodeRegistry::addMachine(99, $old);
        NodeRegistry::add($node->id, $old);
        NodeRegistry::addMachine(99, $new);
        NodeRegistry::add($node->id, $new);
        NodeHealthService::touchMachineControl(99);
        NodeHealthService::touchControl($node->id);
        $worker = new NodeWorker('127.0.0.1', 0);
        $worker->onClose($old);
        $this->assertSame($new, NodeRegistry::get($node->id));
        $this->assertTrue($node->control_online);
        $this->assertTrue(Cache::get('machine_ws_alive:99'));
        NodeRegistry::refreshMachineNodes(99, []);
        $this->assertFalse($node->control_online);
        $this->assertNull(NodeRegistry::get($node->id));
        $worker->onMessage($new, json_encode(['event' => 'pong']));
        $this->assertTrue(Cache::get('machine_ws_alive:99'));
        $worker->onClose($new);
        $this->assertNull(NodeRegistry::getMachine(99));
        $this->assertNull(Cache::get('machine_ws_alive:99'));
    }

    private function report(Server $node, array $data)
    {
        return $this->postJson('/api/v2/server/report', $data + ['token' => 'server-token', 'node_id' => $node->id]);
    }

    private function core(string $state, bool $ready, int $users, int $applied, ?string $error = null): array
    {
        return ['state' => $state, 'ready' => $ready, 'users' => $users, 'applied_users' => $applied, 'error' => $error];
    }

    private function machine(): ServerMachine
    {
        return ServerMachine::create(['name' => 'Machine', 'token' => 'machine-token', 'is_active' => true]);
    }

    private function node(array $attributes = []): Server
    {
        return Server::withoutEvents(fn () => Server::create($attributes + [
            'name' => 'Node', 'type' => 'vless', 'host' => 'node.example.test',
            'port' => '17881', 'server_port' => 17881, 'enabled' => true, 'show' => true,
            'protocol_settings' => ['tls' => 2, 'network' => 'tcp'],
        ]));
    }
}
