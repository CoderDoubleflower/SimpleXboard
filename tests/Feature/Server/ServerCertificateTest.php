<?php

namespace Tests\Feature\Server;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\NodeRegistry;
use App\Services\NodeHealthService;
use App\Services\NodeSyncService;
use App\Services\ServerService;
use App\Services\UserService;
use App\WebSocket\NodeEventHandlers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Workerman\Connection\TcpConnection;

class ServerCertificateTest extends TestCase
{
    use RefreshDatabase;

    private array $messages = [];

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Redis::shouldReceive('publish')->andReturnUsing(function ($channel, $payload) {
            $this->assertSame('node:push', $channel);
            $this->messages[] = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
            return 1;
        });
        admin_setting(['server_token' => 'certificate-test-token', 'show_info_to_server_enable' => 0]);
        $admin = $this->member('Administrator');
        $admin->update(['is_admin' => true]);
        Sanctum::actingAs($admin);
    }

    public static function invalidCertificates(): array
    {
        return [
            'null source' => [null, 'cert_config'],
            'empty object' => [[], 'cert_config.cert_mode'],
            'string source' => ['file', 'cert_config'],
            'list source' => [['file', '/cert.pem', '/key.pem'], 'cert_config'],
            'boolean mode' => [['cert_mode' => false], 'cert_config.cert_mode'],
            'none mode' => [['cert_mode' => 'none'], 'cert_config.cert_mode'],
            'unknown mode' => [['cert_mode' => 'disabled'], 'cert_config.cert_mode'],
            'conflicting mode alias' => [['mode' => 'self', 'cert_mode' => 'file', 'cert_file' => '/cert.pem', 'key_file' => '/key.pem'], 'cert_config.cert_mode'],
            'file missing both paths' => [['cert_mode' => 'file'], 'cert_config.cert_file'],
            'file missing certificate' => [['cert_mode' => 'file', 'key_file' => '/key.pem'], 'cert_config.cert_file'],
            'file missing private key' => [['cert_mode' => 'file', 'cert_file' => '/cert.pem'], 'cert_config.key_file'],
            'blank private key path' => [['cert_mode' => 'file', 'cert_file' => '/cert.pem', 'key_file' => '   '], 'cert_config.key_file'],
            'array path' => [['cert_mode' => 'file', 'cert_file' => ['/cert.pem'], 'key_file' => '/key.pem'], 'cert_config.cert_file'],
            'content missing certificate' => [['cert_mode' => 'content', 'key_content' => 'private-key'], 'cert_config.cert_content'],
            'content missing private key' => [['cert_mode' => 'content', 'cert_content' => 'certificate'], 'cert_config.key_content'],
            'content invalid PEM' => [['cert_mode' => 'content', 'cert_content' => 'not a certificate', 'key_content' => 'not a private key'], 'cert_config.cert_content'],
            'HTTP ACME missing domain' => [['cert_mode' => 'http'], 'cert_config.domain'],
            'HTTP ACME invalid port' => [['cert_mode' => 'http', 'domain' => 'hy.example.com', 'http_port' => 65536], 'cert_config.http_port'],
            'HTTP ACME URL instead of domain' => [['cert_mode' => 'http', 'domain' => 'https://hy.example.com'], 'cert_config.domain'],
            'HTTP ACME wildcard domain' => [['cert_mode' => 'http', 'domain' => '*.example.com'], 'cert_config.domain'],
            'DNS ACME missing domain' => [['cert_mode' => 'dns', 'dns_provider' => 'route53'], 'cert_config.domain'],
            'DNS ACME missing provider' => [['cert_mode' => 'dns', 'domain' => 'hy.example.com'], 'cert_config.dns_provider'],
            'DNS ACME unsupported provider' => [['cert_mode' => 'dns', 'domain' => 'hy.example.com', 'dns_provider' => 'not-supported'], 'cert_config.dns_provider'],
            'DNS ACME malformed credentials' => [['cert_mode' => 'dns', 'domain' => 'hy.example.com', 'dns_provider' => 'route53', 'dns_env' => 'not-an-object'], 'cert_config.dns_env'],
            'DNS ACME credential list' => [['cert_mode' => 'dns', 'domain' => 'hy.example.com', 'dns_provider' => 'route53', 'dns_env' => ['first', 'second']], 'cert_config.dns_env'],
            'DNS ACME missing Cloudflare credential' => [['cert_mode' => 'dns', 'domain' => 'hy.example.com', 'dns_provider' => 'cloudflare'], 'cert_config.dns_env'],
        ];
    }

    #[DataProvider('invalidCertificates')]
    public function test_api_rejects_incomplete_sources_even_with_sni_and_client_insecure(mixed $certificate, string $field): void
    {
        $payload = $this->payload(['cert_config' => $certificate]);
        $payload['protocol_settings']['tls']['allow_insecure'] = true;
        $this->postJson($this->base() . '/save', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('v2_server', 0);
        $this->assertSame([], $this->messages);
    }

    public function test_omitted_source_is_rejected_and_disabled_full_save_is_not_an_implicit_draft(): void
    {
        $payload = $this->payload();
        unset($payload['cert_config']);
        foreach ([true, false] as $enabled) {
            $this->postJson($this->base() . '/save', array_replace($payload, ['enabled' => $enabled]))
                ->assertUnprocessable()->assertJsonValidationErrors('cert_config');
        }
        $this->assertDatabaseCount('v2_server', 0);
    }

    public static function validCertificates(): array
    {
        $file = ['cert_file' => '/etc/xboard-node/certs/full chain.pem', 'key_file' => '/etc/xboard-node/certs/private key.pem'];
        return [
            'remote file paths' => [['cert_mode' => 'file'] + $file, 'file'],
            'legacy file alias' => [['mode' => 'file'] + $file, 'file'],
            'legacy inferred file mode' => [$file, 'file'],
            'self signed' => [['cert_mode' => 'self', 'domain' => 'hy.example.com'], 'self'],
            'self signed localhost default' => [['cert_mode' => 'self'], 'self'],
            'HTTP ACME' => [['cert_mode' => 'http', 'domain' => 'hy.example.com'], 'http'],
            'HTTP ACME default challenge port' => [['cert_mode' => 'http', 'domain' => 'hy.example.com', 'http_port' => 0], 'http'],
            'HTTP ACME custom port and email' => [['cert_mode' => 'http', 'domain' => 'hy.example.com', 'http_port' => 8080, 'email' => 'admin@example.com'], 'http'],
            'DNS ACME environment identity' => [['cert_mode' => 'dns', 'domain' => '*.example.com', 'dns_provider' => 'route53'], 'dns'],
            'DNS ACME empty environment identity' => [['cert_mode' => 'dns', 'domain' => 'hy.example.com', 'dns_provider' => 'route53', 'dns_env' => []], 'dns'],
            'DNS ACME Cloudflare token' => [['cert_mode' => 'dns', 'domain' => 'hy.example.com', 'dns_provider' => 'cloudflare', 'dns_env' => ['CLOUDFLARE_API_TOKEN' => 'test-dns-token']], 'dns'],
            'DNS ACME legacy provider alias' => [['cert_mode' => 'dns', 'domain' => 'hy.example.com', 'dns_provider' => 'CF', 'dns_env' => ['CF_API_TOKEN' => 'test-dns-token']], 'dns'],
        ];
    }

    #[DataProvider('validCertificates')]
    public function test_supported_sources_save_and_downlink_the_effective_mode(array $certificate, string $mode): void
    {
        $this->postJson($this->base() . '/save', $this->payload(['cert_config' => $certificate]))->assertOk();
        $node = Server::firstOrFail();
        $configuration = ServerService::buildNodeConfig($node);
        $this->assertSame($mode, $configuration['cert_config']['cert_mode']);
        $this->assertArrayNotHasKey('mode', $configuration['cert_config']);
        if (array_key_exists('cert_file', $certificate)) {
            // These files belong to the VPS. The panel must not try to read its own filesystem.
            $this->assertSame($certificate['cert_file'], $configuration['cert_config']['cert_file']);
            $this->assertSame($certificate['key_file'], $configuration['cert_config']['key_file']);
        }
        if (($certificate['dns_env'] ?? null) === []) $this->assertArrayNotHasKey('dns_env', $configuration['cert_config']);
        $this->getJson($this->nodeEndpoint($node, 'config'))->assertOk()->assertJsonPath('cert_config.cert_mode', $mode);
    }

    public function test_content_mode_accepts_matching_pem_and_rejects_a_mismatched_private_key(): void
    {
        [$cert, $key] = $this->pemPair();
        [, $otherKey] = $this->pemPair();
        foreach ([['cert_mode' => 'content'], []] as $mode) {
            $this->postJson($this->base() . '/save', $this->payload(['cert_config' => $mode + [
                'cert_content' => $cert, 'key_content' => $key,
            ]]))->assertOk();
            $node = Server::latest('id')->firstOrFail();
            $config = ServerService::buildNodeConfig($node)['cert_config'];
            $this->assertSame('content', $config['cert_mode']);
            $this->assertSame(trim($key), trim($config['key_content']));
        }
        $this->postJson($this->base() . '/save', $this->payload(['cert_config' => [
            'cert_mode' => 'content', 'cert_content' => $cert, 'key_content' => $otherKey,
        ]]))->assertUnprocessable()->assertJsonValidationErrors('cert_config.key_content');
        $this->assertDatabaseCount('v2_server', 2);
    }

    public function test_edit_retains_an_omitted_certificate_but_never_treats_null_as_omitted(): void
    {
        $node = $this->node(['cert_config' => ['mode' => 'file', 'cert_file' => '/cert.pem', 'key_file' => '/key.pem', 'storage_path' => '/custom/cert-cache']]);
        $payload = $this->payload(['id' => $node->id, 'name' => 'Renamed node']);
        unset($payload['cert_config']);
        $this->postJson($this->base() . '/save', $payload)->assertOk();
        $node->refresh();
        $this->assertSame('Renamed node', $node->name);
        $this->assertSame('/cert.pem', $node->cert_config['cert_file']);
        $this->assertSame('/custom/cert-cache', $node->cert_config['storage_path']);
        foreach ([null, [], ['cert_mode' => 'none']] as $invalid) {
            $this->postJson($this->base() . '/save', $payload + ['cert_config' => $invalid])->assertUnprocessable();
            $this->assertSame('/cert.pem', $node->fresh()->cert_config['cert_file']);
        }
    }

    public function test_single_enable_rejects_legacy_invalid_nodes_but_disabling_and_copying_remain_possible(): void
    {
        $legacy = $this->node(['enabled' => false, 'cert_config' => null]);
        $this->postJson($this->base() . '/update', ['id' => $legacy->id, 'enabled' => true])->assertUnprocessable();
        $this->assertFalse($legacy->fresh()->enabled);
        $legacy->forceFill(['enabled' => true])->saveQuietly();
        $this->postJson($this->base() . '/update', ['id' => $legacy->id, 'enabled' => false])->assertOk();
        $this->assertFalse($legacy->fresh()->enabled);
        $this->postJson($this->base() . '/copy', ['id' => $legacy->id])->assertOk();
        $copy = Server::whereKeyNot($legacy->id)->firstOrFail();
        $this->assertFalse($copy->enabled);
        $this->postJson($this->base() . '/update', ['id' => $copy->id, 'enabled' => true])->assertUnprocessable();
    }

    public function test_batch_enable_is_atomic_when_any_node_lacks_a_certificate(): void
    {
        $valid = $this->node(['enabled' => false]);
        $invalid = $this->node(['enabled' => false, 'cert_config' => null]);
        $ids = [$valid->id, $invalid->id];
        $this->postJson($this->base() . '/batchUpdate', ['ids' => $ids, 'enabled' => true])->assertUnprocessable();
        $this->assertFalse($valid->fresh()->enabled);
        $this->assertFalse($invalid->fresh()->enabled);
        $this->postJson($this->base() . '/batchUpdate', ['ids' => $ids, 'enabled' => false])->assertOk();
        $this->assertSame([], $this->messages);
    }

    public function test_machine_reassignment_cannot_bypass_validation_for_already_enabled_legacy_nodes(): void
    {
        $machine = ServerMachine::create(['name' => 'Machine', 'token' => 'machine-token', 'is_active' => true]);
        $invalid = $this->node(['cert_config' => null]);
        $this->postJson($this->base() . '/update', ['id' => $invalid->id, 'machine_id' => $machine->id])->assertUnprocessable();
        $this->assertNull($invalid->fresh()->machine_id);
        $this->postJson($this->base() . '/batchUpdate', ['ids' => [$invalid->id], 'machine_id' => $machine->id])->assertUnprocessable();
        $this->assertNull($invalid->fresh()->machine_id);
    }

    public function test_legacy_invalid_configuration_is_blocked_at_build_and_rest_boundaries(): void
    {
        $node = $this->node(['cert_config' => null]);
        try {
            ServerService::buildNodeConfig($node);
            $this->fail('A missing Hysteria certificate must never reach a node configuration.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cert_config', $exception->errors());
        }
        $this->getJson($this->nodeEndpoint($node, 'config'))->assertUnprocessable()->assertJsonValidationErrors('cert_config');
        $this->getJson('/api/v1/server/UniProxy/config?' . http_build_query([
            'node_id' => $node->id, 'node_type' => 'hysteria', 'token' => 'certificate-test-token',
        ]))->assertUnprocessable();
        $this->assertSame('error', $node->core_status['state']);
        $this->assertSame('panel_validation', $node->core_status['source']);
        $this->assertFalse($node->core_ready);
        $this->getJson($this->base() . '/getNodes')->assertOk()
            ->assertJsonPath('data.0.core_status.source', 'panel_validation')->assertJsonPath('data.0.core_ready', false);
    }

    public function test_machine_listing_users_and_subscriptions_exclude_invalid_legacy_nodes(): void
    {
        $machine = ServerMachine::create(['name' => 'Machine', 'token' => 'machine-token', 'is_active' => true]);
        $invalid = $this->node(['machine_id' => $machine->id, 'cert_config' => null]);
        $valid = $this->node(['machine_id' => $machine->id]);
        $user = $this->member();
        $this->assertSame([$valid->id], ServerService::getMachineNodes($machine)->pluck('id')->all());
        $this->postJson('/api/v2/server/machine/nodes', ['machine_id' => $machine->id, 'token' => 'machine-token'])
            ->assertOk()->assertJsonCount(1, 'nodes')->assertJsonPath('nodes.0.id', $valid->id);
        $this->assertSame([], ServerService::getAvailableUsers($invalid)->all());
        $this->getJson($this->nodeEndpoint($invalid, 'user'))->assertOk()->assertJsonCount(0, 'users');
        $this->assertSame([$valid->id], array_column(ServerService::getAvailableServers($user), 'id'));
        $this->assertSame(1, ServerService::getAvailableUsers($valid)->count());
    }

    public function test_full_and_incremental_sync_revoke_users_without_sending_invalid_configuration(): void
    {
        $node = $this->node(['cert_config' => null]);
        $user = $this->member();
        Cache::put('node_ws_alive:' . $node->id, true, 120);
        foreach ([fn () => NodeSyncService::notifyConfigUpdated($node->id), fn () => NodeSyncService::notifyFullSync($node->id)] as $sync) {
            $this->messages = [];
            $sync();
            $this->assertNotContains('sync.config', array_column($this->messages, 'event'));
            $users = collect($this->messages)->firstWhere('event', 'sync.users');
            $this->assertNotNull($users, 'Blocked configuration must revoke previously applied users.');
            $this->assertSame([], $users['data']['users']);
        }
        $this->messages = [];
        NodeSyncService::notifyUserChanged($user);
        $this->assertCount(1, $this->messages);
        $this->assertSame('sync.user.delta', $this->messages[0]['event']);
        $this->assertSame('remove', $this->messages[0]['data']['action']);
        $this->assertSame($user->id, $this->messages[0]['data']['users'][0]['id']);
    }

    public function test_websocket_full_sync_keeps_invalid_and_valid_nodes_isolated(): void
    {
        $invalid = $this->node(['cert_config' => null]);
        $valid = $this->node();
        $this->member();
        $messages = [];
        $connection = Mockery::mock(TcpConnection::class);
        $connection->machineNodeIds = [$invalid->id, $valid->id];
        $connection->shouldReceive('send')->andReturnUsing(function ($payload) use (&$messages) {
            $messages[] = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
            return true;
        });
        NodeRegistry::add($invalid->id, $connection);
        NodeRegistry::add($valid->id, $connection);
        try {
            NodeEventHandlers::pushFullSync($connection, $invalid);
            NodeEventHandlers::pushFullSync($connection, $valid);
        } finally {
            NodeRegistry::remove($invalid->id, $connection);
            NodeRegistry::remove($valid->id, $connection);
        }
        $blocked = collect($messages)->where('data.node_id', $invalid->id);
        $this->assertNotContains('sync.config', $blocked->pluck('event')->all());
        $this->assertSame([], $blocked->firstWhere('event', 'sync.users')['data']['users']);
        $this->assertNotNull($blocked->firstWhere('event', 'error'));
        $working = collect($messages)->where('data.node_id', $valid->id);
        $this->assertSame('self', $working->firstWhere('event', 'sync.config')['data']['config']['cert_config']['cert_mode']);
        $this->assertCount(1, $working->firstWhere('event', 'sync.users')['data']['users']);
    }

    public function test_certificate_credentials_are_available_to_nodes_but_absent_from_subscription_data(): void
    {
        $user = $this->member();
        $node = $this->node(['cert_config' => [
            'cert_mode' => 'dns', 'domain' => 'hy.example.com', 'dns_provider' => 'cloudflare',
            'dns_env' => ['CLOUDFLARE_API_TOKEN' => 'must-not-reach-clients'],
        ]]);
        $this->assertSame('must-not-reach-clients', ServerService::buildNodeConfig($node)['cert_config']['dns_env']['CLOUDFLARE_API_TOKEN']);
        $subscriptionNodes = ServerService::getAvailableServers($user);
        $this->assertCount(1, $subscriptionNodes);
        $this->assertArrayNotHasKey('cert_config', $subscriptionNodes[0]);
        $this->assertStringNotContainsString('must-not-reach-clients', json_encode($subscriptionNodes));
        $this->get('/s/' . $user->token . '?flag=sing-box')->assertOk()->assertDontSee('must-not-reach-clients', false);
    }

    public function test_certificate_credentials_are_redacted_from_the_administrator_audit_log(): void
    {
        $certificate = ['cert_mode' => 'dns', 'domain' => 'hy.example.com', 'dns_provider' => 'cloudflare',
            'dns_env' => ['CLOUDFLARE_API_TOKEN' => 'must-not-enter-audit']];
        $this->postJson($this->base() . '/save', $this->payload(['cert_config' => $certificate]))->assertOk();
        $audit = DB::table('v2_admin_audit_log')->where('action', 'server_manage.save')->first();
        $this->assertNotNull($audit);
        $this->assertSame('[redacted]', json_decode($audit->request_data, true)['cert_config']);
        $this->assertStringNotContainsString('must-not-enter-audit', $audit->request_data);
    }

    public function test_unbound_subscription_alias_uses_its_parent_certificate_health_without_exposing_the_parent(): void
    {
        $member = $this->member();
        $parent = $this->node(['show' => false, 'cert_config' => [
            'cert_mode' => 'dns', 'domain' => 'hy.example.com', 'dns_provider' => 'cloudflare',
            'dns_env' => ['CLOUDFLARE_API_TOKEN' => 'parent-certificate-credential'],
        ]]);
        $alias = $this->node(['parent_id' => $parent->id, 'cert_config' => null]);
        NodeHealthService::report($parent, ['core_status' => [
            'state' => 'running', 'ready' => true, 'users' => 1, 'applied_users' => 1,
        ]]);
        $this->assertTrue($alias->core_ready);
        $this->assertSame($parent->core_status, $alias->core_status);
        $subscription = ServerService::getAvailableServers($member);
        $this->assertSame([$alias->id], array_column($subscription, 'id'));
        $this->assertArrayNotHasKey('parent', $subscription[0]);
        $this->assertArrayNotHasKey('cert_config', $subscription[0]);
        $this->assertStringNotContainsString('parent-certificate-credential', json_encode($subscription));

        $parent->forceFill(['cert_config' => null])->saveQuietly();
        $this->assertFalse($alias->fresh()->core_ready);
        $this->assertSame('panel_validation', $alias->fresh()->core_status['source']);
        $this->assertSame([], ServerService::getAvailableServers($member));
    }

    public function test_independently_bound_alias_must_have_its_own_certificate_source(): void
    {
        $member = $this->member();
        $parent = $this->node(['show' => false]);
        $machine = ServerMachine::create(['name' => 'Independent machine', 'token' => 'independent-token', 'is_active' => true]);
        $alias = $this->node(['parent_id' => $parent->id, 'machine_id' => $machine->id, 'cert_config' => null]);
        NodeHealthService::report($parent, ['core_status' => [
            'state' => 'running', 'ready' => true, 'users' => 1, 'applied_users' => 1,
        ]]);
        $this->assertFalse($alias->core_ready);
        $this->assertSame('panel_validation', $alias->core_status['source']);
        $this->assertSame([], ServerService::getAvailableServers($member));
        $this->assertTrue(ServerService::getMachineNodes($machine)->isEmpty());
        $this->assertTrue(ServerService::getAvailableUsers($alias)->isEmpty());
        $this->getJson($this->nodeEndpoint($alias, 'config'))->assertUnprocessable();
        $this->postJson($this->base() . '/save', $this->payload(['id' => $alias->id, 'parent_id' => $parent->id,
            'machine_id' => $machine->id, 'cert_config' => null]))->assertUnprocessable();
    }

    public function test_vless_reality_remains_usable_without_a_server_certificate(): void
    {
        $payload = $this->payload([
            'type' => 'vless', 'cert_config' => null,
            'protocol_settings' => ['tls' => 2, 'network' => 'tcp', 'flow' => 'xtls-rprx-vision', 'reality_settings' => [
                'server_name' => 'www.example.com', 'server_port' => 443, 'private_key' => 'test-private-key',
                'public_key' => 'test-public-key', 'short_id' => 'abcdef01',
            ]],
        ]);
        $this->postJson($this->base() . '/save', $payload)->assertOk();
        $node = Server::firstOrFail();
        $this->getJson($this->nodeEndpoint($node, 'config'))->assertOk()->assertJsonPath('tls', 2)->assertJsonMissingPath('cert_config');
        $this->assertSame('unknown', $node->core_status['state']);
    }

    private function payload(array $changes = []): array
    {
        return array_replace([
            'name' => 'Hysteria2 node', 'type' => 'hysteria', 'host' => 'hy.example.com',
            'port' => '443', 'server_port' => 8443, 'enabled' => true, 'show' => true,
            'cert_config' => ['cert_mode' => 'self', 'domain' => 'hy.example.com'],
            'protocol_settings' => [
                'version' => 2, 'tls' => ['server_name' => 'hy.example.com', 'allow_insecure' => false],
                'bandwidth' => ['up' => 100, 'down' => 100], 'obfs' => ['open' => false],
            ],
        ], $changes);
    }

    private function node(array $changes = []): Server
    {
        return Server::withoutEvents(fn () => Server::create($this->payload($changes)));
    }

    private function member(string $name = 'Member'): User
    {
        $user = app(UserService::class)->createUser(['name' => $name]);
        $user->save();
        return $user;
    }

    private function base(): string
    {
        return '/api/v2/' . admin_setting('secure_path', hash('crc32b', config('app.key'))) . '/server/manage';
    }

    private function nodeEndpoint(Server $node, string $method): string
    {
        return '/api/v2/server/' . $method . '?' . http_build_query(['node_id' => $node->id, 'token' => 'certificate-test-token']);
    }

    private function pemPair(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->assertNotFalse($key);
        $request = openssl_csr_new(['commonName' => 'hy.example.com'], $key, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($request);
        $certificate = openssl_csr_sign($request, null, $key, 1, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($certificate);
        $this->assertTrue(openssl_x509_export($certificate, $certificatePem));
        $this->assertTrue(openssl_pkey_export($key, $keyPem));
        return [$certificatePem, $keyPem];
    }
}
