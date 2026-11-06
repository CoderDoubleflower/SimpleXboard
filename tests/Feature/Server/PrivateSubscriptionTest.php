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
            'email' => 'member@example.com', 'password' => 'test-password',
        ]);
        $this->member->save();
        $base = [
            'host' => 'node.example.com', 'port' => 443, 'server_port' => 443,
            'rate' => 1, 'group_ids' => [(string) $this->member->group_id],
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
            'protocol_settings' => [
                'version' => 2,
                'tls' => ['server_name' => 'hy.example.com', 'allow_insecure' => false],
                'bandwidth' => ['up' => 100, 'down' => 200],
                'obfs' => ['open' => true, 'type' => 'salamander', 'password' => 'test-obfs'],
            ],
        ]);
    }

    public function test_general_subscription_keeps_reality_and_hysteria2_fields(): void
    {
        $response = $this->get('/s/' . $this->member->token)->assertOk();
        $links = base64_decode($response->getContent(), true);
        $this->assertStringContainsString('vless://' . $this->member->uuid, $links);
        $this->assertStringContainsString('security=reality', $links);
        $this->assertStringContainsString('pbk=test-public-key', $links);
        $this->assertStringContainsString('sid=abcdef01', $links);
        $this->assertStringContainsString('hysteria2://' . $this->member->uuid, $links);
        $this->assertStringContainsString('sni=hy.example.com', $links);
        $this->assertStringContainsString('obfs-password=test-obfs', $links);
        $this->assertStringNotContainsString('test-private-key', $links);
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

    public function test_singbox_subscription_keeps_credentials_and_tls_options(): void
    {
        $response = $this->get('/s/' . $this->member->token . '?flag=sing-box')->assertOk();
        $outbounds = collect(json_decode($response->getContent(), true)['outbounds']);
        $vless = $outbounds->firstWhere('type', 'vless');
        $hysteria = $outbounds->firstWhere('type', 'hysteria2');
        $this->assertSame($this->member->uuid, $vless['uuid']);
        $this->assertSame('test-public-key', $vless['tls']['reality']['public_key']);
        $this->assertSame($this->member->uuid, $hysteria['password']);
        $this->assertSame('hy.example.com', $hysteria['tls']['server_name']);
        $this->assertStringNotContainsString('test-private-key', $response->getContent());
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
