<?php

namespace Tests\Feature\Server;

use App\Models\Server;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class PrivateSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private User $member;
    private Server $vless;
    private Server $hysteria;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        admin_setting(['server_token' => 'test-node-token', 'show_info_to_server_enable' => 0]);
        $this->member = app(UserService::class)->createUser([
            'email' => 'member@example.com',
        ]);
        $this->member->save();
        $base = [
            'host' => 'node.example.com', 'port' => 443, 'server_port' => 443,
            'show' => true, 'enabled' => true,
        ];
        $this->vless = Server::create($base + [
            'name' => 'Reality node', 'type' => Server::TYPE_VLESS,
            'protocol_settings' => [
                'tls' => 2, 'network' => 'tcp', 'flow' => 'xtls-rprx-vision',
                'reality_settings' => [
                    'server_name' => 'www.example.com', 'server_port' => '443',
                    'public_key' => 'test-public-key', 'private_key' => 'test-private-key',
                    'short_id' => 'abcdef01',
                ],
                'utls' => ['enabled' => true, 'fingerprint' => 'chrome'],
            ],
        ]);
        $this->hysteria = Server::create($base + [
            'name' => 'Hysteria2 node', 'type' => Server::TYPE_HYSTERIA,
            'cert_config' => ['cert_mode' => 'file', 'cert_file' => '/etc/xboard-node/fullchain.pem', 'key_file' => '/etc/xboard-node/private.pem'],
            'protocol_settings' => [
                'version' => 2,
                'tls' => ['server_name' => 'hy.example.com', 'allow_insecure' => false],
                'bandwidth' => ['up' => 100, 'down' => 200],
                'obfs' => ['open' => true, 'type' => 'salamander', 'password' => 'test-obfs'],
            ],
        ]);
    }

    public function test_default_subscription_is_mihomo_and_keeps_reality_and_hysteria2_fields(): void
    {
        $response = $this->get('/s/' . $this->member->token)->assertOk();
        $proxies = collect(Yaml::parse($response->getContent())['proxies']);
        $vless = $proxies->firstWhere('type', 'vless');
        $hysteria = $proxies->firstWhere('type', 'hysteria2');
        $this->assertSame($this->member->uuid, $vless['uuid']);
        $this->assertSame('test-public-key', $vless['reality-opts']['public-key']);
        $this->assertSame('abcdef01', $vless['reality-opts']['short-id']);
        $this->assertSame($this->member->uuid, $hysteria['password']);
        $this->assertSame('hy.example.com', $hysteria['sni']);
        $this->assertSame('test-obfs', $hysteria['obfs-password']);
        $this->assertStringNotContainsString('test-private-key', $response->getContent());
    }

    public function test_clash_meta_subscription_keeps_credentials_and_tls_options(): void
    {
        $response = $this->get('/s/' . $this->member->token . '?flag=clash.meta')->assertOk();
        $proxies = collect(Yaml::parse($response->getContent())['proxies']);
        $vless = $proxies->firstWhere('type', 'vless');
        $hysteria = $proxies->firstWhere('type', 'hysteria2');
        $this->assertSame($this->member->uuid, $vless['uuid']);
        $this->assertSame('test-public-key', $vless['reality-opts']['public-key']);
        $this->assertSame('abcdef01', $vless['reality-opts']['short-id']);
        $this->assertSame($this->member->uuid, $hysteria['password']);
        $this->assertSame('hy.example.com', $hysteria['sni']);
        $this->assertStringNotContainsString('test-private-key', $response->getContent());
    }

    public function test_versionless_stash_subscription_keeps_credentials_and_tls_options(): void
    {
        $response = $this->get('/s/' . $this->member->token . '?flag=stash')->assertOk();
        $proxies = collect(Yaml::parse($response->getContent())['proxies']);
        $vless = $proxies->firstWhere('type', 'vless');
        $hysteria = $proxies->firstWhere('type', 'hysteria2');
        $this->assertSame($this->member->uuid, $vless['uuid']);
        $this->assertSame('test-public-key', $vless['reality-opts']['public-key']);
        $this->assertSame($this->member->uuid, $hysteria['auth']);
        $this->assertSame('hy.example.com', $hysteria['sni']);
        $this->assertSame(100, $hysteria['up-speed']);
        $this->assertArrayNotHasKey('password', $hysteria);
        $this->assertStringNotContainsString('test-private-key', $response->getContent());
    }

    public function test_only_two_exporters_are_registered_and_legacy_mihomo_aliases_still_work(): void
    {
        $this->assertSame([\App\Protocols\ClashMeta::class, \App\Protocols\Stash::class], app('protocols.manager')->getProtocolClasses());
        foreach (['mihomo', 'meta', 'clash.meta', 'clashmeta', 'clash'] as $flag) {
            $response = $this->get('/s/' . $this->member->token . '?flag=' . $flag)->assertOk();
            $proxies = collect(Yaml::parse($response->getContent())['proxies']);
            $this->assertSame($this->member->uuid, $proxies->firstWhere('type', 'hysteria2')['password']);
        }
    }

    public function test_retired_or_unknown_formats_return_an_explanation_instead_of_other_credentials(): void
    {
        foreach (['sing-box', 'singbox', 'surge', 'surfboard', 'general', 'v2rayn', 'shadowrocket', 'quantumultx', 'unknown', 'not-stash'] as $flag) {
            $response = $this->get('/s/' . $this->member->token . '?flag=' . $flag)->assertUnprocessable();
            $this->assertStringContainsString('mihomo', $response->getContent());
            $this->assertStringNotContainsString($this->member->uuid, $response->getContent());
        }
        foreach (['sing-box/1.14.4', 'Surge/6', 'Quantumult X/1.5', 'v2rayN/7.0', 'NekoBox/1.4'] as $ua) {
            $this->withHeader('User-Agent', $ua)->get('/s/' . $this->member->token)->assertUnprocessable();
        }
    }

    public function test_user_agent_detection_preserves_version_when_a_format_flag_is_present(): void
    {
        foreach (['', '?flag=stash'] as $query) {
            $response = $this->withHeader('User-Agent', 'Stash/3.0.0')->get('/s/' . $this->member->token . $query)->assertOk();
            $proxies = collect(Yaml::parse($response->getContent())['proxies']);
            $this->assertNull($proxies->firstWhere('type', 'vless'), 'Known old Stash cannot import REALITY');
            $this->assertSame($this->member->uuid, $proxies->firstWhere('type', 'hysteria2')['auth']);
        }
        $response = $this->withHeader('User-Agent', 'Stash/3.6.0')->get('/s/' . $this->member->token . '?flag=stash')->assertOk();
        $this->assertCount(2, Yaml::parse($response->getContent())['proxies']);

        $response = $this->withHeader('User-Agent', 'Mihomo/1.19.29')->get('/s/' . $this->member->token)->assertOk();
        $hysteria = collect(Yaml::parse($response->getContent())['proxies'])->firstWhere('type', 'hysteria2');
        $this->assertSame($this->member->uuid, $hysteria['password']);
    }

    public function test_stash_never_emits_an_unimplemented_transport_even_without_version(): void
    {
        $settings = $this->vless->protocol_settings;
        $settings['network'] = 'httpupgrade';
        $this->vless->update(['protocol_settings' => $settings]);
        $response = $this->get('/s/' . $this->member->token . '?flag=stash')->assertOk();
        $this->assertNull(collect(Yaml::parse($response->getContent())['proxies'])->firstWhere('type', 'vless'));
    }

    public function test_stash_tuic_versions_and_hysteria_port_hopping_keep_their_native_fields(): void
    {
        $server = ['name' => 'TUIC', 'host' => 'tuic.example.com', 'port' => 443,
            'protocol_settings' => ['version' => '4', 'alpn' => [], 'tls' => ['server_name' => 'sni.example.com']]];
        $v4 = \App\Protocols\Stash::buildTuic('member-secret', $server);
        $this->assertSame(4, $v4['version']);
        $this->assertSame('member-secret', $v4['token']);
        $this->assertArrayNotHasKey('uuid', $v4);
        $this->assertArrayNotHasKey('password', $v4);
        $this->assertSame(['h3'], $v4['alpn']);
        $this->assertSame('sni.example.com', $v4['sni']);
        $server['protocol_settings']['version'] = 5;
        $v5 = \App\Protocols\Stash::buildTuic($this->member->uuid, $server);
        $this->assertSame($this->member->uuid, $v5['uuid']);
        $this->assertSame($this->member->uuid, $v5['password']);
        $this->assertArrayNotHasKey('token', $v5);

        $hysteria = $this->hysteria->toArray();
        $hysteria['ports'] = '10000-20000';
        $hysteria['protocol_settings']['hop_interval'] = 45;
        $proxy = \App\Protocols\Stash::buildHysteria($this->member->uuid, $hysteria);
        $this->assertSame('10000-20000', $proxy['ports']);
        $this->assertSame(45, $proxy['hop-interval']);
        $this->assertSame($this->member->uuid, $proxy['auth']);
    }

    public function test_nodes_receive_authenticated_configuration_and_only_active_members(): void
    {
        foreach ([$this->vless, $this->hysteria] as $node) {
            $query = '?token=test-node-token&node_id=' . $node->id;
            $config = $this->getJson('/api/v2/server/config' . $query)->assertOk();
            $config->assertJsonPath('protocol', $node->type);
            if ($node->type === Server::TYPE_VLESS) {
                $config->assertJsonPath('tls_settings.private_key', 'test-private-key');
            } else {
                $config->assertJsonPath('version', 2)->assertJsonPath('obfs', 'salamander');
            }
            $this->getJson('/api/v2/server/user' . $query)->assertOk()
                ->assertJsonCount(1, 'users')->assertJsonPath('users.0.uuid', $this->member->uuid);
        }
        $this->getJson('/api/v2/server/config?token=wrong&node_id=' . $this->vless->id)
            ->assertUnprocessable();

        $this->member->update(['banned' => true]);
        $this->getJson('/api/v2/server/user?token=test-node-token&node_id=' . $this->vless->id)
            ->assertOk()->assertJsonCount(0, 'users');
    }
}
