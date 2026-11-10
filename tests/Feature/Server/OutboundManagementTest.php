<?php

namespace Tests\Feature\Server;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\ServerOutbound;
use App\Models\User;
use App\Services\OutboundCapabilityService;
use App\Services\ServerOutboundService;
use App\Services\ServerService;
use App\Services\UserService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OutboundManagementTest extends TestCase
{
    use RefreshDatabase;

    private const UUID = 'c6e27b9a-4ce5-414d-814b-713b30caa7fe';
    private const PUBLIC_KEY = 'YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE';

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Redis::shouldReceive('sadd')->byDefault()->andReturn(1);
        Redis::shouldReceive('publish')->byDefault()->andReturn(1);
        admin_setting(['server_token' => 'outbound-test-token', 'show_info_to_server_enable' => 0]);
    }

    public function test_outbound_endpoints_are_admin_only_and_import_does_not_persist(): void
    {
        $base = $this->base();
        foreach (['fetch', 'get?id=1'] as $path) $this->getJson($base . '/' . $path)->assertForbidden();
        foreach (['import', 'save', 'drop'] as $path) $this->postJson($base . '/' . $path, [])->assertForbidden();
        Sanctum::actingAs($this->member());
        $this->getJson($base . '/fetch')->assertForbidden();
        $this->postJson($base . '/import', ['uri' => $this->vlessUri()])->assertForbidden();

        $this->admin();
        $response = $this->postJson($base . '/import', ['uri' => $this->vlessUri()])->assertOk();
        $response->assertJsonPath('data.name', '日本落地')
            ->assertJsonPath('data.type', 'vless')
            ->assertJsonPath('data.settings.uuid', self::UUID)
            ->assertJsonPath('data.settings.tls.reality.public_key', self::PUBLIC_KEY)
            ->assertJsonPath('data.settings.tls.utls.fingerprint', 'chrome');
        $this->assertDatabaseCount('v2_server_outbound', 0);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $audit = DB::table('v2_admin_audit_log')->where('action', 'server_outbound.import')->first();
        $this->assertSame('[redacted]', json_decode($audit->request_data, true)['uri']);
        $this->assertStringNotContainsString(self::UUID, $audit->request_data);
    }

    public function test_credentials_are_encrypted_and_only_the_edit_endpoint_returns_settings(): void
    {
        $this->admin();
        $payload = $this->outboundPayload();
        $id = $this->postJson($this->base() . '/save', $payload)->assertOk()->json('data.id');
        $outbound = ServerOutbound::findOrFail($id);
        $stored = DB::table('v2_server_outbound')->where('id', $id)->value('settings');
        $this->assertStringNotContainsString(self::UUID, $stored);
        $this->assertStringNotContainsString('exit.example.test', $stored);
        $this->assertStringNotContainsString(self::UUID, json_encode($outbound));
        $this->assertSame(self::UUID, $outbound->settings['uuid']);

        $visible = $this->node(['outbound_id' => $id]);
        $hidden = $this->node(['name' => 'Hidden ingress', 'show' => false, 'outbound_id' => $id]);
        $list = $this->getJson($this->base() . '/fetch')->assertOk();
        $list->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.server', 'exit.example.test')
            ->assertJsonPath('data.0.usage_count', 2)->assertJsonCount(2, 'data.0.used_by');
        $this->assertEqualsCanonicalizing([$visible->id, $hidden->id], array_column($list->json('data.0.used_by'), 'id'));
        $this->assertStringNotContainsString(self::UUID, $list->getContent());
        $this->assertStringNotContainsString('public_key', $list->getContent());
        $this->assertArrayNotHasKey('settings', $list->json('data.0'));

        $edit = $this->getJson($this->base() . '/get?id=' . $id)->assertOk()
            ->assertJsonPath('data.settings.uuid', self::UUID);
        $this->assertStringContainsString('no-store', $edit->headers->get('Cache-Control'));
        $audit = DB::table('v2_admin_audit_log')->where('action', 'server_outbound.save')->first();
        $this->assertSame('[redacted]', json_decode($audit->request_data, true)['settings']);
        $this->assertStringNotContainsString(self::UUID, $audit->request_data);
    }

    public function test_hysteria_import_preserves_encoded_password_ipv6_and_optional_settings(): void
    {
        $this->admin();
        $uri = 'hy2://user%3Apa%2Bss%40word@[2001:db8::2]/?sni=hy.example.test&insecure=0&obfs=salamander&obfs-password=secret%2Bvalue&upmbps=100&downmbps=250#HY2';
        $imported = $this->postJson($this->base() . '/import', ['uri' => $uri])->assertOk()
            ->assertJsonPath('data.type', 'hysteria2')
            ->assertJsonPath('data.settings.server', '2001:db8::2')
            ->assertJsonPath('data.settings.server_port', 443)
            ->assertJsonPath('data.settings.password', 'user:pa+ss@word')
            ->assertJsonPath('data.settings.obfs.password', 'secret+value')
            ->assertJsonPath('data.settings.up_mbps', 100)
            ->assertJsonPath('data.settings.down_mbps', 250)->json('data');
        $imported['settings']['password'] = ' password-with-spaces ';
        $id = $this->postJson($this->base() . '/save', $imported)->assertOk()->json('data.id');
        $this->assertSame(' password-with-spaces ', ServerOutbound::findOrFail($id)->settings['password']);
    }

    public function test_link_import_rejects_ambiguous_unsupported_or_incomplete_links(): void
    {
        $this->admin();
        $base = 'vless://' . self::UUID . '@exit.example.test:443?';
        $invalid = [
            'https://example.test/subscription',
            $base . 'security=tls&type=ws&path=%2F',
            $base . 'security=tls&type=grpc',
            $base . 'security=none&type=tcp',
            $base . 'security=tls&security=reality',
            $base . 'security=tls&encryption=unsupported',
            $base . 'security=tls&headerType=http',
            $base . 'security=tls&spx=%2F',
            $base . 'security=tls&fp=unknown-fingerprint',
            $base . 'security=tls&pbk=' . self::PUBLIC_KEY,
            $base . 'security=tls&sni=a.example&sni=b.example',
            $base . 'security=tls&sni=a.example&serverName=b.example',
            $base . 'security=tls&insecure=maybe',
            $base . 'security=tls&insecure=0&allowInsecure=1',
            $base . 'security=tls&alpn=%00',
            $base . 'security=tls#bad%0Aname',
            $base . 'security=tls#bad%ZZ',
            'vless://not-a-uuid@exit.example.test:443?security=tls',
            'vless://' . self::UUID . '@exit.example.test?security=tls',
            'vless://' . self::UUID . '@exit.example.test:70000?security=tls',
            $base . 'security=reality&sni=www.example.test&pbk=invalid',
            $base . 'security=reality&pbk=' . self::PUBLIC_KEY,
            $base . 'security=reality&sni=www.example.test&pbk=' . self::PUBLIC_KEY . '&sid=abc',
            'hysteria2://pass@exit.example.test:443?mport=443,8443',
            'hysteria2://pass@exit.example.test:443?obfs=salamander',
            'hysteria2://pass@exit.example.test:443?obfs-password=secret',
            $base . "security=tls\n" . $this->vlessUri(),
        ];
        foreach ($invalid as $uri) {
            $this->postJson($this->base() . '/import', ['uri' => $uri])->assertUnprocessable();
        }
        $this->assertDatabaseCount('v2_server_outbound', 0);
    }

    public function test_socks5_import_supports_unauthenticated_plain_and_base64_credentials(): void
    {
        $this->admin();
        $variants = [
            ['socks5://127.0.0.1:1080#SOCKS5', null, null],
            ['socks://us%40er:p%3Aa%2Bss@[2001:db8::1]:1080#SOCKS5', 'us@er', 'p:a+ss'],
            ['socks5://' . rtrim(strtr(base64_encode('user:pass:word'), '+/', '-_'), '=') . '@exit.example.test:1080', 'user', 'pass:word'],
        ];
        foreach ($variants as [$uri, $username, $password]) {
            $imported = $this->postJson($this->base() . '/import', ['uri' => $uri])->assertOk()
                ->assertJsonPath('data.type', 'socks')->assertJsonPath('data.settings.version', '5')->json('data');
            $this->assertSame($username, $imported['settings']['username'] ?? null);
            $this->assertSame($password, $imported['settings']['password'] ?? null);
            $this->assertArrayNotHasKey('tls', $imported['settings']);
            $id = $this->postJson($this->base() . '/save', $imported)->assertOk()->json('data.id');
            $node = $this->node(['outbound_id' => $id]);
            $core = ServerService::buildNodeConfig($node)['custom_outbounds'][0];
            $this->assertSame('socks', $core['protocol']);
            $this->assertSame($password, $core['settings']['password'] ?? null);
            $this->assertSame(1080, $core['settings']['server_port']);
        }
    }

    public function test_shadowsocks_import_supports_sip002_and_legacy_forms_and_validates_2022_keys(): void
    {
        $this->admin();
        $uris = [
            'ss://aes-128-gcm:pass%3Aword@exit.example.test:8388#SS',
            'ss://' . rtrim(strtr(base64_encode('aes-128-gcm:pass:word'), '+/', '-_'), '=') . '@exit.example.test:8388/#SS',
            'ss://' . rtrim(base64_encode('aes-128-gcm:pass:word@exit.example.test:8388'), '=') . '#SS',
        ];
        foreach ($uris as $uri) {
            $imported = $this->postJson($this->base() . '/import', ['uri' => $uri])->assertOk()
                ->assertJsonPath('data.type', 'shadowsocks')->assertJsonPath('data.settings.method', 'aes-128-gcm')
                ->assertJsonPath('data.settings.password', 'pass:word')->json('data');
            $id = $this->postJson($this->base() . '/save', $imported)->assertOk()->json('data.id');
            $this->assertSame('shadowsocks', ServerService::buildNodeConfig($this->node(['outbound_id' => $id]))['custom_outbounds'][0]['protocol']);
        }
        $special = 'pass/?#@%+word';
        $this->postJson($this->base() . '/import', ['uri' => 'ss://' . base64_encode('aes-256-gcm:' . $special . '@exit.example.test:8388') . '#Legacy'])
            ->assertOk()->assertJsonPath('data.settings.password', $special);
        foreach (ServerOutboundService::SHADOWSOCKS_METHODS as $method) {
            $password = str_starts_with($method, '2022-') ? base64_encode(str_repeat('a', $method === '2022-blake3-aes-128-gcm' ? 16 : 32)) : 'secret';
            $payload = ['name' => 'SS cipher', 'type' => 'shadowsocks', 'settings' => [
                'server' => 'exit.example.test', 'server_port' => 8388, 'method' => $method, 'password' => $password,
            ]];
            $this->postJson($this->base() . '/save', $payload)->assertOk();
            if (str_starts_with($method, '2022-')) {
                $payload['settings']['password'] = 'not-a-valid-key';
                $this->postJson($this->base() . '/save', $payload)->assertUnprocessable()->assertJsonValidationErrors('settings.password');
                $payload['settings']['password'] = $password . ':' . $password;
                $response = $this->postJson($this->base() . '/save', $payload);
                if ($method === '2022-blake3-chacha20-poly1305') $response->assertUnprocessable();
                else $response->assertOk();
            }
        }
    }

    public function test_trojan_and_vmess_import_preserve_tcp_tls_security_and_legacy_alter_id(): void
    {
        $this->admin();
        $trojan = $this->postJson($this->base() . '/import', [
            'uri' => 'trojan://p%3Aa%2Bss@exit.example.test:443?security=tls&type=tcp&peer=tls.example.test&alpn=h2,http%2F1.1&fp=firefox#Trojan',
        ])->assertOk()->assertJsonPath('data.type', 'trojan')->assertJsonPath('data.settings.password', 'p:a+ss')
            ->assertJsonPath('data.settings.tls.server_name', 'tls.example.test')->json('data');
        $id = $this->postJson($this->base() . '/save', $trojan)->assertOk()->json('data.id');
        $this->assertSame('firefox', ServerService::buildNodeConfig($this->node(['outbound_id' => $id]))['custom_outbounds'][0]['settings']['tls']['utls']['fingerprint']);

        $json = ['v' => '2', 'ps' => 'VMess TLS', 'add' => 'exit.example.test', 'port' => '443', 'id' => self::UUID,
            'aid' => '16', 'scy' => 'aes-128-gcm', 'net' => 'tcp', 'type' => 'none', 'host' => '', 'path' => '',
            'tls' => 'tls', 'sni' => 'tls.example.test', 'alpn' => 'h2,http/1.1', 'fp' => 'chrome', 'allowInsecure' => false];
        $imported = $this->postJson($this->base() . '/import', ['uri' => 'vmess://' . base64_encode(json_encode($json))])->assertOk()
            ->assertJsonPath('data.type', 'vmess')->assertJsonPath('data.settings.security', 'aes-128-gcm')
            ->assertJsonPath('data.settings.alter_id', 16)->assertJsonPath('data.settings.tls.alpn', ['h2', 'http/1.1'])->json('data');
        $id = $this->postJson($this->base() . '/save', $imported)->assertOk()->json('data.id');
        $core = ServerService::buildNodeConfig($this->node(['outbound_id' => $id]))['custom_outbounds'][0];
        $this->assertSame('vmess', $core['protocol']);
        $this->assertSame(16, $core['settings']['alter_id']);
        $this->assertSame('aes-128-gcm', $core['settings']['security']);

        $json['tls'] = '';
        foreach (['sni', 'alpn', 'fp'] as $key) $json[$key] = '';
        $json['aid'] = '0';
        $noTls = $this->postJson($this->base() . '/import', ['uri' => 'vmess://' . rtrim(strtr(base64_encode(json_encode($json)), '+/', '-_'), '=')])
            ->assertOk()->assertJsonPath('data.settings.alter_id', 0)->json('data');
        $this->assertArrayNotHasKey('tls', $noTls['settings']);
        foreach (['auto', 'none', 'zero', 'aes-128-gcm', 'chacha20-poly1305'] as $security) {
            $noTls['settings']['security'] = $security;
            $this->postJson($this->base() . '/save', $noTls)->assertOk();
        }
    }

    public function test_extra_protocol_import_and_settings_fail_explicitly_on_unsupported_options(): void
    {
        $this->admin();
        foreach ([
            'socks4://exit.example.test:1080',
            'socks5://user@exit.example.test:1080',
            'socks5://user:pass@exit.example.test',
            'socks5://user:pass@exit.example.test:1080?udp=1',
            'ss://aes-128-gcm:pass@exit.example.test:8388?plugin=v2ray-plugin',
            'ss://unsupported:pass@exit.example.test:8388',
            'ss://invalid-base64',
            'trojan://pass@exit.example.test:443?type=ws',
            'trojan://pass@exit.example.test:443?security=none',
            'vmess://not-base64-json',
            'vmess://' . base64_encode('[]'),
        ] as $uri) $this->postJson($this->base() . '/import', ['uri' => $uri])->assertUnprocessable();
        $vmess = ['v' => '2', 'ps' => 'VMess', 'add' => 'exit.example.test', 'port' => 443, 'id' => self::UUID, 'net' => 'tcp', 'tls' => ''];
        foreach ([['net' => 'ws'], ['type' => 'http'], ['path' => '/ws'], ['tls' => 'reality'], ['sni' => 'ignored.example.test'], ['detour' => 'direct'], ['add' => ['invalid']]] as $invalid) {
            $uri = 'vmess://' . base64_encode(json_encode(array_replace($vmess, $invalid)));
            $this->postJson($this->base() . '/import', ['uri' => $uri])->assertUnprocessable();
        }
        $base = ['name' => 'SOCKS', 'type' => 'socks', 'settings' => ['server' => 'exit.example.test', 'server_port' => 1080]];
        foreach ([['version' => '4'], ['username' => 'only-user'], ['password' => 'only-password'], ['username' => str_repeat('中', 86), 'password' => 'pass'], ['tls' => ['enabled' => true]]] as $invalid) {
            $payload = $base;
            $payload['settings'] += $invalid;
            $this->postJson($this->base() . '/save', $payload)->assertUnprocessable();
        }
        $this->assertDatabaseCount('v2_server_outbound', 0);
    }

    public function test_settings_reject_unknown_core_options_and_invalid_nested_shapes(): void
    {
        $this->admin();
        $original = $this->outboundPayload();
        $mutations = [
            fn ($s) => $s + ['detour' => 'direct'],
            fn ($s) => $s + ['tag' => 'direct'],
            fn ($s) => $s + ['network' => 'tcp'],
            fn ($s) => $s + ['transport' => ['type' => 'ws']],
            fn ($s) => array_replace($s, ['server' => 'https://exit.example.test']),
            fn ($s) => array_replace($s, ['server_port' => 0]),
            fn ($s) => array_replace($s, ['uuid' => 'invalid']),
            fn ($s) => array_replace($s, ['tls' => 'invalid']),
            fn ($s) => array_replace_recursive($s, ['tls' => ['enabled' => false]]),
            fn ($s) => array_replace_recursive($s, ['tls' => ['reality' => ['private_key' => 'never-accept-this']]]),
            fn ($s) => array_replace_recursive($s, ['tls' => ['insecure' => true]]),
            fn ($s) => array_replace_recursive($s, ['tls' => ['certificate_path' => '/etc/secret']]),
            fn ($s) => array_replace($s, ['tls' => ['enabled' => true, 'utls' => []]]),
            fn ($s) => array_replace($s, ['tls' => ['enabled' => true, 'reality' => []]]),
        ];
        foreach ($mutations as $mutate) {
            $this->postJson($this->base() . '/save', array_replace($original, ['settings' => $mutate($original['settings'])]))->assertUnprocessable();
        }
        $this->postJson($this->base() . '/save', ['name' => 'HY2', 'type' => 'hysteria2', 'settings' => [
            'server' => 'exit.example.test', 'server_port' => 443, 'password' => 'secret', 'tls' => ['enabled' => true], 'obfs' => [],
        ]])->assertUnprocessable();
        $this->assertDatabaseCount('v2_server_outbound', 0);
    }

    public function test_binding_compiles_a_default_outbound_and_detaching_restores_direct_config(): void
    {
        $this->admin();
        $outbound = $this->outbound();
        $node = $this->node();
        OutboundCapabilityService::remember($node, null, true);
        $payload = $this->nodePayload($node);
        $manage = $this->adminPath() . '/server/manage';
        $this->postJson($manage . '/save', $payload + ['outbound_id' => $outbound->id])->assertOk();
        $node->refresh();
        $this->assertSame($outbound->id, $node->outbound_id);
        $config = ServerService::buildNodeConfig($node);
        $this->assertSame($outbound->tag(), $config['default_outbound']);
        $this->assertSame($outbound->tag(), $config['custom_outbounds'][0]['tag']);
        $this->assertSame('vless', $config['custom_outbounds'][0]['protocol']);
        $this->assertSame(self::UUID, $config['custom_outbounds'][0]['settings']['uuid']);
        $this->assertArrayNotHasKey('network', $config['custom_outbounds'][0]['settings']);
        $this->assertArrayNotHasKey('custom_routes', $config);
        $this->getJson($manage . '/getNodes')->assertOk()->assertJsonPath('data.0.outbound_id', $outbound->id)
            ->assertJsonPath('data.0.managed_outbound_supported', true);

        $this->postJson($manage . '/save', $payload)->assertOk();
        $this->assertSame($outbound->id, $node->fresh()->outbound_id); // An omitted field is not an explicit detach.
        $this->postJson($manage . '/save', $payload + ['outbound_id' => null])->assertOk();
        $direct = ServerService::buildNodeConfig($node->fresh());
        $this->assertArrayNotHasKey('default_outbound', $direct);
        $this->assertArrayNotHasKey('custom_outbounds', $direct);
    }

    public function test_managed_binding_rejects_new_and_retained_legacy_routing_without_erasing_it(): void
    {
        $this->admin();
        $outbound = $this->outbound();
        $node = $this->node();
        OutboundCapabilityService::remember($node, null, true);
        $manage = $this->adminPath() . '/server/manage/save';
        $payload = $this->nodePayload($node);
        foreach (['custom_outbounds' => [['type' => 'direct', 'tag' => 'legacy']], 'custom_routes' => [['outbound' => 'legacy']], 'route_ids' => [123]] as $key => $value) {
            $this->postJson($manage, $payload + ['outbound_id' => $outbound->id, $key => $value])->assertUnprocessable()
                ->assertJsonValidationErrors('outbound_id');
            $node->update([$key => $value]);
            $this->postJson($manage, $payload + ['outbound_id' => $outbound->id])->assertUnprocessable();
            $this->assertSame($value, $node->fresh()->{$key});
            $node->update([$key => null]);
        }
        $node->update(['custom_outbounds' => [['type' => 'direct', 'tag' => 'legacy']]]);
        $this->assertSame([['type' => 'direct', 'tag' => 'legacy']], ServerService::buildNodeConfig($node->fresh())['custom_outbounds']);
        $this->postJson($manage, $payload + ['outbound_id' => $outbound->id, 'custom_outbounds' => []])->assertOk();
        $this->postJson($manage, $payload + ['custom_routes' => [['outbound' => 'direct']]])->assertUnprocessable();
        $this->postJson($manage, $payload + ['outbound_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors('outbound_id');
    }

    public function test_deleting_an_outbound_in_use_is_rejected_without_direct_fallback(): void
    {
        $this->admin();
        $outbound = $this->outbound();
        $node = $this->node(['show' => false, 'outbound_id' => $outbound->id]);
        $this->postJson($this->base() . '/drop', ['id' => $outbound->id])->assertUnprocessable()->assertJsonValidationErrors('id');
        $this->assertSame($outbound->id, $node->fresh()->outbound_id);
        $this->assertSame($outbound->tag(), ServerService::buildNodeConfig($node->fresh())['default_outbound']);
        try {
            DB::table('v2_server_outbound')->where('id', $outbound->id)->delete();
            $this->fail('The database must also restrict deletion of referenced outbounds.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('FOREIGN KEY', strtoupper($e->getMessage()));
        }
        $node->update(['outbound_id' => null]);
        $this->postJson($this->base() . '/drop', ['id' => $outbound->id])->assertOk();
        $this->assertDatabaseMissing('v2_server_outbound', ['id' => $outbound->id]);
    }

    public function test_quick_and_batch_machine_changes_cannot_bypass_capability_validation(): void
    {
        $this->admin();
        $currentMachine = ServerMachine::create(['name' => 'Capable', 'token' => 'capable-token', 'is_active' => true]);
        $unsupported = ServerMachine::create(['name' => 'Old agent', 'token' => 'old-token', 'is_active' => true]);
        $node = $this->node(['machine_id' => $currentMachine->id, 'outbound_id' => $this->outbound()->id]);
        OutboundCapabilityService::remember($node, $currentMachine, true);
        $path = $this->adminPath() . '/server/manage';
        $this->postJson($path . '/update', ['id' => $node->id, 'machine_id' => $unsupported->id])
            ->assertUnprocessable()->assertJsonValidationErrors('outbound_id');
        $this->postJson($path . '/batchUpdate', ['ids' => [$node->id], 'machine_id' => $unsupported->id])
            ->assertUnprocessable()->assertJsonValidationErrors('outbound_id');
        $this->assertSame($currentMachine->id, $node->fresh()->machine_id);
        OutboundCapabilityService::remember(null, $unsupported, true);
        $this->postJson($path . '/update', ['id' => $node->id, 'machine_id' => $unsupported->id])->assertOk();
        $this->assertSame($unsupported->id, $node->fresh()->machine_id);
    }

    public function test_http_config_etag_tracks_shared_outbound_changes_and_subscription_excludes_its_credentials(): void
    {
        $this->admin();
        $outbound = $this->outbound();
        $node = $this->node(['outbound_id' => $outbound->id]);
        $query = '/api/v2/server/config?token=outbound-test-token&node_id=' . $node->id;
        $headers = ['X-SimpleXboard-Features' => 'managed-outbound-v1'];
        $initial = $this->getJson($query, $headers)->assertOk()->assertJsonPath('default_outbound', $outbound->tag());
        $tag = $initial->headers->get('ETag');
        $this->getJson($query, $headers + ['If-None-Match' => $tag])->assertStatus(304);
        $updated = $outbound->settings;
        $updated['server'] = 'changed-exit.example.test';
        $outbound->update(['settings' => $updated]);
        $next = $this->getJson($query, $headers + ['If-None-Match' => $tag])->assertOk()
            ->assertJsonPath('custom_outbounds.0.settings.server', 'changed-exit.example.test');
        $this->assertNotSame($tag, $next->headers->get('ETag'));

        $member = $this->member();
        $visible = ServerService::getAvailableServers($member);
        $this->assertStringNotContainsString(self::UUID, json_encode($visible));
        $this->assertArrayNotHasKey('custom_outbounds', $visible[0]);
        foreach (['', 'mihomo', 'clash.meta', 'stash'] as $format) {
            $response = $this->get('/s/' . $member->token . ($format ? '?flag=' . $format : ''))->assertOk();
            $body = $response->getContent();
            $this->assertStringContainsString('entry.example.test', $body);
            $this->assertStringNotContainsString('changed-exit.example.test', $body);
            $this->assertStringNotContainsString(self::UUID, $body);
            $this->assertStringNotContainsString($outbound->tag(), $body);
        }
    }

    private function adminPath(): string
    {
        return '/api/v2/' . admin_setting('secure_path', hash('crc32b', config('app.key')));
    }

    private function base(): string { return $this->adminPath() . '/server/outbound'; }

    private function member(): User
    {
        $user = app(UserService::class)->createUser(['name' => 'Test member']);
        $user->save();
        return $user;
    }

    private function admin(): void
    {
        $admin = $this->member();
        $admin->update(['is_admin' => true]);
        Sanctum::actingAs($admin);
    }

    private function node(array $extra = []): Server
    {
        return Server::create(array_replace([
            'name' => 'Ingress', 'type' => 'vless', 'host' => 'entry.example.test',
            'port' => 443, 'server_port' => 8443, 'enabled' => true, 'show' => true,
            'protocol_settings' => ['tls' => 1, 'network' => 'tcp', 'tls_settings' => ['server_name' => 'entry.example.test']],
        ], $extra));
    }

    private function nodePayload(Server $node): array
    {
        return $node->only(['id', 'name', 'type', 'host', 'port', 'server_port', 'protocol_settings']);
    }

    private function outboundPayload(): array
    {
        return ['name' => '日本落地', 'type' => 'vless', 'settings' => [
            'server' => 'exit.example.test', 'server_port' => 443, 'uuid' => self::UUID,
            'flow' => 'xtls-rprx-vision', 'tls' => ['enabled' => true, 'server_name' => 'www.example.test',
                'reality' => ['enabled' => true, 'public_key' => self::PUBLIC_KEY, 'short_id' => 'aabb']],
        ], 'notes' => 'Existing external node'];
    }

    private function outbound(): ServerOutbound
    {
        $payload = $this->outboundPayload();
        $payload['settings'] = app(ServerOutboundService::class)->validateSettings($payload['type'], $payload['settings']);
        return ServerOutbound::create($payload);
    }

    private function vlessUri(): string
    {
        return 'vless://' . self::UUID . '@exit.example.test:443?encryption=none&security=reality&type=tcp&sni=www.example.test&fp=chrome&pbk=' . self::PUBLIC_KEY . '&sid=aabb&flow=xtls-rprx-vision#' . rawurlencode('日本落地');
    }
}
