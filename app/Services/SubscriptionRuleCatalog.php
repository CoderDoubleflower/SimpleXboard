<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/**
 * Rule categories ported from 954310195/sublink-worker at 81146e682849377cb631b663a119ade3898d0659.
 * Copyright (c) 2024 7Sageer. MIT license: LICENSES/sublink-worker.txt.
 */
class SubscriptionRuleCatalog
{
    public static function categories(): array
    {
        // Local destinations come first; broad China / non-China categories come last.
        return [
            self::category('Private', '局域网', [], ['private'], 'DIRECT'),
            self::category('Ad Block', '广告拦截', ['category-ads-all'], [], 'REJECT'),
            self::category('AI Services', 'AI 服务', ['category-ai-!cn']),
            self::category('Bilibili', '哔哩哔哩', ['bilibili']),
            self::category('Youtube', 'YouTube', ['youtube']),
            self::category('Google', 'Google', ['google'], ['google']),
            self::category('Telegram', 'Telegram', [], ['telegram']),
            self::category('Github', 'GitHub / GitLab', ['github', 'gitlab']),
            self::category('Microsoft', '微软', ['microsoft']),
            self::category('Apple', 'Apple', ['apple']),
            self::category('Social Media', '社交媒体', ['facebook', 'instagram', 'twitter', 'tiktok', 'linkedin']),
            self::category('Streaming', '流媒体', ['netflix', 'hulu', 'disney', 'hbo', 'amazon', 'bahamut']),
            self::category('Gaming', '游戏', ['steam', 'epicgames', 'ea', 'ubisoft', 'blizzard']),
            self::category('Education', '教育', ['coursera', 'edx', 'udemy', 'khanacademy', 'category-scholar-!cn']),
            self::category('Financial', '金融服务', ['paypal', 'visa', 'mastercard', 'stripe', 'wise']),
            self::category('Cloud Services', '云服务', ['aws', 'azure', 'digitalocean', 'heroku', 'dropbox']),
            self::category('Location:CN', '中国大陆', ['geolocation-cn', 'cn'], ['cn'], 'DIRECT'),
            self::category('Non-China', '境外网站', ['geolocation-!cn']),
        ];
    }

    public static function presets(): array
    {
        return [
            'minimal' => ['Private', 'Location:CN', 'Non-China'],
            'balanced' => ['Private', 'Github', 'Google', 'Youtube', 'AI Services', 'Telegram', 'Location:CN', 'Non-China'],
            'comprehensive' => array_column(self::categories(), 'name'),
        ];
    }

    /** @return array{providers: array, rules: string[], groups: array} */
    public static function generate(array $selected, string $format, string $baseUrl, string $defaultPolicy): array
    {
        $known = array_column(self::categories(), 'name');
        foreach ($selected as $name) {
            if (!is_string($name) || !in_array($name, $known, true)) {
                throw ValidationException::withMessages(['selected_rules' => '包含无法识别的规则分类。']);
            }
        }
        self::validateSource($format, $baseUrl);

        $providers = [];
        $rules = [];
        $groups = [];
        foreach (self::categories() as $category) {
            if (!in_array($category['name'], $selected, true)) {
                continue;
            }
            $name = $category['name'];
            $first = in_array($category['default_policy'], ['DIRECT', 'REJECT'], true)
                ? $category['default_policy'] : $defaultPolicy;
            $members = array_values(array_unique([$first, $defaultPolicy, 'DIRECT', 'REJECT']));
            $groups[] = [
                'name' => $name,
                'type' => 'select',
                'proxies' => array_values(array_filter($members, fn ($member) => $member !== $name)),
            ];
            foreach (['site_rules' => 'domain', 'ip_rules' => 'ipcidr'] as $key => $behavior) {
                foreach ($category[$key] as $id) {
                    $providerName = $behavior === 'ipcidr' ? $id . '-ip' : $id;
                    $provider = self::provider($id, $behavior, $format, $baseUrl);
                    if (isset($providers[$providerName]) && $providers[$providerName] !== $provider) {
                        throw ValidationException::withMessages(['selected_rules' => '域名规则集与 IP 规则集名称冲突。']);
                    }
                    $providers[$providerName] = $provider;
                    $rules[] = "RULE-SET,{$providerName},{$name}" . ($behavior === 'ipcidr' ? ',no-resolve' : '');
                }
            }
        }

        return ['providers' => $providers, 'rules' => $rules, 'groups' => $groups];
    }

    /** A safe source ID is used in both the URL path and the client cache filename. */
    public static function provider(string $id, string $behavior, string $format, string $baseUrl): array
    {
        if (!preg_match('/\A[A-Za-z0-9_!-]+(?:\.[A-Za-z0-9_!-]+)*\z/D', $id) || strlen($id) > 128) {
            throw ValidationException::withMessages(['content' => '规则集标识只能包含字母、数字、下划线、短横线、感叹号及名称内部的单个句点。']);
        }
        if (!in_array($behavior, ['domain', 'ipcidr'], true)) {
            throw ValidationException::withMessages(['content' => '内置规则集仅支持 domain 或 ipcidr。']);
        }
        self::validateSource($format, $baseUrl);

        $directory = $behavior === 'domain' ? 'geosite' : 'geoip';
        $name = $behavior === 'domain' ? $id : $id . '-ip';
        return [
            'type' => 'http',
            'behavior' => $behavior,
            'format' => $format,
            'url' => rtrim($baseUrl, '/') . "/{$directory}/{$id}.{$format}",
            'path' => "./rules/{$name}.{$format}",
            'interval' => 86400,
        ];
    }

    private static function category(string $name, string $label, array $sites, array $ips = [], string $policy = '节点选择'): array
    {
        return ['name' => $name, 'label' => $label, 'site_rules' => $sites, 'ip_rules' => $ips, 'default_policy' => $policy];
    }

    private static function validateSource(string $format, string $baseUrl): void
    {
        if (!in_array($format, ['yaml', 'mrs'], true)) {
            throw ValidationException::withMessages(['rule_format' => '内置规则集格式只能是 yaml 或 mrs。']);
        }
        $url = parse_url($baseUrl);
        if (!is_array($url) || !in_array($url['scheme'] ?? '', ['http', 'https'], true)
            || empty($url['host']) || isset($url['query']) || isset($url['fragment'])
            || isset($url['user']) || isset($url['pass']) || preg_match('/[\s\x00-\x1f\x7f]/', $baseUrl)) {
            throw ValidationException::withMessages(['rule_base_url' => '规则集数据源应为不含认证信息、查询参数及片段的 HTTP(S) 基础地址。']);
        }
    }
}
