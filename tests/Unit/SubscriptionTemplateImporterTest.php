<?php

namespace Tests\Unit;

use App\Services\SubscriptionRuleCatalog;
use App\Services\SubscriptionTemplateImporter;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class SubscriptionTemplateImporterTest extends TestCase
{
    private function profile(): array
    {
        return [
            'version' => 1,
            'base_yaml' => "# existing configuration\nmode: rule\ndns:\n  enable: true\n  nameserver: [223.5.5.5]\n",
            'preset' => 'original',
            'selected_rules' => [],
            'rule_providers' => [],
            'custom_rules' => [],
            'chain_rules' => [],
            'default_policy' => '节点选择',
            'rule_format' => 'yaml',
            'rule_base_url' => 'https://raw.githubusercontent.com/MetaCubeX/meta-rules-dat/meta/geo',
        ];
    }

    private function import(array|string $content, ?array $current = null): array
    {
        return (new SubscriptionTemplateImporter())->import(
            is_array($content) ? json_encode($content, JSON_THROW_ON_ERROR) : $content,
            $current ?? $this->profile(),
        );
    }

    public function test_native_envelope_and_bare_profile_round_trip_without_loss(): void
    {
        $profile = $this->profile();
        $profile['preset'] = 'balanced';
        $profile['custom_rules'] = ['DOMAIN-SUFFIX,work.example,DIRECT'];
        $profile['chain_rules'] = [['exit_nodes' => ['node:12'], 'entry' => 'node:13']];
        foreach ([$profile, ['client' => 'stash', 'profile' => $profile]] as $payload) {
            $result = $this->import($payload);
            $this->assertSame($profile, $result['profile']);
            $this->assertSame([], $result['warnings']);
        }
    }

    public function test_saved_sublink_settings_preserve_base_dns_and_convert_custom_rules(): void
    {
        $result = $this->import(['version' => 1, 'config' => [
            'selectedRules' => 'balanced',
            'customRules' => [[
                'name' => '工作',
                'domain_suffix' => 'corp.example, api.example',
                'domain_keyword' => ['office'],
                'src_ip_cidr' => ['192.168.1.12/32'],
                'ip_cidr' => ['203.0.113.0/24', '2001:db8::/32'],
                'site' => ['category-ai-!cn'],
                'ip' => 'telegram',
            ]],
            'dialerProxyRules' => [['proxyNames' => ['美国落地'], 'target' => '香港入口']],
        ]]);
        $profile = $result['profile'];
        $this->assertSame('balanced', $profile['preset']);
        $this->assertSame(SubscriptionRuleCatalog::presets()['balanced'], $profile['selected_rules']);
        $base = Yaml::parse($profile['base_yaml']);
        $this->assertSame(['223.5.5.5'], $base['dns']['nameserver']);
        $this->assertSame([
            ['name' => '工作', 'type' => 'select', 'proxies' => ['节点选择', 'DIRECT', 'REJECT']],
        ], $base['proxy-groups']);
        $this->assertSame([
            'SRC-IP-CIDR,192.168.1.12/32,工作',
            'DOMAIN-SUFFIX,corp.example,工作',
            'DOMAIN-SUFFIX,api.example,工作',
            'DOMAIN-KEYWORD,office,工作',
            'RULE-SET,category-ai-!cn,工作',
            'RULE-SET,telegram-ip,工作,no-resolve',
            'IP-CIDR,203.0.113.0/24,工作,no-resolve',
            'IP-CIDR6,2001:db8::/32,工作,no-resolve',
        ], $profile['custom_rules']);
        $this->assertSame('domain', $profile['rule_providers'][0]['behavior']);
        $this->assertSame('https://raw.githubusercontent.com/MetaCubeX/meta-rules-dat/meta/geo/geoip/telegram.yaml', $profile['rule_providers'][1]['url']);
        $this->assertSame([['exit_nodes' => ['美国落地'], 'entry' => '香港入口']], $profile['chain_rules']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_conversion_url_is_parsed_locally_without_fetching_or_retaining_subscription_secrets(): void
    {
        Http::preventStrayRequests();
        $url = 'https://converter.example/stash?' . http_build_query([
            'config' => 'https://subscription.example/api?token=must-not-survive',
            'selectedRules' => json_encode(['Google', 'Private']),
            'customRules' => json_encode([['name' => 'DIRECT', 'domain_suffix' => 'home.example']]),
            'dialer_proxy_rules' => json_encode([['proxyNames' => ['exit'], 'target' => 'entry']]),
            'configId' => 'remote-template',
        ]);
        $result = $this->import($url);
        $this->assertSame('custom', $result['profile']['preset']);
        $this->assertSame(['Google', 'Private'], $result['profile']['selected_rules']);
        $this->assertSame(['DOMAIN-SUFFIX,home.example,DIRECT'], $result['profile']['custom_rules']);
        $this->assertSame($this->profile()['base_yaml'], $result['profile']['base_yaml']);
        $this->assertCount(2, $result['warnings']);
        $this->assertStringNotContainsString('must-not-survive', json_encode($result));
        $this->assertStringNotContainsString('subscription.example', json_encode($result));
        Http::assertNothingSent();
    }

    public function test_last_matching_source_chain_rule_wins_without_ambiguous_duplicates(): void
    {
        $result = $this->import([
            'selectedRules' => 'minimal',
            'dialerProxyRules' => [
                ['proxyNames' => ['exit-a', 'exit-b', 'exit-a'], 'target' => 'entry-one'],
                ['proxyNames' => ['exit-b', '1'], 'target' => 'entry-two'],
            ],
        ]);
        $this->assertSame([
            ['exit_nodes' => ['exit-a'], 'entry' => 'entry-one'],
            ['exit_nodes' => ['exit-b', '1'], 'entry' => 'entry-two'],
        ], $result['profile']['chain_rules']);
        $this->assertCount(1, $result['warnings']);
    }

    public function test_source_custom_order_and_same_provider_references_are_preserved(): void
    {
        $source = [
            'selectedRules' => [],
            'customRules' => [
                ['name' => 'First', 'site' => 'google', 'domain_suffix' => 'first.example'],
                ['name' => 'Second', 'site' => 'google', 'domain_suffix' => 'second.example'],
            ],
        ];
        $first = $this->import($source);
        $second = $this->import($source);
        $this->assertSame($first, $second);
        $this->assertSame('minimal', $first['profile']['preset']);
        $this->assertSame([
            'DOMAIN-SUFFIX,first.example,First', 'RULE-SET,google,First',
            'DOMAIN-SUFFIX,second.example,Second', 'RULE-SET,google,Second',
        ], $first['profile']['custom_rules']);
        $this->assertCount(1, $first['profile']['rule_providers']);
        $this->assertSame('First', $source['customRules'][0]['name']);
    }

    public function test_explicit_source_base_yaml_preserves_static_groups_and_does_not_duplicate_them(): void
    {
        $yaml = "mode: rule\nproxy-groups:\n  - name: Work\n    type: select\n    proxies: [DIRECT]\n";
        $result = $this->import([
            'selectedRules' => 'minimal',
            'baseConfig' => $yaml,
            'customRules' => [['name' => 'Work', 'domain_suffix' => 'corp.example']],
        ]);
        $this->assertSame($yaml, $result['profile']['base_yaml']);
        $this->assertCount(1, Yaml::parse($result['profile']['base_yaml'])['proxy-groups']);
    }

    public function test_explicit_native_yaml_and_json_can_be_imported_as_base_templates(): void
    {
        $config = ['mode' => 'rule', 'rules' => ['MATCH,DIRECT']];
        $current = $this->profile();
        $current['preset'] = 'balanced';
        $current['custom_rules'] = ['DOMAIN-SUFFIX,previous.example,DIRECT'];
        $current['chain_rules'] = [['exit_nodes' => ['old-exit'], 'entry' => 'old-entry']];
        foreach ([Yaml::dump($config), json_encode($config)] as $content) {
            $result = $this->import($content, $current);
            $this->assertSame($config, Yaml::parse($result['profile']['base_yaml']));
            $this->assertSame('original', $result['profile']['preset']);
            $this->assertSame([], $result['profile']['custom_rules']);
            $this->assertSame([], $result['profile']['chain_rules']);
            $this->assertCount(1, $result['warnings']);
        }
    }

    public function test_malformed_inputs_and_unsupported_source_protocols_fail_with_validation_errors(): void
    {
        $cases = [
            '[]', '{}', '42', '{bad json',
            'https://converter.example/s/short-link-token',
            ['selectedRules' => 'typo'],
            ['selectedRules' => ['Unknown']],
            ['selectedRules' => ['Google'], 'customRules' => '{'],
            ['customRules' => [['name' => 'Broken']]],
            ['customRules' => [['name' => 'Unsafe,policy', 'domain_suffix' => 'example.com']]],
            ['customRules' => [['name' => 'Protocol', 'domain_suffix' => 'example.com', 'protocol' => ['http']]]],
            ['customRules' => [['name' => 'IP', 'ip_cidr' => '192.168.1.1/999']]],
            ['dialerProxyRules' => [['proxyNames' => ['same'], 'target' => 'same']]],
            ['dialerProxyRules' => [['proxyNames' => [], 'target' => 'entry']]],
            ['profile' => ['version' => 2]],
            ['selectedRules' => 'minimal', 'baseConfig' => "mode: rule\nproxies: broken\n", 'dialerProxyRules' => [['proxyNames' => ['exit'], 'target' => 'entry']]],
            ['base_yaml' => "a: 1\na: 2\n"],
            ['custom_rules' => ["DOMAIN,example.com,DIRECT\nMATCH,REJECT"]],
            ['chain_rules' => [['exit_nodes' => [123], 'entry' => 'entry']]],
        ];
        foreach ($cases as $index => $content) {
            try {
                $this->import($content);
                $this->fail("Malformed import {$index} was accepted.");
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors(), "Malformed import {$index}");
            }
        }
    }

    public function test_unsafe_geosite_identifiers_fail_instead_of_being_silently_removed(): void
    {
        foreach (['../secret', '..', '.hidden', 'rules..private', 'foo/bar', 'foo%2fbar', 'foo?token=private', 'foo#fragment', 'https://remote.example'] as $id) {
            try {
                $this->import(['customRules' => [['name' => 'Work', 'site' => [$id]]]]);
                $this->fail('Unsafe identifier accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('content', $exception->errors());
                $this->assertStringNotContainsString($id, json_encode($exception->errors(), JSON_UNESCAPED_SLASHES));
            }
        }
    }

    public function test_catalog_keeps_site_and_ip_providers_distinct_and_sets_correct_default_policies(): void
    {
        $built = SubscriptionRuleCatalog::generate(SubscriptionRuleCatalog::presets()['balanced'], 'mrs', $this->profile()['rule_base_url'], 'Main');
        $this->assertSame('domain', $built['providers']['google']['behavior']);
        $this->assertSame('ipcidr', $built['providers']['google-ip']['behavior']);
        $this->assertSame('./rules/google.mrs', $built['providers']['google']['path']);
        $this->assertSame('./rules/google-ip.mrs', $built['providers']['google-ip']['path']);
        $this->assertSame('RULE-SET,private-ip,Private,no-resolve', $built['rules'][0]);
        $this->assertSame('RULE-SET,geolocation-!cn,Non-China', end($built['rules']));
        $groups = array_column($built['groups'], null, 'name');
        $this->assertSame('DIRECT', $groups['Private']['proxies'][0]);
        $this->assertSame('DIRECT', $groups['Location:CN']['proxies'][0]);
        $this->assertSame('Main', $groups['Google']['proxies'][0]);
        $ads = SubscriptionRuleCatalog::generate(['Ad Block'], 'yaml', $this->profile()['rule_base_url'], 'Main');
        $this->assertSame('REJECT', $ads['groups'][0]['proxies'][0]);
    }

    public function test_catalog_avoids_self_references_and_accepts_safe_custom_geosite_names(): void
    {
        $built = SubscriptionRuleCatalog::generate(['Google'], 'yaml', $this->profile()['rule_base_url'], 'Google');
        $this->assertSame(['DIRECT', 'REJECT'], $built['groups'][0]['proxies']);
        $provider = SubscriptionRuleCatalog::provider('My_Rules-v2.0-!cn', 'domain', 'yaml', $this->profile()['rule_base_url']);
        $this->assertStringEndsWith('/geosite/My_Rules-v2.0-!cn.yaml', $provider['url']);
    }

    public function test_import_warnings_explain_options_that_cannot_be_transferred(): void
    {
        $result = $this->import([
            'selectedRules' => 'minimal',
            'dialer_proxy' => 'global-entry',
            'group_by_country' => 'true',
            'enable_clash_ui' => 'true',
            'input' => 'ss://secret@example.com',
        ]);
        $this->assertCount(4, $result['warnings']);
        $this->assertStringNotContainsString('secret', json_encode($result));
    }

    public function test_source_generated_group_labels_map_to_canonical_names(): void
    {
        $aliases = [
            '🚀 节点选择' => '节点选择', '🚀 Node Select' => '节点选择',
            '💬 AI 服务' => 'AI Services', '🔍 Google Services' => 'Google',
            '🏠 私有网络' => 'Private', '中国大陆' => 'Location:CN',
            '📹 YouTube' => 'Youtube', '🌐 Социальные сети' => 'Social Media',
            '📲 تلگرام' => 'Telegram',
        ];
        foreach ($aliases as $source => $expected) {
            $result = $this->import([
                'selectedRules' => 'comprehensive',
                'dialerProxyRules' => [['proxyNames' => ['exit'], 'target' => $source]],
            ]);
            $this->assertSame($expected, $result['profile']['chain_rules'][0]['entry']);
        }
    }

    public function test_referenced_source_automatic_groups_are_created_with_dynamic_authorised_members(): void
    {
        foreach (['⚡ 自动选择' => ['自动选择', 'url-test'], '🖐️ Manual Switch' => ['手动切换', 'select'], '🐟 Fall Back' => ['漏网之鱼', 'select']] as $source => [$name, $type]) {
            $result = $this->import([
                'selectedRules' => 'minimal',
                'dialerProxyRules' => [['proxyNames' => ['exit'], 'target' => $source]],
            ]);
            $base = Yaml::parse($result['profile']['base_yaml']);
            $this->assertSame($name, $result['profile']['chain_rules'][0]['entry']);
            $this->assertSame($type, $base['proxy-groups'][0]['type']);
            $this->assertContains('$nodes', $base['proxy-groups'][0]['proxies']);
        }
    }

    public function test_authored_custom_external_and_static_names_override_source_aliases(): void
    {
        $yaml = Yaml::dump([
            'mode' => 'rule',
            'proxies' => [['name' => '🔍 谷歌服务', 'type' => 'socks5', 'server' => 'entry.example', 'port' => 1080]],
            'proxy-groups' => [
                ['name' => '🚀 节点选择', 'type' => 'select', 'proxies' => ['🔍 谷歌服务']],
                ['name' => 'External entry', 'type' => 'select', 'use' => ['external']],
            ],
            'proxy-providers' => ['external' => ['type' => 'http', 'url' => 'https://nodes.example/nodes.yaml']],
        ], 10, 2);
        foreach (['🔍 谷歌服务', '🚀 节点选择', 'External entry'] as $target) {
            $result = $this->import([
                'selectedRules' => 'balanced', 'baseConfig' => $yaml,
                'dialerProxyRules' => [['proxyNames' => ['exit'], 'target' => $target]],
            ]);
            $this->assertSame($target, $result['profile']['chain_rules'][0]['entry']);
            $this->assertSame($yaml, $result['profile']['base_yaml']);
        }
        $custom = $this->import([
            'selectedRules' => 'minimal',
            'customRules' => [['name' => '💬 AI 服务', 'domain_suffix' => 'work.example']],
            'dialerProxyRules' => [['proxyNames' => ['exit'], 'target' => '💬 AI 服务']],
        ]);
        $this->assertSame('💬 AI 服务', $custom['profile']['chain_rules'][0]['entry']);
    }
}
