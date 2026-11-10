<?php

namespace Tests\Unit;

use App\Services\SubscriptionRuleValidator;
use App\Services\SubscriptionTemplateService;
use App\Services\SubscriptionTemplateValidator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class SubscriptionCompilerReviewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::store('redis')->forever('admin_settings', ['app_name' => 'SimpleXboard']);
    }

    private function compile(array $config, array $overrides = [], string $client = 'mihomo'): array
    {
        $validator = new SubscriptionTemplateValidator();
        $profile = $validator->profile(array_replace(
            SubscriptionTemplateValidator::defaults(Yaml::dump($config, 20, 2)), $overrides,
        ), $client);
        return (new SubscriptionTemplateService($validator))->compile($client, $profile, [
            ['name' => 'entry', 'type' => 'socks5', 'server' => '127.0.0.1', 'port' => 1080],
            ['name' => 'exit', 'type' => 'socks5', 'server' => '127.0.0.1', 'port' => 1081],
        ], [['id' => 1], ['id' => 2]], true);
    }

    public function test_non_string_native_rule_provider_url_is_a_validation_error(): void
    {
        $this->expectException(ValidationException::class);
        $this->compile(['mode' => 'rule', 'rule-providers' => [
            'invalid' => ['type' => 'http', 'behavior' => 'domain', 'url' => ['https://rules.example/rules.yaml']],
        ]]);
    }

    public function test_scalar_stash_nameserver_policy_is_a_validation_error(): void
    {
        $this->expectException(ValidationException::class);
        $this->compile(['mode' => 'rule', 'dns' => ['nameserver-policy' => 'invalid']], [], 'stash');
    }

    public function test_unknown_proxy_group_types_are_rejected_before_export(): void
    {
        $this->expectException(ValidationException::class);
        $this->compile(['mode' => 'rule', 'proxy-groups' => [
            ['name' => 'Bad group', 'type' => 'not-a-client-group', 'proxies' => ['entry']],
        ]]);
    }

    public function test_rule_sets_referenced_inside_logical_rules_must_exist(): void
    {
        $this->expectException(ValidationException::class);
        $this->compile(['mode' => 'rule', 'rules' => [
            'AND,((DOMAIN-SUFFIX,example.com),(RULE-SET,missing-provider)),DIRECT',
            'MATCH,DIRECT',
        ]]);
    }

    public function test_sub_rule_cycles_are_rejected_before_export(): void
    {
        $this->expectException(ValidationException::class);
        $this->compile([
            'mode' => 'rule',
            'rules' => ['SUB-RULE,(DOMAIN-SUFFIX,example.com),one', 'MATCH,DIRECT'],
            'sub-rules' => [
                'one' => ['SUB-RULE,(DOMAIN-SUFFIX,example.com),two'],
                'two' => ['SUB-RULE,(DOMAIN-SUFFIX,example.com),one'],
            ],
        ]);
    }

    public function test_sub_rule_inner_proxy_targets_must_exist(): void
    {
        $this->expectException(ValidationException::class);
        $this->compile([
            'mode' => 'rule',
            'rules' => ['SUB-RULE,(DOMAIN-SUFFIX,example.com),work', 'MATCH,DIRECT'],
            'sub-rules' => ['work' => ['DOMAIN-SUFFIX,example.com,missing-proxy', 'MATCH,DIRECT']],
        ]);
    }

    public function test_inline_provider_node_chain_cycles_are_detected(): void
    {
        $this->expectException(ValidationException::class);
        $this->compile([
            'mode' => 'rule',
            'proxy-groups' => [['name' => 'Upstream', 'type' => 'select', 'use' => ['inline-nodes']]],
            'proxy-providers' => [
                'inline-nodes' => ['type' => 'inline', 'payload' => [[
                    'name' => 'provider-hop', 'type' => 'socks5', 'server' => '127.0.0.1', 'port' => 1082,
                    'dialer-proxy' => 'exit',
                ]]],
            ],
        ], ['chain_rules' => [['exit_nodes' => ['node:2'], 'entry' => 'Upstream']]]);
    }

    public function test_include_all_filter_excluding_exit_does_not_create_a_false_loop(): void
    {
        $result = $this->compile([
            'mode' => 'rule',
            'proxy-groups' => [[
                'name' => 'Filtered entry', 'type' => 'select',
                'include-all-proxies' => true, 'filter' => '^entry$',
            ]],
        ], ['chain_rules' => [['exit_nodes' => ['node:2'], 'entry' => 'Filtered entry']]]);
        $this->assertSame('Filtered entry', $result['proxies'][1]['dialer-proxy']);
    }

    public function test_ordinary_group_chain_cycle_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->compile([
            'mode' => 'rule',
            'proxy-groups' => [['name' => 'Contains exit', 'type' => 'select', 'proxies' => ['exit']]],
        ], ['chain_rules' => [['exit_nodes' => ['node:2'], 'entry' => 'Contains exit']]]);
    }

    public function test_regular_acyclic_chain_remains_valid(): void
    {
        $result = $this->compile(['mode' => 'rule'], [
            'chain_rules' => [['exit_nodes' => ['node:2'], 'entry' => 'node:1']],
        ]);
        $this->assertSame('entry', $result['proxies'][1]['dialer-proxy']);
        $this->assertArrayNotHasKey('dialer-proxy', $result['proxies'][0]);
    }

    public function test_valid_nested_conditions_preserve_options_and_resolve_sub_rule_policies(): void
    {
        $validator = new SubscriptionRuleValidator(new SubscriptionTemplateValidator());
        $logical = 'AND,((RULE-SET,work-domains),(OR,((DOMAIN-SUFFIX,example.com),(NETWORK,TCP)))),node:2';
        $nestedIp = 'AND,((IP-CIDR,192.0.2.0/24,no-resolve),(NETWORK,TCP)),DIRECT';
        $result = $validator->validate([
            'rule-providers' => ['work-domains' => ['type' => 'inline', 'behavior' => 'domain', 'payload' => ['+.example.com']]],
            'rules' => [$logical, 'SUB-RULE,(NOT,((NETWORK,UDP))),work', 'MATCH,DIRECT'],
            'sub-rules' => ['work' => [$nestedIp, 'DOMAIN,corp.example,node:1', 'MATCH,DIRECT']],
        ], 'mihomo', fn ($name) => ['node:1' => 'entry', 'node:2' => 'exit'][$name] ?? $name);
        $this->assertSame(str_replace(',node:2', ',exit', $logical), $result['rules'][0]);
        $this->assertSame($nestedIp, $result['sub-rules']['work'][0]);
        $this->assertSame('DOMAIN,corp.example,entry', $result['sub-rules']['work'][1]);
    }

    public function test_malformed_sub_rule_shapes_and_logical_conditions_are_validation_errors(): void
    {
        $validator = new SubscriptionRuleValidator(new SubscriptionTemplateValidator());
        $cases = [
            ['rules' => ['MATCH,DIRECT'], 'sub-rules' => 'bad'],
            ['rules' => ['MATCH,DIRECT'], 'sub-rules' => ['work' => 'bad']],
            ['rules' => ['MATCH,DIRECT'], 'sub-rules' => ['work' => [23]]],
            ['rules' => ['NOT,((DOMAIN,example.com),(NETWORK,TCP)),DIRECT']],
            ['rules' => ['AND,((SUB-RULE,(DOMAIN,example.com),work),(NETWORK,TCP)),DIRECT']],
            ['rules' => ['SUB-RULE,(MATCH),work'], 'sub-rules' => ['work' => ['MATCH,DIRECT']]],
            ['rules' => ['AND,(DOMAIN,example.com),DIRECT']],
        ];
        foreach ($cases as $case) {
            try {
                $validator->validate($case, 'mihomo', fn ($name) => $name);
                $this->fail('Invalid conditional rule was accepted.');
            } catch (ValidationException $exception) {
                $this->assertSame(422, $exception->status);
            }
        }
    }

    public function test_condition_nesting_is_bounded(): void
    {
        $condition = '(NETWORK,TCP)';
        for ($i = 0; $i < 40; $i++) $condition = '(NOT,(' . $condition . '))';
        $this->expectException(ValidationException::class);
        (new SubscriptionRuleValidator(new SubscriptionTemplateValidator()))->validate([
            'rules' => ['AND,(' . $condition . '),DIRECT'],
        ], 'mihomo', fn ($name) => $name);
    }
}
