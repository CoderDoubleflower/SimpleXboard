<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;
use JsonException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/** Imports template settings only. Subscription sources are never fetched or retained. */
class SubscriptionTemplateImporter
{
    private const PROFILE_KEYS = [
        'version', 'base_yaml', 'preset', 'selected_rules', 'rule_providers', 'custom_rules',
        'chain_rules', 'default_policy', 'rule_format', 'rule_base_url',
    ];

    private const SOURCE_KEYS = [
        'selectedRules', 'customRules', 'dialerProxyRules', 'dialer_proxy_rules', 'dialerProxy',
        'dialer_proxy', 'baseConfig', 'base_config', 'configEditor', 'input', 'configId',
    ];

    /** @return array{profile: array, warnings: string[]} */
    public function import(string $content, array $currentProfile): array
    {
        $content = trim($content);
        if ($content === '' || strlen($content) > 1048576) {
            $this->fail('请提供不超过 1 MiB 的模板内容。');
        }
        $warnings = [];
        $currentProfile = array_intersect_key($currentProfile, array_flip(self::PROFILE_KEYS));

        if (preg_match('~\Ahttps?://~i', $content)) {
            $source = $this->parseConversionUrl($content);
            return $this->importSource($source, $currentProfile, $warnings);
        }

        try {
            $data = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // An explicitly pasted client YAML config is a base template, not an input subscription.
            $data = $this->parseYaml($content);
            if (!$this->isClientConfig($data)) {
                $this->fail('内容不是有效的模板 JSON、客户端 YAML 或 sublink-worker 完整转换链接。');
            }
            return $this->importBaseYaml($content, $currentProfile);
        }
        if (!is_array($data) || array_is_list($data)) {
            $this->fail('模板 JSON 必须是对象。');
        }
        if (array_key_exists('profile', $data)) {
            if (!is_array($data['profile']) || array_is_list($data['profile'])) {
                $this->fail('导出的 profile 必须是模板对象。');
            }
            return $this->importProfile($data['profile'], $currentProfile);
        }
        if (array_intersect(['base_yaml', 'preset', 'selected_rules', 'rule_providers', 'custom_rules', 'chain_rules', 'default_policy', 'rule_format', 'rule_base_url'], array_keys($data))) {
            return $this->importProfile($data, $currentProfile);
        }
        if (isset($data['config']) && is_array($data['config'])) {
            $data = $data['config'];
            if (array_is_list($data)) {
                $this->fail('sublink-worker 的 config 必须是配置对象。');
            }
        }
        if (array_intersect(self::SOURCE_KEYS, array_keys($data))) {
            return $this->importSource($data, $currentProfile, $warnings);
        }
        if ($this->isClientConfig($data)) {
            return $this->importBaseYaml(Yaml::dump($data, 20, 2), $currentProfile);
        }
        $this->fail('JSON 中没有可导入的模板设置。');
    }

    private function importProfile(array $data, array $current): array
    {
        if (isset($data['version']) && $data['version'] !== 1) {
            $this->fail('不支持此模板版本。');
        }
        $profile = array_replace($current, array_intersect_key($data, array_flip(self::PROFILE_KEYS)));
        foreach (['base_yaml', 'preset', 'default_policy', 'rule_format', 'rule_base_url'] as $key) {
            if (isset($profile[$key]) && !is_string($profile[$key])) {
                $this->fail("{$key} 必须是文本。");
            }
        }
        foreach (['selected_rules', 'rule_providers', 'custom_rules', 'chain_rules'] as $key) {
            if (!isset($profile[$key]) || !is_array($profile[$key]) || !array_is_list($profile[$key])) {
                $this->fail("{$key} 必须是数组。");
            }
        }
        if (!in_array($profile['preset'] ?? '', ['original', 'minimal', 'balanced', 'comprehensive', 'custom'], true)) {
            $this->fail('无法识别模板预设。');
        }
        $this->parseYaml($profile['base_yaml'] ?? '');
        SubscriptionRuleCatalog::generate($profile['selected_rules'], $profile['rule_format'] ?? 'yaml',
            $profile['rule_base_url'] ?? '', $profile['default_policy'] ?? '节点选择');
        foreach ($profile['custom_rules'] as $rule) {
            if (!is_string($rule) || trim($rule) === '' || preg_match('/[\r\n\x00]/', $rule)) {
                $this->fail('custom_rules 中每条规则必须为一行非空文本。');
            }
        }
        foreach ($profile['rule_providers'] as $provider) {
            if (!is_array($provider) || array_is_list($provider) || !is_string($provider['name'] ?? null)
                || !is_string($provider['url'] ?? null) || !in_array($provider['behavior'] ?? '', ['domain', 'ipcidr', 'classical'], true)
                || !in_array($provider['format'] ?? '', ['yaml', 'text', 'mrs'], true)
                || (($provider['format'] ?? '') === 'mrs' && ($provider['behavior'] ?? '') === 'classical')) {
                $this->fail('rule_providers 中存在不完整或不兼容的规则集。');
            }
        }
        foreach ($profile['chain_rules'] as $rule) {
            if (!is_array($rule) || !is_array($rule['exit_nodes'] ?? null) || !array_is_list($rule['exit_nodes'])
                || $rule['exit_nodes'] === [] || !is_string($rule['entry'] ?? null)) {
                $this->fail('chain_rules 中存在不完整的链式代理配置。');
            }
            $this->name($rule['entry']);
            foreach ($rule['exit_nodes'] as $node) {
                $this->name($node);
            }
        }
        return ['profile' => $profile, 'warnings' => []];
    }

    private function importBaseYaml(string $yaml, array $current): array
    {
        $profile = array_replace($current, [
            'version' => 1, 'base_yaml' => $yaml, 'preset' => 'original',
            'selected_rules' => [], 'rule_providers' => [], 'custom_rules' => [], 'chain_rules' => [],
        ]);
        return ['profile' => $profile, 'warnings' => ['已按完整 YAML 模板替换草稿并清空叠加设置，保存后才会应用到订阅。']];
    }

    private function parseConversionUrl(string $content): array
    {
        $url = parse_url($content);
        if (!is_array($url) || empty($url['host']) || !isset($url['query'])
            || !in_array(basename($url['path'] ?? ''), ['clash', 'mihomo', 'stash', 'singbox', 'subconverter'], true)) {
            $this->fail('仅能导入包含配置参数的完整转换链接；请在 sublink-worker 中展开短链接后再导入。');
        }
        parse_str($url['query'], $params);
        if (!array_intersect(array_merge(self::SOURCE_KEYS, ['config']), array_keys($params))) {
            $this->fail('转换链接中没有可导入的模板参数。');
        }
        return $params;
    }

    private function importSource(array $source, array $current, array $warnings): array
    {
        $profile = $current;
        $selected = $source['selectedRules'] ?? 'minimal';
        if (is_string($selected) && str_starts_with(ltrim($selected), '[')) {
            $selected = $this->jsonArray($selected, 'selectedRules');
        }
        if (is_string($selected)) {
            if (!isset(SubscriptionRuleCatalog::presets()[$selected])) {
                $this->fail('sublink-worker 的 selectedRules 预设无法识别。');
            }
            $profile['preset'] = $selected;
            $profile['selected_rules'] = SubscriptionRuleCatalog::presets()[$selected];
        } elseif (is_array($selected) && array_is_list($selected)) {
            $profile['preset'] = $selected === [] ? 'minimal' : 'custom';
            $profile['selected_rules'] = $selected === [] ? SubscriptionRuleCatalog::presets()['minimal'] : $selected;
        } else {
            $this->fail('selectedRules 必须是预设名称或分类名称数组。');
        }
        $profile['version'] = 1;
        $profile['rule_providers'] = [];
        $profile['custom_rules'] = [];
        $profile['chain_rules'] = [];
        $format = $profile['rule_format'] ?? 'yaml';
        $baseUrl = $profile['rule_base_url'] ?? 'https://raw.githubusercontent.com/MetaCubeX/meta-rules-dat/meta/geo';
        $policy = $this->name($profile['default_policy'] ?? '节点选择');
        SubscriptionRuleCatalog::generate($profile['selected_rules'], $format, $baseUrl, $policy);

        foreach (['baseConfig', 'base_config', 'configEditor'] as $key) {
            if (!isset($source[$key]) || $source[$key] === '') {
                continue;
            }
            if (is_array($source[$key]) && !array_is_list($source[$key])) {
                $profile['base_yaml'] = Yaml::dump($source[$key], 20, 2);
            } elseif (is_string($source[$key])) {
                $this->parseYaml($source[$key]);
                $profile['base_yaml'] = $source[$key];
            } else {
                $this->fail('sublink-worker 基础模板必须是 YAML 文本或配置对象。');
            }
            break;
        }
        $base = $this->parseYaml($profile['base_yaml'] ?? '');
        $groups = $base['proxy-groups'] ?? [];
        if (!is_array($groups) || !array_is_list($groups)) {
            $this->fail('基础模板的 proxy-groups 必须是数组。');
        }
        if (isset($base['proxies']) && (!is_array($base['proxies']) || !array_is_list($base['proxies']))) {
            $this->fail('基础模板的 proxies 必须是数组。');
        }
        $groupNames = array_column($groups, 'name');
        $generatedNames = $profile['selected_rules'];
        $providers = [];

        foreach ($this->jsonArray($source['customRules'] ?? [], 'customRules') as $item) {
            if (!is_array($item) || array_is_list($item)) {
                $this->fail('customRules 中每条自定义规则必须是对象。');
            }
            $name = $this->name($item['name'] ?? null);
            if ($this->strings($item['protocol'] ?? []) !== []) {
                $this->fail('sublink-worker 的 protocol 条件无法无损转换为 mihomo / Stash 规则，请移除该条件后用原生规则明确配置。');
            }
            if (!in_array($name, array_merge(['DIRECT', 'REJECT', $policy], $groupNames, $generatedNames), true)) {
                $groups[] = ['name' => $name, 'type' => 'select', 'proxies' => array_values(array_unique([$policy, 'DIRECT', 'REJECT']))];
                $groupNames[] = $name;
            }
            $before = count($profile['custom_rules']);
            foreach (['src_ip_cidr' => 'SRC-IP-CIDR', 'domain_suffix' => 'DOMAIN-SUFFIX', 'domain_keyword' => 'DOMAIN-KEYWORD'] as $key => $type) {
                foreach ($this->strings($item[$key] ?? []) as $value) {
                    if ($key === 'src_ip_cidr') {
                        $value = $this->cidr($value);
                    }
                    $profile['custom_rules'][] = "{$type},{$value},{$name}";
                }
            }
            foreach (['site' => 'domain', 'ip' => 'ipcidr'] as $key => $behavior) {
                foreach ($this->strings($item[$key] ?? []) as $id) {
                    $provider = SubscriptionRuleCatalog::provider($id, $behavior, $format, $baseUrl);
                    $providerName = $behavior === 'ipcidr' ? $id . '-ip' : $id;
                    $editable = array_intersect_key($provider, array_flip(['url', 'behavior', 'format', 'interval']));
                    $editable = ['name' => $providerName, ...$editable, 'policy' => $name];
                    if (isset($providers[$providerName]) && ($providers[$providerName]['behavior'] !== $behavior
                        || $providers[$providerName]['url'] !== $editable['url'])) {
                        $this->fail('自定义域名规则集与 IP 规则集名称冲突，请修改标识。');
                    }
                    // Keep the first policy; every use also has an ordered, explicit rule below.
                    $providers[$providerName] ??= $editable;
                    $profile['custom_rules'][] = "RULE-SET,{$providerName},{$name}" . ($behavior === 'ipcidr' ? ',no-resolve' : '');
                }
            }
            foreach ($this->strings($item['ip_cidr'] ?? []) as $value) {
                $value = $this->cidr($value);
                $type = str_contains($value, ':') ? 'IP-CIDR6' : 'IP-CIDR';
                $profile['custom_rules'][] = "{$type},{$value},{$name},no-resolve";
            }
            if ($before === count($profile['custom_rules'])) {
                $this->fail('每条 customRules 至少需要一个有效的匹配条件。');
            }
        }
        $profile['rule_providers'] = array_values($providers);

        $winningEntries = [];
        foreach ($this->jsonArray($source['dialerProxyRules'] ?? $source['dialer_proxy_rules'] ?? [], 'dialerProxyRules') as $rule) {
            if (!is_array($rule) || array_is_list($rule) || !is_array($rule['proxyNames'] ?? null)
                || !array_is_list($rule['proxyNames']) || $rule['proxyNames'] === []) {
                $this->fail('每条链式代理规则必须包含非空的 proxyNames 数组和 target。');
            }
            $entry = $this->sourceEntry($this->name($rule['target'] ?? null), $groups,
                array_column($base['proxies'] ?? [], 'name'), $policy);
            foreach ($rule['proxyNames'] as $node) {
                $node = $this->name($node);
                if ($node === $entry) {
                    $this->fail('链式代理入口和落地节点不能相同。');
                }
                if (isset($winningEntries[$node]) && $winningEntries[$node] !== $entry) {
                    $warnings[] = '同一落地节点存在多条链式代理设置，已按 sublink-worker 的规则保留最后一条。';
                }
                $winningEntries[$node] = $entry;
            }
        }
        $chainGroups = [];
        foreach ($winningEntries as $node => $entry) {
            $chainGroups[$entry][] = (string) $node;
        }
        foreach ($chainGroups as $entry => $nodes) {
            $profile['chain_rules'][] = ['exit_nodes' => $nodes, 'entry' => (string) $entry];
        }
        if ($groups !== ($base['proxy-groups'] ?? [])) {
            $base['proxy-groups'] = $groups;
            $profile['base_yaml'] = Yaml::dump($base, 20, 2);
        }

        foreach (['config', 'input', 'urls', 'subscriptions', 'subscription'] as $key) {
            if (isset($source[$key]) && $source[$key] !== '' && $source[$key] !== []) {
                $warnings[] = '原始订阅地址及输入节点未导入；订阅中的节点仍由 SimpleXboard 的成员授权生成。';
                break;
            }
        }
        if (!empty($source['configId'])) {
            $warnings[] = '远程 configId 对应的基础模板未下载，请另行粘贴该 YAML 模板。';
        }
        if (!empty($source['dialerProxy']) || !empty($source['dialer_proxy'])) {
            $warnings[] = '全局 dialer-proxy 未导入，请在链式代理中明确选择落地节点和入口。';
        }
        if (in_array($source['groupByCountry'] ?? $source['group_by_country'] ?? false, [true, 'true', 1], true)) {
            $warnings[] = '按国家自动分组未导入；可以在基础 YAML 中添加需要的策略组。';
        }
        if (in_array($source['enableClashUI'] ?? $source['enable_clash_ui'] ?? false, [true, 'true', 1], true)
            || !empty($source['external_controller']) || !empty($source['external_ui_download_url'])) {
            $warnings[] = '外部控制器和 Dashboard 参数未导入。';
        }
        return ['profile' => $profile, 'warnings' => array_values(array_unique($warnings))];
    }

    /** Source-generated labels are aliases; explicitly authored groups/proxies retain their identity. */
    private function sourceEntry(string $entry, array &$groups, array $staticNames, string $policy): string
    {
        $groupNames = array_column($groups, 'name');
        if (in_array($entry, $groupNames, true) || in_array($entry, $staticNames, true)) {
            return $entry;
        }
        // Labels from sublink-worker/src/i18n/index.js; see LICENSES/sublink-worker.txt.
        $aliases = [
            'Node Select' => ['🚀 节点选择', '🚀 Node Select', '🚀 انتخاب نود', '🚀 Выбор узла', '节点选择'],
            'Auto Select' => ['⚡ 自动选择', '⚡ Auto Select', '⚡ انتخاب خودکار', '⚡ Автовыбор', '自动选择'],
            'Manual Switch' => ['🖐️ 手动切换', '🖐️ Manual Switch', '🖐️ Ручной выбор', '手动切换'],
            'Fall Back' => ['🐟 漏网之鱼', '🐟 Fall Back', '🐟 فال بک', '🐟 Резерв', '漏网之鱼'],
            'Ad Block' => ['🛑 广告拦截', '🛑 Ad Blocking', '🛑 مسدودسازی تبلیغات', '🛑 Блокировка рекламы'],
            'AI Services' => ['💬 AI 服务', '💬 AI Services', '💬 سرویس‌های هوش مصنوعی', '💬 AI-сервисы'],
            'Bilibili' => ['📺 哔哩哔哩', '📺 Bilibili', '📺 بیلی‌بیلی'],
            'Youtube' => ['📹 油管视频', '📹 Youtube', '📹 YouTube', '📹 یوتیوب'],
            'Google' => ['🔍 谷歌服务', '🔍 Google Services', '🔍 سرویس‌های گوگل', '🔍 Сервисы Google'],
            'Private' => ['🏠 私有网络', '🏠 Private Network', '🏠 شبکه خصوصی', '🏠 Локальная сеть'],
            'Location:CN' => ['🔒 国内服务', '🔒 China Services', '🔒 سرویس‌های چین', '🔒 Сервисы Китая'],
            'Telegram' => ['📲 电报消息', '📲 Telegram', '📲 تلگرام'],
            'Github' => ['🐱 Github', '🐱 GitHub', '🐱 گیت‌هاب'],
            'Microsoft' => ['Ⓜ️ 微软服务', 'Ⓜ️ Microsoft Services', 'Ⓜ️ سرویس‌های مایکروسافت', 'Ⓜ️ Сервисы Microsoft'],
            'Apple' => ['🍏 苹果服务', '🍏 Apple Services', '🍏 سرویس‌های اپل', '🍏 Сервисы Apple'],
            'Social Media' => ['🌐 社交媒体', '🌐 Social Media', '🌐 شبکه‌های اجتماعی', '🌐 Социальные сети'],
            'Streaming' => ['🎬 流媒体', '🎬 Streaming', '🎬 استریمینگ', '🎬 Стриминг'],
            'Gaming' => ['🎮 游戏平台', '🎮 Gaming Platform', '🎮 پلتفرم بازی', '🎮 Игровые платформы'],
            'Education' => ['📚 教育资源', '📚 Education Resources', '📚 منابع آموزشی', '📚 Образовательные ресурсы'],
            'Financial' => ['💰 金融服务', '💰 Financial Services', '💰 سرویس‌های مالی', '💰 Финансовые сервисы'],
            'Cloud Services' => ['☁️ 云服务', '☁️ Cloud Services', '☁️ سرویس‌های ابری', '☁️ Облачные сервисы'],
            'Non-China' => ['🌐 非中国', '🌐 Non-China', '🌐 خارج از چین', '🌐 За пределами Китая'],
        ];
        foreach (SubscriptionRuleCatalog::categories() as $category) {
            $aliases[$category['name']][] = $category['label'];
        }
        $canonical = null;
        foreach ($aliases as $name => $labels) {
            if ($entry === $name || in_array($entry, $labels, true)) {
                $canonical = $name;
                break;
            }
        }
        if ($canonical === null) {
            return $entry;
        }
        if ($canonical === 'Node Select') {
            return $policy;
        }
        $common = ['Auto Select' => '自动选择', 'Manual Switch' => '手动切换', 'Fall Back' => '漏网之鱼'];
        if (!isset($common[$canonical])) {
            return $canonical;
        }
        $name = $common[$canonical];
        // A pre-existing canonical group is also authoritative. Do not replace its members.
        if (in_array($name, $groupNames, true) || in_array($name, $staticNames, true)) {
            return $name;
        }
        if ($canonical === 'Auto Select') {
            $groups[] = ['name' => $name, 'type' => 'url-test', 'proxies' => ['$nodes'],
                'url' => 'https://www.gstatic.com/generate_204', 'interval' => 300];
        } elseif ($canonical === 'Manual Switch') {
            $groups[] = ['name' => $name, 'type' => 'select', 'proxies' => ['$nodes']];
        } else {
            $groups[] = ['name' => $name, 'type' => 'select',
                'proxies' => array_values(array_unique([$policy, '$nodes', 'DIRECT', 'REJECT']))];
        }
        return $name;
    }

    private function jsonArray(mixed $value, string $field): array
    {
        if (is_string($value)) {
            try {
                $value = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $this->fail("{$field} 不是有效的 JSON 数组。");
            }
        }
        if (!is_array($value) || !array_is_list($value)) {
            $this->fail("{$field} 必须是数组。");
        }
        return $value;
    }

    private function strings(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value) || !array_is_list($value)) {
            $this->fail('自定义规则字段必须是文本数组或逗号分隔的文本。');
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_string($item) || preg_match('/[\r\n\x00-\x1f\x7f,]/', $item)) {
                $this->fail('自定义规则值包含无效字符。');
            }
            if (trim($item) !== '') {
                $result[] = trim($item);
            }
        }
        return array_values(array_unique($result));
    }

    private function name(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > 256 || preg_match('/[,\x00-\x1f\x7f]/', $value)) {
            $this->fail('策略组和节点名称必须是非空文本，不能包含逗号或控制字符。');
        }
        return trim($value);
    }

    private function cidr(string $value): string
    {
        $parts = explode('/', $value);
        $ip = $parts[0];
        if (count($parts) > 2 || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $this->fail('自定义规则中存在无效的 IP 或 CIDR。');
        }
        $max = str_contains($ip, ':') ? 128 : 32;
        $prefix = $parts[1] ?? (string) $max;
        if (!ctype_digit($prefix) || (int) $prefix > $max) {
            $this->fail('自定义规则中存在无效的 CIDR 前缀。');
        }
        return $ip . '/' . (int) $prefix;
    }

    private function parseYaml(string $text): array
    {
        try {
            $value = Yaml::parse($text, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException) {
            $this->fail('基础 YAML 无法解析，请检查缩进、重复键和数据类型。');
        }
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            $this->fail('基础 YAML 顶层必须是配置对象。');
        }
        return $value;
    }

    private function isClientConfig(array $data): bool
    {
        return array_intersect(['proxies', 'proxy-groups', 'rules', 'rule-providers', 'dns', 'mode'], array_keys($data)) !== [];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['content' => $message]);
    }
}
