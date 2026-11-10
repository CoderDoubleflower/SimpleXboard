<?php

namespace App\Services;

use App\Http\Controllers\V1\Client\ClientController;
use App\Models\SubscribeTemplate;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;

/**
 * Native subscription composition adapted from sublink-worker's rule builders.
 * Node credentials and ACLs always remain in SimpleXboard's normal exporters.
 */
class SubscriptionTemplateService
{
    public function __construct(private SubscriptionTemplateValidator $validator) {}

    public function getProfile(string $client): array
    {
        $key = $this->templateKey($client);
        $yaml = subscribe_template($key);
        if (!is_string($yaml) || trim($yaml) === '') $yaml = file_get_contents(resource_path('rules/default.clash.yaml'));
        $options = SubscribeTemplate::getContent('sublink_' . $client);
        if ($options === null) $yaml = $this->migrateLegacyGroups($yaml);
        $options = $options === null ? [] : json_decode($options, true);
        if (!is_array($options)) SubscriptionTemplateValidator::fail('profile', '已保存的模板配置损坏，请重新导入。');
        return array_replace(SubscriptionTemplateValidator::defaults($yaml), $options, ['base_yaml' => $yaml]);
    }

    public function save(string $client, array $profile): array
    {
        $profile = $this->prepareProfile($client, $profile);
        // Preview uses the exact production exporter, including reference/loop checks.
        $this->preview($client, $profile);
        DB::transaction(function () use ($client, $profile) {
            SubscribeTemplate::setContent($this->templateKey($client), $profile['base_yaml']);
            $options = $profile;
            unset($options['base_yaml']);
            SubscribeTemplate::setContent('sublink_' . $client, json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        });
        return $profile;
    }

    public function preview(string $client, array $profile, ?User $user = null): array
    {
        $profile = $this->prepareProfile($client, $profile);
        $sample = $user === null;
        if ($user !== null && !$user->isAvailable()) SubscriptionTemplateValidator::fail('user_id', '该成员已停用、到期或没有可用额度。');
        $user ??= new User([
            'uuid' => '11111111-1111-4111-8111-111111111111', 'token' => 'preview-only',
            'access_mode' => 'all', 'is_admin' => false, 'banned' => false,
            'transfer_enable' => null, 'expired_at' => null, 'u' => 0, 'd' => 0,
        ]);
        $request = request();
        $previous = $request->attributes->get('subscription_template_preview');
        // A request attribute cannot be injected through a query string or JSON body.
        $request->attributes->set('subscription_template_preview', ['client' => $client, 'profile' => $profile, 'strict' => $sample]);
        $renderRequest = clone $request;
        $renderRequest->merge(['flag' => $client]);
        try {
            $response = app(ClientController::class)->doSubscribe($renderRequest, $user);
            $yaml = $response->getContent();
            $compileWarnings = $request->attributes->get('subscription_template_preview')['warnings'] ?? [];
        } finally {
            if ($previous === null) $request->attributes->remove('subscription_template_preview');
            else $request->attributes->set('subscription_template_preview', $previous);
        }
        $warnings = [$sample ? '示例预览使用演示凭据；请选定成员查看其真实订阅。' : '预览包含该成员的真实订阅凭据，请勿公开分享。'];
        array_push($warnings, ...$compileWarnings);
        if ($client === 'stash' && $profile['chain_rules']) $warnings[] = '代理链需要 Stash iOS/tvOS 3.2.5+ 或 macOS 4.1+。';
        return ['client' => $client, 'yaml' => $yaml, 'warnings' => $warnings];
    }

    /** Resolve imported panel node names once, while the administrator can see all nodes. */
    public function prepareProfile(string $client, array $profile): array
    {
        $profile = $this->validator->profile($profile, $client);
        if (!$profile['chain_rules']) return $profile;
        $base = $this->replaceAppName($this->validator->yaml($profile['base_yaml']));
        $static = array_fill_keys(array_merge(array_column($base['proxies'], 'name'), array_column($base['proxy-groups'], 'name')), true);
        $names = [];
        foreach (Server::query()->get(['id', 'name', 'type', 'protocol_settings']) as $node) {
            $names[$node->name][] = 'node:' . $node->id;
            $prefix = $node->type === 'hysteria' ? ((int) ($node->protocol_settings['version'] ?? 1) === 2 ? '[Hy2]' : '[Hy]') : '[' . ($node->type === 'shadowsocks' ? 'ss' : $node->type) . ']';
            $names[$prefix . $node->name][] = 'node:' . $node->id;
        }
        $canonical = function (string $ref) use ($names, $static): string {
            if (preg_match('/^(node|proxy|group):/', $ref) || isset($static[$ref])) return $ref;
            return isset($names[$ref]) && count($names[$ref]) === 1 ? $names[$ref][0] : $ref;
        };
        foreach ($profile['chain_rules'] as &$chain) {
            $chain['exit_nodes'] = array_map($canonical, $chain['exit_nodes']);
            $chain['entry'] = $canonical($chain['entry']);
        }
        unset($chain);
        return $this->validator->profile($profile, $client);
    }

    public function build(string $client, array $proxies, array $servers): array
    {
        $override = request()->attributes->get('subscription_template_preview');
        $preview = is_array($override) && ($override['client'] ?? null) === $client;
        $profile = $this->validator->profile($preview ? $override['profile'] : $this->getProfile($client), $client);
        return $this->compile($client, $profile, $proxies, $servers, $preview && ($override['strict'] ?? false));
    }

    public function compile(string $client, array $profile, array $managedProxies, array $servers, bool $strict = false): array
    {
        $config = $this->replaceAppName($this->validator->yaml($profile['base_yaml']));
        $nodeRefs = []; $originalNames = []; $used = []; $staticNames = [];
        foreach ($config['proxies'] as $proxy) {
            if (isset($used[$proxy['name']])) SubscriptionTemplateValidator::fail('profile.base_yaml', '基础 YAML 中存在重名节点。');
            $used[$proxy['name']] = true;
            $staticNames[$proxy['name']] = true;
        }
        $counts = array_count_values(array_column($managedProxies, 'name'));
        foreach (array_values($managedProxies) as $i => $proxy) {
            $original = $proxy['name'];
            $id = $servers[$i]['id'] ?? ($i + 1);
            $name = $original;
            if (isset($used[$name]) || ($counts[$name] ?? 0) > 1 || in_array($name, SubscriptionTemplateValidator::BUILTINS, true) || $name === 'GLOBAL') {
                $name .= ' [#' . $id . ']';
            }
            while (isset($used[$name])) $name .= ' [node]';
            $this->validator->name($name, 'profile.base_yaml');
            $proxy['name'] = $name;
            $used[$name] = true;
            $nodeRefs['node:' . $id] = $name;
            $originalNames[$original][] = $name;
            $config['proxies'][] = $proxy;
        }
        $nodeNames = array_values($nodeRefs);
        $groups = [];
        foreach ($config['proxy-groups'] as $group) {
            $name = $group['name'];
            if (isset($groups[$name]) || isset($used[$name])) SubscriptionTemplateValidator::fail('profile.base_yaml', '节点和策略组名称必须唯一。');
            $group['proxies'] = $this->expandNodes($group, $nodeNames);
            $groups[$name] = $group;
        }
        $policy = $this->replaceAppName($profile['default_policy']);
        if (!isset($groups[$policy]) && !isset($used[$policy]) && !in_array($policy, SubscriptionTemplateValidator::BUILTINS, true)) {
            $groups[$policy] = ['name' => $policy, 'type' => 'select', 'proxies' => $nodeNames ?: ['REJECT']];
        }
        $selected = $profile['preset'] === 'custom' ? $profile['selected_rules'] : (SubscriptionRuleCatalog::presets()[$profile['preset']] ?? []);
        $generated = SubscriptionRuleCatalog::generate($selected, $profile['rule_format'], $profile['rule_base_url'], $policy);
        foreach ($generated['groups'] as $group) {
            if (isset($used[$group['name']])) SubscriptionTemplateValidator::fail('profile.selected_rules', '规则分类与已有节点名称冲突。');
            $groups[$group['name']] ??= $group;
        }

        $providers = $config['rule-providers'];
        foreach ($generated['providers'] as $name => $provider) $this->addProvider($providers, $name, $provider, 'profile.selected_rules');
        $providerRules = [];
        foreach ($profile['rule_providers'] as $i => $provider) {
            $name = $provider['name'];
            $definition = array_intersect_key($provider, array_flip(['url', 'behavior', 'format', 'interval']));
            $definition['type'] = 'http';
            $definition['path'] = './rules/' . $name . '.' . ($provider['format'] === 'text' ? 'list' : $provider['format']);
            $this->addProvider($providers, $name, $definition, "profile.rule_providers.$i.name");
            $rule = 'RULE-SET,' . $name . ',' . $provider['policy'] . ($provider['behavior'] === 'ipcidr' ? ',no-resolve' : '');
            if (!in_array($rule, $profile['custom_rules'], true)) $providerRules[] = $rule;
        }
        $this->validateProviders($providers);
        $config['rule-providers'] = $providers;

        // Never query additional nodes here. Only names from the member's filtered export exist.
        $proxyIndexes = array_flip(array_column($config['proxies'], 'name'));
        $references = $used + array_fill_keys(array_keys($groups), true) + array_fill_keys(SubscriptionTemplateValidator::BUILTINS, true);
        $resolve = function (string $ref, bool $required = true) use ($nodeRefs, $references, $originalNames, $staticNames, $groups, $proxyIndexes): ?string {
            if (str_starts_with($ref, 'node:')) {
                $name = $nodeRefs[$ref] ?? null;
            } else {
                $name = preg_replace('/^(?:proxy|group):/', '', $ref);
                if (str_starts_with($ref, 'group:') && !isset($groups[$name])) $name = null;
                elseif (str_starts_with($ref, 'proxy:') && !isset($proxyIndexes[$name])) $name = null;
                elseif (!isset($staticNames[$name]) && !isset($groups[$name])) {
                    if (isset($originalNames[$name]) && count($originalNames[$name]) > 1) SubscriptionTemplateValidator::fail('profile.chain_rules', '同名节点请使用节点 ID 引用。');
                    $name = $originalNames[$name][0] ?? $name;
                }
                if (!isset($references[$name])) $name = null;
            }
            if ($name === null && $required) SubscriptionTemplateValidator::fail('profile.chain_rules', '链式代理或策略引用不可用；请检查节点展示、成员授权和客户端协议支持。');
            return $name;
        };
        $assignedExits = [];
        foreach ($profile['chain_rules'] as $i => $chain) {
            $exits = [];
            foreach ($chain['exit_nodes'] as $ref) {
                $name = $resolve($ref, $strict || !str_starts_with($ref, 'node:'));
                if ($name !== null) {
                    if (!isset($proxyIndexes[$name])) SubscriptionTemplateValidator::fail("profile.chain_rules.$i.exit_nodes", '落地端必须是真实节点，不能是策略组或内置策略。');
                    if (isset($assignedExits[$name])) SubscriptionTemplateValidator::fail("profile.chain_rules.$i.exit_nodes", '同一落地节点只能设置一个入口。');
                    $assignedExits[$name] = true;
                    $exits[] = $name;
                }
            }
            if (!$exits) continue;
            $entry = $resolve($chain['entry']);
            if (in_array($entry, SubscriptionTemplateValidator::BUILTINS, true)) SubscriptionTemplateValidator::fail("profile.chain_rules.$i.entry", '入口必须是节点或策略组。');
            foreach ($exits as $name) {
                $index = $proxyIndexes[$name];
                if (isset($config['proxies'][$index]['interface-name'])) SubscriptionTemplateValidator::fail("profile.chain_rules.$i", 'dialer-proxy 不能与 interface-name 同时使用。');
                $config['proxies'][$index]['dialer-proxy'] = $entry;
            }
        }

        $graph = []; $chainTargets = [];
        foreach ($config['proxies'] as &$proxy) {
            $graph[$proxy['name']] = [];
            if (isset($proxy['dialer-proxy'])) {
                if (!is_string($proxy['dialer-proxy']) || isset($proxy['interface-name'])) SubscriptionTemplateValidator::fail('profile.base_yaml', '代理链入口格式错误或与 interface-name 冲突。');
                $proxy['dialer-proxy'] = $resolve($proxy['dialer-proxy']);
                $graph[$proxy['name']][] = $proxy['dialer-proxy'];
                $chainTargets[] = $proxy['dialer-proxy'];
            }
        }
        unset($proxy);
        $providerGraph = $this->proxyProviderGraph($config['proxy-providers'], $resolve);
        $graph += $providerGraph['graph'];
        array_push($chainTargets, ...$providerGraph['chain_targets']);
        foreach ($groups as $name => &$group) {
            $group['proxies'] = array_values(array_unique(array_map(fn ($ref) => $resolve($ref), $group['proxies'])));
            $uses = $group['use'] ?? [];
            if (!empty($group['include-all']) || !empty($group['include-all-providers'])) $uses = array_values(array_unique(array_merge($uses, array_keys($config['proxy-providers']))));
            foreach ($uses as $provider) {
                if (!array_key_exists($provider, $config['proxy-providers'])) SubscriptionTemplateValidator::fail('profile.base_yaml', '策略组引用了不存在的远程代理集。');
            }
            $graph[$name] = $group['proxies'];
            foreach ($uses as $provider) {
                foreach ($providerGraph['members'][$provider] as $member) {
                    if ($member['proxy'] === null || $this->groupIncludesProxy($group, $member['proxy'])) $graph[$name][] = $member['key'];
                }
            }
            if (!empty($group['include-all']) || !empty($group['include-all-proxies'])) {
                $candidates = [];
                foreach ($config['proxies'] as $candidate) {
                    if ($this->groupIncludesProxy($group, $candidate)) $candidates[] = $candidate['name'];
                }
                $graph[$name] = array_values(array_unique(array_merge($graph[$name], $candidates)));
            }
        }
        unset($group);
        $this->secureEntryGroups($groups, $graph, $chainTargets, $providerGraph['remote_keys']);
        $this->checkCycles($graph);
        $config['proxy-groups'] = array_values($groups);
        $rules = array_merge($profile['custom_rules'], $providerRules, $generated['rules'], $profile['preset'] === 'original' ? $config['rules'] : []);
        if (!$rules || !preg_match('/^(MATCH|FINAL),/', end($rules))) $rules[] = 'MATCH,' . $policy;
        $config['rules'] = $this->replaceAppName($rules);
        $config = app(SubscriptionRuleValidator::class)->validate($config, $client, $resolve);
        $rules = $config['rules'];
        // getHost() strips ports; an IP literal needs CIDR rather than a DOMAIN rule.
        $host = trim(request()->getHost(), '[]');
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            array_unshift($rules, (str_contains($host, ':') ? 'IP-CIDR6,' : 'IP-CIDR,') . $host . (str_contains($host, ':') ? '/128' : '/32') . ',DIRECT,no-resolve');
        } elseif ($host !== '') array_unshift($rules, 'DOMAIN,' . $host . ',DIRECT');
        $config['rules'] = array_values(array_unique($rules));
        foreach (['rule-providers', 'proxy-providers'] as $key) if (!$config[$key]) unset($config[$key]);
        if ($client === 'stash' && is_array($config['dns'] ?? null)) {
            if (isset($config['dns']['respect-rules'])) {
                $config['dns']['follow-rule'] ??= $config['dns']['respect-rules'];
                unset($config['dns']['respect-rules']);
            }
            foreach ($config['dns']['nameserver-policy'] ?? [] as $key => $value) {
                if (is_array($value)) $config['dns']['nameserver-policy'][$key] = $value[0] ?? '';
            }
        }
        return $config;
    }

    public function templateKey(string $client): string
    {
        return match ($client) {
            'mihomo' => 'clashmeta', 'stash' => 'stash',
            default => SubscriptionTemplateValidator::fail('client', '仅支持 mihomo 与 Stash。'),
        };
    }

    private function expandNodes(array $group, array $nodeNames): array
    {
        $configured = $group['proxies'] ?? [];
        if (!$configured && empty($group['use']) && empty($group['include-all']) && empty($group['include-all-proxies']) && empty($group['include-all-providers'])) return $nodeNames ?: ['REJECT'];
        $result = [];
        foreach ($configured as $name) {
            if ($name === '$nodes') { array_push($result, ...$nodeNames); continue; }
            if ($this->isNodePattern($name)) {
                foreach ($nodeNames as $node) if (preg_match($name, $node) === 1) $result[] = $node;
            } elseif (strlen($name) >= 2 && $name[0] === '/' && strrpos($name, '/') > 0) {
                SubscriptionTemplateValidator::fail('profile.base_yaml', '节点筛选正则表达式无效。');
            } else $result[] = $name;
        }
        if (!$result && empty($group['use']) && empty($group['include-all']) && empty($group['include-all-proxies']) && empty($group['include-all-providers'])) $result = ['REJECT'];
        return array_values(array_unique($result));
    }

    private function addProvider(array &$providers, string $name, array $provider, string $field): void
    {
        if (isset($providers[$name])) {
            // Reusing an identical provider is safe; separate policies are expressed by rules.
            $keys = ['url', 'behavior', 'format'];
            foreach ($keys as $key) if (($providers[$name][$key] ?? null) !== ($provider[$key] ?? null)) SubscriptionTemplateValidator::fail($field, '规则集名称冲突，请更换名称。');
            if (isset($provider['interval'])) $providers[$name]['interval'] = $provider['interval'];
            return;
        }
        $providers[$name] = $provider;
    }

    private function validateProviders(array &$providers): void
    {
        $paths = [];
        foreach ($providers as $name => &$provider) {
            if (!is_string($name)) SubscriptionTemplateValidator::fail('profile.base_yaml', '规则集名称必须是文本。');
            $this->validator->name($name, 'profile.base_yaml');
            if (!is_array($provider) || !in_array($provider['behavior'] ?? null, ['domain', 'ipcidr', 'classical'], true)) SubscriptionTemplateValidator::fail('profile.base_yaml', '规则集需要有效的 behavior。');
            $format = $provider['format'] ?? 'yaml';
            if (!in_array($format, ['yaml', 'text', 'mrs'], true) || ($format === 'mrs' && $provider['behavior'] === 'classical')) SubscriptionTemplateValidator::fail('profile.base_yaml', '规则集格式与 behavior 不兼容。');
            if (isset($provider['url'])) {
                if (!is_string($provider['url'])) SubscriptionTemplateValidator::fail('profile.base_yaml', '规则集 URL 必须是文本。');
                $this->validator->url($provider['url'], 'profile.base_yaml');
            }
            $provider['type'] ??= isset($provider['url']) ? 'http' : (isset($provider['payload']) ? 'inline' : 'file');
            if (!in_array($provider['type'], ['http', 'file', 'inline'], true)) SubscriptionTemplateValidator::fail('profile.base_yaml', '规则集 type 仅支持 http、file 或 inline。');
            if ($provider['type'] === 'http') {
                if (empty($provider['url'])) SubscriptionTemplateValidator::fail('profile.base_yaml', '远程规则集缺少 URL。');
                $provider['path'] ??= './rules/provider-' . substr(hash('sha256', $name), 0, 16) . '.' . ($format === 'text' ? 'list' : $format);
                $provider['interval'] ??= 86400;
            }
            if ($provider['type'] === 'file' && empty($provider['path'])) SubscriptionTemplateValidator::fail('profile.base_yaml', '本地规则集缺少 path。');
            if ($provider['type'] === 'inline' && (!is_array($provider['payload'] ?? null) || !array_is_list($provider['payload']))) SubscriptionTemplateValidator::fail('profile.base_yaml', '内联规则集 payload 必须是列表。');
            if (isset($provider['interval']) && (!is_int($provider['interval']) || $provider['interval'] < 0)) SubscriptionTemplateValidator::fail('profile.base_yaml', '规则集 interval 必须是非负整数。');
            if (isset($provider['path'])) {
                if (!is_string($provider['path']) || preg_match('#(^/|(^|/)\.\.(/|$)|\\\\|\x00)#', $provider['path'])) SubscriptionTemplateValidator::fail('profile.base_yaml', '规则集缓存路径必须位于客户端配置目录内。');
                $path = preg_replace('#^(\./)+#', '', $provider['path']);
                if (isset($paths[$path])) SubscriptionTemplateValidator::fail('profile.base_yaml', '不同规则集不能共用缓存文件。');
                $paths[$path] = true;
            }
        }
        unset($provider);
    }

    private function groupIncludesProxy(array $group, array $proxy): bool
    {
        foreach (['filter' => true, 'exclude-filter' => false] as $field => $include) {
            if (empty($group[$field])) continue;
            if (!is_string($group[$field])) SubscriptionTemplateValidator::fail('profile.base_yaml', '策略组筛选条件必须是文本。');
            $pattern = '~' . str_replace('~', '\\~', $group[$field]) . '~u';
            $matches = @preg_match($pattern, $proxy['name']);
            if ($matches === false) SubscriptionTemplateValidator::fail('profile.base_yaml', '策略组筛选正则表达式无效。');
            if (($matches === 1) !== $include) return false;
        }
        if (isset($group['exclude-type'])) {
            if (!is_string($group['exclude-type'])) SubscriptionTemplateValidator::fail('profile.base_yaml', 'exclude-type 必须是逗号分隔的协议名称。');
            if (in_array($proxy['type'], array_map('trim', explode(',', $group['exclude-type'])), true)) return false;
        }
        return true;
    }

    /** Validate known provider dependencies without downloading third-party content. */
    private function proxyProviderGraph(array &$providers, callable $resolve): array
    {
        $graph = []; $members = []; $chainTargets = []; $remoteKeys = [];
        foreach ($providers as $name => &$provider) {
            if (!is_string($name) || !is_array($provider) || array_is_list($provider)) SubscriptionTemplateValidator::fail('profile.base_yaml', '代理集必须包含名称与配置映射。');
            $this->validator->name($name, 'profile.base_yaml');
            if (isset($provider['override']) && !is_array($provider['override'])) SubscriptionTemplateValidator::fail('profile.base_yaml', '代理集 override 必须是映射。');
            $members[$name] = [];
            $override = $provider['override']['dialer-proxy'] ?? null;
            if ($override !== null && !is_string($override)) SubscriptionTemplateValidator::fail('profile.base_yaml', '代理集的链路入口必须是文本引用。');
            if ($override !== null) $provider['override']['dialer-proxy'] = $resolve($override);
            $payload = $provider['payload'] ?? [];
            if (!is_array($payload) || !array_is_list($payload)) SubscriptionTemplateValidator::fail('profile.base_yaml', '代理集 payload 必须是节点列表。');
            if (($provider['type'] ?? null) === 'inline' && !$payload) SubscriptionTemplateValidator::fail('profile.base_yaml', '内联代理集不能没有节点。');
            if (isset($provider['url'])) {
                if (!is_string($provider['url'])) SubscriptionTemplateValidator::fail('profile.base_yaml', '远程代理集 URL 必须是文本。');
                $this->validator->url($provider['url'], 'profile.base_yaml');
            }
            $local = [];
            foreach ($payload as $index => $proxy) {
                if (!is_array($proxy) || !is_string($proxy['name'] ?? null) || !is_string($proxy['type'] ?? null)) SubscriptionTemplateValidator::fail('profile.base_yaml', '代理集每个节点都需要 name 和 type。');
                $this->validator->name($proxy['name'], 'profile.base_yaml');
                if (isset($local[$proxy['name']])) SubscriptionTemplateValidator::fail('profile.base_yaml', '同一代理集内的节点不能重名。');
                // NUL is forbidden in exported names; these keys only exist in the validation graph.
                $local[$proxy['name']] = "\0provider:$name:$index";
            }
            foreach ($payload as $index => $proxy) {
                $key = $local[$proxy['name']];
                $graph[$key] = [];
                $dialer = $override ?? $proxy['dialer-proxy'] ?? null;
                if ($dialer !== null) {
                    if (!is_string($dialer) || isset($proxy['interface-name']) || isset($provider['override']['interface-name'])) SubscriptionTemplateValidator::fail('profile.base_yaml', '代理集节点入口格式错误或与 interface-name 冲突。');
                    $graph[$key][] = $local[$dialer] ?? $resolve($dialer);
                    $chainTargets[] = $graph[$key][0];
                    if ($override === null) $provider['payload'][$index]['dialer-proxy'] = isset($local[$dialer]) ? $dialer : $resolve($dialer);
                }
                $members[$name][] = ['key' => $key, 'proxy' => $proxy];
            }
            if (($provider['type'] ?? null) !== 'inline') {
                $key = "\0provider:$name:remote";
                $graph[$key] = $override === null ? [] : [$resolve($override)];
                if ($override !== null) $chainTargets[] = $graph[$key][0];
                $remoteKeys[$key] = true;
                $members[$name][] = ['key' => $key, 'proxy' => null];
            }
        }
        unset($provider);
        return ['graph' => $graph, 'members' => $members, 'chain_targets' => $chainTargets, 'remote_keys' => $remoteKeys];
    }

    private function secureEntryGroups(array &$groups, array &$graph, array $targets, array $remoteKeys): void
    {
        $visited = []; $guardedRemote = false;
        $visit = function (string $name) use (&$visit, &$visited, &$groups, &$graph, $remoteKeys, &$guardedRemote): void {
            if (isset($visited[$name])) return;
            $visited[$name] = true;
            if (isset($groups[$name])) {
                if (empty($graph[$name])) SubscriptionTemplateValidator::fail('profile.chain_rules', '链路入口组没有可用节点，请检查成员授权、节点展示和筛选条件。');
                foreach ($graph[$name] as $member) {
                    if (!isset($remoteKeys[$member])) continue;
                    // Native empty groups fall back to COMPATIBLE/DIRECT. A remote
                    // provider can become empty after export, so retain a blocking member.
                    if (!in_array('REJECT', $groups[$name]['proxies'], true)) $groups[$name]['proxies'][] = 'REJECT';
                    if (!in_array('REJECT', $graph[$name], true)) $graph[$name][] = 'REJECT';
                    $guardedRemote = true;
                }
            }
            foreach ($graph[$name] ?? [] as $member) $visit($member);
        };
        foreach ($targets as $target) $visit($target);
        if ($guardedRemote && request()->attributes->has('subscription_template_preview')) {
            $preview = request()->attributes->get('subscription_template_preview');
            $preview['warnings'][] = '远程代理集入口组已加入 REJECT 兜底；select 组首次使用时，请在客户端选择实际入口节点。';
            request()->attributes->set('subscription_template_preview', $preview);
        }
    }

    private function migrateLegacyGroups(string $yaml): string
    {
        try { $config = Yaml::parse($yaml, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE); }
        catch (\Throwable) { return $yaml; }
        if (!is_array($config) || !is_array($config['proxy-groups'] ?? null)) return $yaml;
        $changed = false;
        foreach ($config['proxy-groups'] as &$group) {
            if (!is_array($group)) continue;
            $members = $group['proxies'] ?? [];
            if (!is_array($members) || !array_is_list($members)) continue;
            $explicit = false;
            foreach ($members as $member) {
                if (!is_string($member)) { $explicit = true; break; }
                if ($member === '$nodes' || $this->isNodePattern($member)) $explicit = true;
            }
            if (!$explicit) { $group['proxies'] = array_merge($members, ['$nodes']); $changed = true; }
        }
        unset($group);
        // Keep the original database bytes until an administrator saves the migrated draft.
        return $changed ? Yaml::dump($config, 20, 2) : $yaml;
    }

    private function isNodePattern(string $value): bool
    {
        return strlen($value) >= 2 && @preg_match($value, '') !== false;
    }

    private function checkCycles(array $graph): void
    {
        $state = [];
        $visit = function (string $name) use (&$visit, &$state, $graph): void {
            if (($state[$name] ?? 0) === 1) SubscriptionTemplateValidator::fail('profile.chain_rules', '代理链或策略组存在循环引用，请检查入口是否包含落地节点。');
            if (($state[$name] ?? 0) === 2) return;
            $state[$name] = 1;
            foreach ($graph[$name] ?? [] as $target) $visit($target);
            $state[$name] = 2;
        };
        foreach (array_keys($graph) as $name) $visit($name);
    }

    private function replaceAppName(mixed $value): mixed
    {
        if (is_string($value)) return str_replace('$app_name', admin_setting('app_name', 'SimpleXboard'), $value);
        if (!is_array($value)) return $value;
        $result = [];
        foreach ($value as $key => $item) $result[is_string($key) ? $this->replaceAppName($key) : $key] = $this->replaceAppName($item);
        return $result;
    }
}
