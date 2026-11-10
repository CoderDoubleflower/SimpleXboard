<?php

namespace Tests\Feature\Server;

use App\Models\AdminAuditLog;
use App\Models\Server;
use App\Models\SubscribeTemplate;
use App\Models\User;
use App\Services\AuthService;
use App\Services\SubscriptionTemplateService;
use App\Services\SubscriptionTemplateValidator;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class SubscriptionTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private string $prefix;
    private string $auth;
    private User $member;
    private Server $entry;
    private Server $exit;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $admin = User::create(['name' => 'Admin', 'email' => 'template-admin@example.com', 'is_admin' => true, 'uuid' => Helper::guid(true), 'token' => Helper::guid()]);
        $this->auth = (new AuthService($admin))->generateAuthData()['auth_data'];
        $this->prefix = '/api/v2/' . hash('crc32b', config('app.key'));
        $this->member = app(UserService::class)->createUser(['name' => 'Member']);
        $this->member->save();
        $node = ['type' => Server::TYPE_SOCKS, 'port' => 1080, 'server_port' => 1080, 'show' => true, 'enabled' => true, 'protocol_settings' => []];
        $this->entry = Server::create($node + ['name' => '香港入口', 'host' => 'entry.example.com', 'sort' => 1]);
        $this->exit = Server::create($node + ['name' => '美国落地', 'host' => 'exit.example.com', 'sort' => 2]);
    }

    private function profile(): array
    {
        return SubscriptionTemplateValidator::defaults("mixed-port: 7890\nallow-lan: false\nmode: rule\ndns:\n  enable: false\nproxies: []\nproxy-groups:\n  - name: 节点选择\n    type: select\n    proxies: [\"\$nodes\"]\nrules:\n  - MATCH,节点选择\n");
    }

    private function postTemplate(string $action, array $data)
    {
        app('auth')->forgetGuards();
        return $this->withHeader('Authorization', $this->auth)->postJson($this->prefix . '/subscription-template/' . $action, $data);
    }

    private function save(array $profile, string $client = 'mihomo')
    {
        return $this->postTemplate('save', ['client' => $client, 'profile' => $profile]);
    }

    private function subscription(string $client = 'mihomo', ?User $user = null)
    {
        app('auth')->forgetGuards();
        return $this->get('/s/' . ($user ?? $this->member)->token . '?flag=' . $client);
    }

    public function test_templates_require_admin_and_only_offer_supported_clients(): void
    {
        $this->getJson($this->prefix . '/subscription-template/fetch?client=mihomo')->assertForbidden();
        $response = $this->withHeader('Authorization', $this->auth)->getJson($this->prefix . '/subscription-template/fetch?client=mihomo')->assertOk();
        $response->assertJsonPath('data.client', 'mihomo')->assertJsonCount(18, 'data.categories')->assertJsonPath('data.profile.preset', 'original');
        $response->assertJsonPath('data.nodes.0.ref', 'node:' . $this->entry->id)->assertJsonMissingPath('data.nodes.0.password');
        $this->getJson($this->prefix . '/subscription-template/fetch?client=singbox')->assertUnprocessable();
        $config = $this->getJson($this->prefix . '/config/fetch')->assertOk()->json('data.subscribe_template');
        $this->assertSame(['subscribe_template_mihomo', 'subscribe_template_stash'], array_keys($config));
        $this->postJson($this->prefix . '/config/save', ['subscribe_template_singbox' => '{}'])->assertUnprocessable();
    }

    public function test_preview_uses_real_exporter_without_saving_and_member_credentials_do_not_bleed(): void
    {
        $original = SubscribeTemplate::getContent('clashmeta');
        $profile = $this->profile();
        $profile['custom_rules'] = ['DOMAIN-SUFFIX,work.example,节点选择'];
        $sample = $this->postTemplate('preview', ['client' => 'mihomo', 'profile' => $profile])->assertOk()->json('data.yaml');
        $this->assertStringContainsString('11111111-1111-4111-8111-111111111111', $sample);
        $this->assertStringNotContainsString($this->member->uuid, $sample);
        $preview = $this->postTemplate('preview', ['client' => 'mihomo', 'profile' => $profile, 'user_id' => $this->member->id])->assertOk()->json('data.yaml');
        $this->assertStringContainsString($this->member->uuid, $preview);
        $other = app(UserService::class)->createUser(['name' => 'Other']); $other->save();
        $another = $this->postTemplate('preview', ['client' => 'mihomo', 'profile' => $profile, 'user_id' => $other->id])->assertOk()->json('data.yaml');
        $this->assertStringContainsString($other->uuid, $another);
        $this->assertStringNotContainsString($this->member->uuid, $another);
        $this->assertSame($original, SubscribeTemplate::getContent('clashmeta'));
        $this->assertNull(SubscribeTemplate::getContent('sublink_mihomo'));
        $this->save($profile)->assertOk();
        $this->assertSame($preview, $this->subscription()->assertOk()->getContent());
    }

    public function test_presets_rule_providers_and_ordered_custom_rules_compile_for_both_clients(): void
    {
        foreach (['mihomo', 'stash'] as $client) {
            $profile = $this->profile();
            $profile['preset'] = 'balanced';
            $profile['custom_rules'] = ['DOMAIN,work.example,DIRECT', 'DOMAIN-SUFFIX,example,节点选择', 'IP-CIDR6,2001:db8::/32,REJECT,no-resolve'];
            $profile['rule_providers'] = [['name' => 'work', 'url' => 'https://example.com/work.yaml', 'behavior' => 'domain', 'format' => 'yaml', 'interval' => 3600, 'policy' => '节点选择']];
            $this->save($profile, $client)->assertOk();
            $config = Yaml::parse($this->subscription($client)->assertOk()->getContent());
            $this->assertSame($profile['custom_rules'], array_slice($config['rules'], 1, 3));
            $this->assertSame('RULE-SET,work,节点选择', $config['rules'][4]);
            $this->assertSame('MATCH,节点选择', end($config['rules']));
            $this->assertSame(3600, $config['rule-providers']['work']['interval']);
            $this->assertSame('domain', $config['rule-providers']['google']['behavior']);
            $this->assertSame('ipcidr', $config['rule-providers']['google-ip']['behavior']);
            $this->assertNotSame($config['rule-providers']['google']['path'], $config['rule-providers']['google-ip']['path']);
        }
    }

    public function test_chain_ids_survive_renaming_prefixes_and_do_not_mutate_nodes(): void
    {
        $profile = $this->profile();
        $profile['chain_rules'] = [['exit_nodes' => [$this->exit->name], 'entry' => $this->entry->name]];
        foreach (['mihomo', 'stash'] as $client) {
            $saved = $this->save($profile, $client)->assertOk()->json('data.profile');
            $this->assertSame('node:' . $this->exit->id, $saved['chain_rules'][0]['exit_nodes'][0]);
            $this->assertSame('node:' . $this->entry->id, $saved['chain_rules'][0]['entry']);
        }
        $this->entry->update(['name' => '新入口']);
        $this->exit->update(['name' => '新落地']);
        admin_setting(['show_protocol_to_server_enable' => true]);
        foreach (['mihomo', 'stash'] as $client) {
            $proxies = collect(Yaml::parse($this->subscription($client)->assertOk()->getContent())['proxies']);
            $this->assertSame('[socks]新入口', $proxies->firstWhere('name', '[socks]新落地')['dialer-proxy']);
            $this->assertArrayNotHasKey('dialer-proxy', $proxies->firstWhere('name', '[socks]新入口'));
        }
        $this->assertArrayNotHasKey('dialer-proxy', $this->exit->fresh()->protocol_settings);
    }

    public function test_missing_member_chain_entry_fails_closed_and_missing_exit_is_omitted(): void
    {
        $profile = $this->profile();
        $profile['chain_rules'] = [['exit_nodes' => ['node:' . $this->exit->id], 'entry' => 'node:' . $this->entry->id]];
        $this->save($profile)->assertOk();
        $this->member->update(['access_mode' => 'restricted']);
        $this->member->servers()->sync([$this->exit->id]);
        $error = $this->subscription()->assertUnprocessable()->getContent();
        $this->assertStringNotContainsString($this->entry->host, $error);
        $this->assertStringNotContainsString($this->member->uuid, $error);
        $this->member->servers()->sync([$this->entry->id]);
        $proxies = Yaml::parse($this->subscription()->assertOk()->getContent())['proxies'];
        $this->assertCount(1, $proxies);
        $this->assertArrayNotHasKey('dialer-proxy', $proxies[0]);
        $this->member->servers()->sync([$this->entry->id, $this->exit->id]);
        $this->entry->update(['show' => false]);
        $this->subscription()->assertUnprocessable();
    }

    public function test_cycles_and_invalid_profiles_are_rejected_atomically(): void
    {
        $valid = $this->profile();
        $this->save($valid)->assertOk();
        $cases = [];
        $cases[] = ['base_yaml' => "proxies: [\nprivate-secret: hidden-credential"];
        $cases[] = ['custom_rules' => ['MATCH,DIRECT']];
        $cases[] = ['custom_rules' => ['DOMAIN,work.example,Missing Group']];
        $cases[] = ['custom_rules' => ['IP-CIDR,999.2.3.4/33,DIRECT']];
        $cases[] = ['custom_rules' => ['RULE-SET,missing,DIRECT']];
        $cases[] = ['chain_rules' => [['exit_nodes' => ['node:' . $this->exit->id], 'entry' => 'node:9999']]];
        $cases[] = ['chain_rules' => [
            ['exit_nodes' => ['node:' . $this->exit->id], 'entry' => 'node:' . $this->entry->id],
            ['exit_nodes' => ['node:' . $this->entry->id], 'entry' => 'node:' . $this->exit->id],
        ]];
        $cases[] = ['chain_rules' => [['exit_nodes' => ['node:' . $this->exit->id], 'entry' => 'group:节点选择']]];
        $cases[] = ['rule_providers' => [['name' => 'bad', 'url' => 'file:///etc/passwd', 'behavior' => 'domain', 'format' => 'yaml', 'interval' => 86400, 'policy' => 'DIRECT']]];
        $cases[] = ['rule_providers' => [['name' => 'bad', 'url' => 'https://example.com/bad.mrs', 'behavior' => 'classical', 'format' => 'mrs', 'interval' => 86400, 'policy' => 'DIRECT']]];
        foreach ($cases as $patch) {
            $error = $this->save(array_replace($valid, $patch))->assertUnprocessable()->getContent();
            $this->assertStringNotContainsString('hidden-credential', $error);
            $this->assertSame($valid, app(SubscriptionTemplateService::class)->getProfile('mihomo'));
        }
    }

    public function test_implicit_all_node_group_cycles_are_rejected(): void
    {
        $profile = $this->profile();
        $profile['base_yaml'] .= "\n";
        $config = Yaml::parse($profile['base_yaml']);
        $config['proxy-groups'][] = ['name' => 'All', 'type' => 'select', 'include-all-proxies' => true];
        $profile['base_yaml'] = Yaml::dump($config, 10, 2);
        $profile['chain_rules'] = [['exit_nodes' => ['node:' . $this->exit->id], 'entry' => 'group:All']];
        $this->save($profile)->assertUnprocessable();
    }

    public function test_duplicate_node_names_and_static_names_keep_stable_distinct_references(): void
    {
        $this->entry->update(['name' => 'same']); $this->exit->update(['name' => 'same']);
        $profile = $this->profile();
        $config = Yaml::parse($profile['base_yaml']);
        $config['proxies'][] = ['name' => 'same', 'type' => 'socks5', 'server' => 'external.example.com', 'port' => 1080];
        $profile['base_yaml'] = Yaml::dump($config, 10, 2);
        $profile['chain_rules'] = [['exit_nodes' => ['node:' . $this->exit->id], 'entry' => 'proxy:same']];
        $this->save($profile)->assertOk();
        $proxies = Yaml::parse($this->subscription()->assertOk()->getContent())['proxies'];
        $this->assertCount(3, array_unique(array_column($proxies, 'name')));
        $this->assertSame('same', $proxies[2]['dialer-proxy']);
        $this->assertSame('same [#' . $this->exit->id . ']', $proxies[2]['name']);
        $this->assertArrayNotHasKey('dialer-proxy', $proxies[0]);
    }

    public function test_import_uses_current_draft_and_does_not_log_external_credentials(): void
    {
        $profile = $this->profile();
        $config = Yaml::parse($profile['base_yaml']);
        $config['dns']['nameserver'] = ['https://example.com/dns-query'];
        $config['proxies'] = [['name' => 'external', 'type' => 'socks5', 'server' => 'outside.example.com', 'port' => 1080, 'password' => 'never-log-this-secret']];
        $profile['base_yaml'] = Yaml::dump($config, 10, 2);
        $import = $this->postTemplate('import', ['client' => 'mihomo', 'profile' => $profile, 'content' => json_encode(['selectedRules' => 'balanced'])])->assertOk();
        $this->assertSame($profile['base_yaml'], $import->json('data.profile.base_yaml'));
        $this->assertNull(SubscribeTemplate::getContent('sublink_mihomo'));
        $this->save($profile)->assertOk();
        $this->postTemplate('preview', ['client' => 'mihomo', 'profile' => $profile, 'user_id' => $this->member->id])->assertOk();
        $audit = AdminAuditLog::pluck('request_data')->implode(' ');
        $this->assertStringNotContainsString('never-log-this-secret', $audit);
        $this->assertStringNotContainsString($this->member->uuid, $audit);
        $this->assertStringContainsString('[redacted]', $audit);
        $native = json_encode(['format' => 'simplexboard-subscription-template', 'version' => 1, 'client' => 'mihomo', 'profile' => $profile]);
        $this->postTemplate('import', ['client' => 'mihomo', 'content' => $native])->assertOk()->assertJsonPath('data.profile.base_yaml', $profile['base_yaml']);
    }

    public function test_empty_nodes_and_regex_groups_remain_valid_without_placeholder_leaks(): void
    {
        $this->entry->update(['show' => false]); $this->exit->update(['show' => false]);
        $profile = $this->profile();
        $profile['base_yaml'] = str_replace('["$nodes"]', '["/nonexistent/"]', $profile['base_yaml']);
        $this->save($profile)->assertOk();
        $response = $this->subscription()->assertOk();
        $config = Yaml::parse($response->getContent());
        $this->assertSame([], $config['proxies']);
        $this->assertSame(['REJECT'], $config['proxy-groups'][0]['proxies']);
        $this->assertStringNotContainsString('/nonexistent/', $response->getContent());
        $this->assertStringNotContainsString('$nodes', $response->getContent());
    }

    public function test_legacy_group_insertion_is_migrated_explicitly_without_overwriting_saved_yaml(): void
    {
        $legacy = "proxies: []\nproxy-groups:\n  - name: old\n    type: select\n    proxies: [DIRECT]\nrules: [MATCH,old]\n";
        // Use a quoted rule: YAML flow lists otherwise split its comma.
        $legacy = str_replace('[MATCH,old]', '["MATCH,old"]', $legacy);
        SubscribeTemplate::setContent('clashmeta', $legacy);
        $profile = app(SubscriptionTemplateService::class)->getProfile('mihomo');
        $this->assertSame(['DIRECT', '$nodes'], Yaml::parse($profile['base_yaml'])['proxy-groups'][0]['proxies']);
        $config = Yaml::parse($this->subscription()->assertOk()->getContent());
        $this->assertSame(['DIRECT', '香港入口', '美国落地'], $config['proxy-groups'][0]['proxies']);
        $this->assertSame($legacy, SubscribeTemplate::getContent('clashmeta'));
        $this->save($profile)->assertOk();
        $this->assertSame($profile['base_yaml'], SubscribeTemplate::getContent('clashmeta'));
    }

    public function test_invalid_old_yaml_remains_editable_and_valid_import_can_replace_broken_draft(): void
    {
        SubscribeTemplate::setContent('clashmeta', "broken: [\n");
        $response = $this->withHeader('Authorization', $this->auth)->getJson($this->prefix . '/subscription-template/fetch?client=mihomo')->assertOk();
        $response->assertJsonPath('data.profile.base_yaml', "broken: [\n");
        $this->assertNotEmpty($response->json('data.warnings'));
        $broken = $this->profile(); $broken['base_yaml'] = "broken: [\n"; $broken['chain_rules'] = [['exit_nodes' => [], 'entry' => '']];
        $good = $this->profile();
        $this->postTemplate('import', ['client' => 'mihomo', 'profile' => $broken, 'content' => json_encode(['profile' => $good])])->assertOk()->assertJsonPath('data.profile.base_yaml', $good['base_yaml']);
    }
}
