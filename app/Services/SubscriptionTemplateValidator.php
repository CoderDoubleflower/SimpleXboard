<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/** Validate administrator-authored configuration before it reaches a subscription. */
class SubscriptionTemplateValidator
{
    public const BUILTINS = ['DIRECT', 'REJECT', 'REJECT-DROP', 'PASS', 'COMPATIBLE'];

    public static function defaults(string $yaml): array
    {
        return [
            'version' => 1, 'base_yaml' => $yaml, 'preset' => 'original', 'selected_rules' => [],
            'rule_providers' => [], 'custom_rules' => [], 'chain_rules' => [],
            'default_policy' => '节点选择', 'rule_format' => 'yaml',
            'rule_base_url' => 'https://raw.githubusercontent.com/MetaCubeX/meta-rules-dat/meta/geo',
        ];
    }

    public function profile(array $profile, string $client): array
    {
        if (!in_array($client, ['mihomo', 'stash'], true)) self::fail('client', '仅支持 mihomo 与 Stash。');
        $profile = array_replace(self::defaults(''), $profile);
        $allowed = implode(',', array_keys(self::defaults('')));
        Validator::make(['profile' => $profile], [
            'profile' => ['required', "array:$allowed"],
            'profile.version' => ['required', 'integer', Rule::in([1])],
            'profile.base_yaml' => ['required', 'string', 'max:262144'],
            'profile.preset' => ['required', Rule::in(['original', 'minimal', 'balanced', 'comprehensive', 'custom'])],
            'profile.selected_rules' => ['present', 'array', 'max:32'],
            'profile.selected_rules.*' => ['required', 'string', 'distinct', Rule::in(array_column(SubscriptionRuleCatalog::categories(), 'name'))],
            'profile.rule_format' => ['required', Rule::in(['yaml', 'mrs'])],
            'profile.rule_base_url' => ['required', 'string', 'max:2048'],
            'profile.default_policy' => ['required', 'string', 'max:160'],
            'profile.rule_providers' => ['present', 'array', 'max:150'],
            'profile.rule_providers.*' => ['array:name,url,behavior,format,interval,policy'],
            'profile.rule_providers.*.name' => ['required', 'string', 'max:100', 'distinct', 'regex:/^[A-Za-z0-9_!-]+(?:\.[A-Za-z0-9_!-]+)*$/'],
            'profile.rule_providers.*.url' => ['required', 'string', 'max:2048'],
            'profile.rule_providers.*.behavior' => ['required', Rule::in(['domain', 'ipcidr', 'classical'])],
            'profile.rule_providers.*.format' => ['required', Rule::in(['yaml', 'text', 'mrs'])],
            'profile.rule_providers.*.interval' => ['required', 'integer', 'min:60', 'max:2592000'],
            'profile.rule_providers.*.policy' => ['required', 'string', 'max:200'],
            'profile.custom_rules' => ['present', 'array', 'max:1000'],
            'profile.custom_rules.*' => ['required', 'string', 'max:4096'],
            'profile.chain_rules' => ['present', 'array', 'max:150'],
            'profile.chain_rules.*' => ['array:exit_nodes,entry'],
            'profile.chain_rules.*.exit_nodes' => ['required', 'array', 'min:1', 'max:300'],
            'profile.chain_rules.*.exit_nodes.*' => ['required', 'string', 'max:200'],
            'profile.chain_rules.*.entry' => ['required', 'string', 'max:200'],
        ])->validate();
        foreach (['selected_rules', 'rule_providers', 'custom_rules', 'chain_rules'] as $field) {
            if (!array_is_list($profile[$field])) self::fail("profile.$field", '请使用有序数组。');
        }
        $this->url($profile['rule_base_url'], 'profile.rule_base_url', true);
        $this->name($profile['default_policy'], 'profile.default_policy');
        foreach ($profile['rule_providers'] as $i => $provider) {
            $this->url($provider['url'], "profile.rule_providers.$i.url");
            if ($provider['format'] === 'mrs' && $provider['behavior'] === 'classical') {
                self::fail("profile.rule_providers.$i.format", 'MRS 仅支持 domain 和 ipcidr；classical 请使用 YAML 或 text。');
            }
        }
        $assigned = [];
        foreach ($profile['chain_rules'] as $i => $chain) {
            if (!array_is_list($chain['exit_nodes'])) self::fail("profile.chain_rules.$i.exit_nodes", '落地节点必须是有序数组。');
            foreach ($chain['exit_nodes'] as $exit) {
                if (isset($assigned[$exit])) self::fail("profile.chain_rules.$i.exit_nodes", '同一落地节点只能设置一个入口。');
                $assigned[$exit] = true;
                if ($exit === $chain['entry']) self::fail("profile.chain_rules.$i.entry", '入口和落地节点不能相同。');
            }
        }
        $config = $this->yaml($profile['base_yaml']);
        foreach ($profile['custom_rules'] as $i => &$rule) {
            $rule = trim($rule);
            $this->rule($rule, $client, "profile.custom_rules.$i", false);
        }
        unset($rule);
        foreach ($config['rules'] ?? [] as $i => $rule) {
            $parts = $this->rule($rule, $client, "profile.base_yaml.rules.$i");
            if (in_array($parts[0], ['MATCH', 'FINAL'], true) && $i !== array_key_last($config['rules'])) {
                self::fail('profile.base_yaml', 'MATCH / FINAL 必须是最后一条规则，否则后面的规则不会生效。');
            }
        }
        return $profile;
    }

    public function yaml(string $yaml): array
    {
        try {
            $config = Yaml::parse($yaml, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $e) {
            // The parser message can contain a line with proxy credentials.
            self::fail('profile.base_yaml', '基础 YAML 格式错误' . ($e->getParsedLine() > 0 ? '（第 ' . $e->getParsedLine() . ' 行）' : '') . '。');
        }
        if (!is_array($config) || array_is_list($config)) self::fail('profile.base_yaml', '基础 YAML 必须是配置映射。');
        foreach (['proxies', 'proxy-groups', 'rules'] as $key) {
            if (!isset($config[$key])) $config[$key] = [];
            if (!is_array($config[$key]) || !array_is_list($config[$key])) self::fail('profile.base_yaml', "$key 必须是列表。");
        }
        foreach (['rule-providers', 'proxy-providers'] as $key) {
            if (!isset($config[$key])) $config[$key] = [];
            if (!is_array($config[$key]) || ($config[$key] && array_is_list($config[$key]))) self::fail('profile.base_yaml', "$key 必须是映射。");
        }
        if (isset($config['dns'])) {
            if (!is_array($config['dns']) || ($config['dns'] && array_is_list($config['dns']))) self::fail('profile.base_yaml', 'dns 必须是映射。');
            if (isset($config['dns']['nameserver-policy'])) {
                $policy = $config['dns']['nameserver-policy'];
                if (!is_array($policy) || ($policy && array_is_list($policy))) self::fail('profile.base_yaml', 'DNS nameserver-policy 必须是映射。');
                foreach ($policy as $value) {
                    if (!is_string($value) && (!is_array($value) || !array_is_list($value) || !$value || count(array_filter($value, 'is_string')) !== count($value))) self::fail('profile.base_yaml', 'DNS nameserver-policy 的值必须是地址文本或非空文本列表。');
                }
            }
        }
        foreach (['proxies', 'proxy-groups'] as $section) {
            foreach ($config[$section] as $item) {
                if (!is_array($item) || !is_string($item['name'] ?? null) || !is_string($item['type'] ?? null)) {
                    self::fail('profile.base_yaml', "$section 的每一项都需要 name 和 type。");
                }
                $this->name($item['name'], 'profile.base_yaml');
                if (in_array($item['name'], self::BUILTINS, true) || $item['name'] === 'GLOBAL') self::fail('profile.base_yaml', '节点或策略组不能使用内置策略的名称。');
                if ($section === 'proxy-groups') {
                    if (array_key_exists('dialer-proxy', $item) || $item['type'] === 'relay') self::fail('profile.base_yaml', '请在链式代理中设置入口与落地节点；策略组不支持 dialer-proxy 或已弃用的 relay。');
                    if (!in_array($item['type'], ['select', 'url-test', 'fallback', 'load-balance'], true)) self::fail('profile.base_yaml', '策略组类型不受支持。');
                    foreach (['proxies', 'use'] as $key) {
                        if (isset($item[$key]) && (!is_array($item[$key]) || !array_is_list($item[$key]) || count(array_filter($item[$key], 'is_string')) !== count($item[$key]))) {
                            self::fail('profile.base_yaml', "策略组 $key 必须是字符串列表。");
                        }
                    }
                }
            }
        }
        return $config;
    }

    /** Returns top-level comma-separated fields, preserving logical expressions. */
    public function rule(mixed $rule, string $client, string $field, bool $allowFinal = true): array
    {
        if (!is_string($rule) || trim($rule) === '' || preg_match('/[\r\n\x00]/', $rule)) self::fail($field, '每行只能填写一条规则。');
        $parts = []; $start = 0; $depth = 0;
        for ($i = 0, $length = strlen($rule); $i < $length; $i++) {
            if ($rule[$i] === '(' && ($i === 0 || $rule[$i - 1] !== '\\')) $depth++;
            if ($rule[$i] === ')' && ($i === 0 || $rule[$i - 1] !== '\\')) $depth--;
            if ($depth < 0) self::fail($field, '规则括号不匹配。');
            if ($rule[$i] === ',' && $depth === 0) { $parts[] = trim(substr($rule, $start, $i - $start)); $start = $i + 1; }
        }
        $parts[] = trim(substr($rule, $start));
        if ($depth !== 0) self::fail($field, '规则括号不匹配。');
        $common = ['DOMAIN', 'DOMAIN-SUFFIX', 'DOMAIN-KEYWORD', 'DOMAIN-REGEX', 'GEOSITE', 'GEOIP', 'IP-CIDR', 'IP-CIDR6', 'IP-ASN', 'SRC-IP-CIDR', 'SRC-PORT', 'DST-PORT', 'NETWORK', 'PROCESS-NAME', 'PROCESS-PATH', 'RULE-SET', 'AND', 'OR', 'NOT', 'MATCH'];
        $specific = $client === 'mihomo'
            ? ['DOMAIN-WILDCARD', 'SRC-GEOIP', 'SRC-IP-ASN', 'SRC-IP-SUFFIX', 'IP-SUFFIX', 'IN-PORT', 'IN-TYPE', 'IN-USER', 'IN-NAME', 'PROCESS-NAME-REGEX', 'PROCESS-PATH-REGEX', 'PROCESS-NAME-WILDCARD', 'PROCESS-PATH-WILDCARD', 'REMATCH-NAME', 'DSCP', 'UID', 'SUB-RULE']
            : ['FINAL', 'DOMAIN-WILDCARD', 'PROTOCOL', 'SRC-IP', 'SCRIPT', 'USER-AGENT', 'URL-REGEX'];
        if (!in_array($parts[0], array_merge($common, $specific), true)) self::fail($field, '规则类型不受当前客户端支持。');
        $terminal = in_array($parts[0], ['MATCH', 'FINAL'], true);
        if ($terminal && !$allowFinal) self::fail($field, '自定义规则中不能填写 MATCH / FINAL；最终策略由模板自动追加。');
        $expected = $terminal ? 2 : 3;
        if (count($parts) < $expected || in_array('', $parts, true)) self::fail($field, '规则缺少匹配内容或目标策略。');
        foreach (array_slice($parts, $expected) as $option) {
            $options = $client === 'stash' ? ['no-resolve', 'no-track', 'pre-matching'] : ['no-resolve', 'src'];
            if (!in_array($option, $options, true)) self::fail($field, '规则附加选项不受支持。');
        }
        if (in_array($parts[0], ['IP-CIDR', 'IP-CIDR6', 'SRC-IP-CIDR'], true)) {
            $cidr = explode('/', $parts[1]);
            $ip = $cidr[0];
            if (count($cidr) !== 2 || !filter_var($ip, FILTER_VALIDATE_IP) || !ctype_digit($cidr[1]) || (int) $cidr[1] > (str_contains($ip, ':') ? 128 : 32)) {
                self::fail($field, 'CIDR 必须是有效的 IPv4/IPv6 地址和前缀长度。');
            }
        }
        return $parts;
    }

    public function url(string $url, string $field, bool $base = false): void
    {
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
            || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_FRAGMENT) !== null
            || ($base && parse_url($url, PHP_URL_QUERY) !== null)) {
            self::fail($field, '请输入完整的 HTTP(S) 地址，不能包含账号密码或片段；规则源根地址不能包含查询参数。');
        }
    }

    public function name(string $name, string $field): void
    {
        if (trim($name) === '' || preg_match('/[\x00-\x1F,\r\n]/', $name)) self::fail($field, '名称不能为空，也不能包含逗号或控制字符。');
    }

    public static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
