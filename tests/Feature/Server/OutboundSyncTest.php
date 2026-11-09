<?php

namespace Tests\Feature\Server;

use App\Models\Server;
use App\Models\ServerOutbound;
use App\Services\ServerService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class OutboundSyncTest extends TestCase
{
    private array $messages = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Real commit boundaries matter for shared credential changes.
        Artisan::call('migrate:fresh', ['--force' => true]);
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

    public function test_shared_changes_publish_after_commit_to_visible_and_hidden_references_only(): void
    {
        $outbound = $this->outbound();
        $visible = $this->node(['outbound_id' => $outbound->id]);
        $hidden = $this->node(['outbound_id' => $outbound->id, 'show' => false]);
        $offline = $this->node(['outbound_id' => $outbound->id]);
        $unrelated = $this->node();
        foreach ([$visible, $hidden, $unrelated] as $node) Cache::put('node_ws_alive:' . $node->id, true, 600);

        $settings = $outbound->settings;
        $settings['password'] = 'new-password';
        DB::beginTransaction();
        $outbound->update(['settings' => $settings]);
        $this->assertSame([], $this->messages);
        DB::rollBack();
        $this->assertSame([], $this->messages);
        $this->assertSame('original-password', $outbound->fresh()->settings['password']);

        DB::transaction(fn () => $outbound->fresh()->update(['settings' => $settings]));
        $this->assertEqualsCanonicalizing([$visible->id, $hidden->id], array_column($this->messages, 'node_id'));
        foreach ($this->messages as $message) {
            $this->assertSame('sync.config', $message['event']);
            $this->assertSame('new-password', $message['data']['config']['custom_outbounds'][0]['settings']['password']);
            $node = Server::findOrFail($message['node_id']);
            $this->assertSame(ServerService::buildNodeConfig($node), $message['data']['config']);
        }
        $this->assertSame('new-password', ServerService::buildNodeConfig($offline->fresh())['custom_outbounds'][0]['settings']['password']);
        $this->messages = [];
        $outbound->fresh()->update(['name' => 'Renamed']);
        $this->assertSame([], $this->messages);
    }

    public function test_changing_and_removing_a_node_binding_push_the_effective_config_after_commit(): void
    {
        $outbound = $this->outbound();
        $node = $this->node();
        Cache::put('node_ws_alive:' . $node->id, true, 600);
        DB::beginTransaction();
        $node->update(['outbound_id' => $outbound->id]);
        $this->assertSame([], $this->messages);
        DB::commit();
        $this->assertCount(1, $this->messages);
        $this->assertSame($outbound->tag(), $this->messages[0]['data']['config']['default_outbound']);
        $this->messages = [];
        DB::transaction(fn () => $node->update(['outbound_id' => null]));
        $this->assertCount(1, $this->messages);
        $this->assertArrayNotHasKey('default_outbound', $this->messages[0]['data']['config']);
        $this->assertArrayNotHasKey('custom_outbounds', $this->messages[0]['data']['config']);
    }

    private function outbound(): ServerOutbound
    {
        return ServerOutbound::create(['name' => 'Shared SOCKS5', 'type' => 'socks', 'settings' => [
            'server' => 'exit.example.test', 'server_port' => 1080, 'version' => '5',
            'username' => 'user', 'password' => 'original-password',
        ]]);
    }

    private function node(array $attributes = []): Server
    {
        return Server::create($attributes + [
            'name' => 'Ingress', 'type' => 'vless', 'host' => 'entry.example.test', 'port' => 443, 'server_port' => 8443,
            'protocol_settings' => ['tls' => 1, 'network' => 'tcp', 'tls_settings' => ['server_name' => 'entry.example.test']],
        ]);
    }
}
