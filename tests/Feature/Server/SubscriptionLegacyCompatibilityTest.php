<?php

namespace Tests\Feature\Server;

use App\Models\Server;
use App\Models\SubscribeTemplate;
use App\Models\User;
use App\Services\UserService;
use App\Services\SubscriptionTemplateService;
use App\Services\SubscriptionTemplateValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class SubscriptionLegacyCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $member;
    private Server $entry;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $this->member = app(UserService::class)->createUser(['name' => 'Legacy member']);
        $this->member->save();
        $base = ['type' => Server::TYPE_SOCKS, 'port' => 1080, 'server_port' => 1080,
            'show' => true, 'enabled' => true, 'protocol_settings' => []];
        $this->entry = Server::create($base + ['name' => '香港入口', 'host' => 'entry.example.com', 'sort' => 1]);
        Server::create($base + ['name' => '美国落地', 'host' => 'exit.example.com', 'sort' => 2]);
    }

    public function test_legacy_non_slash_regex_placeholders_keep_their_original_selection(): void
    {
        foreach (['#^香港#', '~^香港~u', '%^香港%'] as $pattern) {
            $legacy = Yaml::dump([
                'proxies' => [],
                'proxy-groups' => [['name' => 'Legacy selection', 'type' => 'select', 'proxies' => [$pattern]]],
                'rules' => ['MATCH,Legacy selection'],
            ], 10, 2);
            SubscribeTemplate::setContent('clashmeta', $legacy);
            $response = $this->get('/s/' . $this->member->token . '?flag=mihomo')->assertOk();
            $groups = collect(Yaml::parse($response->getContent())['proxy-groups']);
            $this->assertSame(['香港入口'], $groups->firstWhere('name', 'Legacy selection')['proxies']);
            $this->assertSame($legacy, SubscribeTemplate::getContent('clashmeta'), 'Reading a legacy template must not overwrite its source');
        }
    }

    public function test_hidden_entry_in_raw_yaml_dialer_reference_fails_closed_without_exposing_credentials(): void
    {
        $template = Yaml::dump([
            'proxies' => [['name' => 'External exit', 'type' => 'socks5', 'server' => 'external.example.com',
                'port' => 1080, 'password' => 'private-external-credential', 'dialer-proxy' => 'node:' . $this->entry->id]],
            'proxy-groups' => [], 'rules' => ['MATCH,External exit'],
        ], 10, 2);
        SubscribeTemplate::setContent('clashmeta', $template);
        $this->get('/s/' . $this->member->token . '?flag=mihomo')->assertOk();
        $this->entry->update(['show' => false]);
        $response = $this->get('/s/' . $this->member->token . '?flag=mihomo')->assertUnprocessable();
        foreach (['private-external-credential', $this->member->uuid, $this->entry->host] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }

    public function test_hidden_entry_cannot_turn_an_implicit_chain_group_into_direct_fallback(): void
    {
        $exit = Server::query()->where('name', '美国落地')->firstOrFail();
        $profile = SubscriptionTemplateValidator::defaults(Yaml::dump([
            'proxies' => [],
            'proxy-groups' => [['name' => '入口组', 'type' => 'select', 'include-all-proxies' => true, 'filter' => '^香港入口$']],
            'rules' => ['MATCH,美国落地'],
        ], 10, 2));
        $profile['chain_rules'] = [['exit_nodes' => ['node:' . $exit->id], 'entry' => 'group:入口组']];
        app(SubscriptionTemplateService::class)->save('mihomo', $profile);
        $this->get('/s/' . $this->member->token . '?flag=mihomo')->assertOk();
        $this->entry->update(['show' => false]);
        $this->get('/s/' . $this->member->token . '?flag=mihomo')->assertUnprocessable();
    }

    public function test_remote_chain_entry_keeps_an_explicit_reject_when_provider_becomes_empty(): void
    {
        $exit = Server::query()->where('name', '美国落地')->firstOrFail();
        $profile = SubscriptionTemplateValidator::defaults(Yaml::dump([
            'proxies' => [],
            'proxy-providers' => ['remote-entry' => [
                'type' => 'http', 'url' => 'https://entry.example.com/proxies.yaml',
                'path' => './providers/remote-entry.yaml', 'interval' => 3600,
            ]],
            'proxy-groups' => [
                ['name' => '远程入口', 'type' => 'select', 'use' => ['remote-entry'], 'filter' => '^remote-entry$'],
                ['name' => '入口选择', 'type' => 'select', 'proxies' => ['远程入口']],
            ],
            'rules' => ['MATCH,美国落地'],
        ], 10, 2));
        $profile['chain_rules'] = [['exit_nodes' => ['node:' . $exit->id], 'entry' => 'group:入口选择']];

        foreach (['mihomo', 'stash'] as $client) {
            app(SubscriptionTemplateService::class)->save($client, $profile);
            $response = $this->get('/s/' . $this->member->token . '?flag=' . $client)->assertOk();
            $config = Yaml::parse($response->getContent());
            $groups = collect($config['proxy-groups']);
            $remote = $groups->firstWhere('name', '远程入口');
            // Native clients otherwise make an empty provider group COMPATIBLE/DIRECT.
            $this->assertSame(['REJECT'], $remote['proxies']);
            $this->assertSame(['remote-entry'], $remote['use']);
            $this->assertSame('^remote-entry$', $remote['filter']);
            $this->assertSame(['远程入口'], $groups->firstWhere('name', '入口选择')['proxies']);
            $this->assertSame('入口选择', collect($config['proxies'])->firstWhere('name', '美国落地')['dialer-proxy']);
        }
    }
}
