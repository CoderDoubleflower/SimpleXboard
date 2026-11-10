<?php

namespace App\Services;

/** Validate references in composed rules, logical conditions and mihomo sub-rules. */
class SubscriptionRuleValidator
{
    private const MAX_DEPTH = 32;
    private const MAX_RULES = 5000;

    public function __construct(private SubscriptionTemplateValidator $validator) {}

    /** Return the same configuration with policy references normalized by the caller. */
    public function validate(array $config, string $client, callable $resolve): array
    {
        $rules = $config['rules'] ?? [];
        $subRules = $config['sub-rules'] ?? [];
        $providers = $config['rule-providers'] ?? [];
        if (!is_array($rules) || !array_is_list($rules)) {
            SubscriptionTemplateValidator::fail('profile.rules', '规则必须是有序列表。');
        }
        if (!is_array($subRules) || ($subRules !== [] && array_is_list($subRules))) {
            SubscriptionTemplateValidator::fail('profile.base_yaml', 'sub-rules 必须是名称与规则列表的映射。');
        }
        if ($subRules !== [] && $client !== 'mihomo') {
            SubscriptionTemplateValidator::fail('profile.base_yaml', 'sub-rules 仅支持 mihomo。');
        }
        if (!is_array($providers) || ($providers !== [] && array_is_list($providers))) {
            SubscriptionTemplateValidator::fail('profile.base_yaml', 'rule-providers 必须是映射。');
        }
        $count = count($rules);
        foreach ($subRules as $name => $list) {
            $this->validator->name((string) $name, 'profile.base_yaml');
            if (!is_array($list) || !array_is_list($list)) {
                SubscriptionTemplateValidator::fail('profile.base_yaml', '每个子规则必须是有序规则列表。');
            }
            $count += count($list);
        }
        if ($count > self::MAX_RULES) {
            SubscriptionTemplateValidator::fail('profile.rules', '模板与子规则合计不能超过 5000 条规则。');
        }

        $subNames = array_fill_keys(array_keys($subRules), true);
        $edges = array_fill_keys(array_keys($subRules), []);
        $config['rules'] = $this->normalizeList($rules, $client, $resolve, $providers, $subNames, $edges, null);
        foreach ($subRules as $name => $list) {
            $config['sub-rules'][$name] = $this->normalizeList($list, $client, $resolve, $providers, $subNames, $edges, (string) $name);
        }
        $this->checkCycles($edges);
        return $config;
    }

    private function normalizeList(array $rules, string $client, callable $resolve, array $providers,
        array $subNames, array &$edges, ?string $owner): array
    {
        $result = [];
        foreach ($rules as $index => $rule) {
            $field = $owner === null ? "profile.rules.$index" : 'profile.base_yaml.sub-rules';
            $parts = $this->validator->rule($rule, $client, $field);
            $type = $parts[0];
            $terminal = in_array($type, ['MATCH', 'FINAL'], true);
            if ($terminal && $index !== array_key_last($rules)) {
                SubscriptionTemplateValidator::fail($field, 'MATCH / FINAL 必须是该规则列表的最后一条。');
            }
            if (in_array($type, ['AND', 'OR', 'NOT'], true)) {
                $parts[1] = $this->logical($type, $parts[1], $client, $providers, $field, 0);
            } elseif ($type === 'SUB-RULE') {
                $parts[1] = $this->condition($parts[1], $client, $providers, $field, 0);
            } elseif ($type === 'RULE-SET') {
                $this->requireProvider($parts[1], $providers, $field);
            }

            $targetIndex = $terminal ? 1 : 2;
            if ($type === 'SUB-RULE') {
                if (!isset($subNames[$parts[$targetIndex]])) {
                    SubscriptionTemplateValidator::fail($field, 'SUB-RULE 引用了不存在的子规则。');
                }
                if ($owner !== null) {
                    $edges[$owner][] = $parts[$targetIndex];
                }
            } else {
                $target = $resolve($parts[$targetIndex]);
                if (!is_string($target) || $target === '') {
                    SubscriptionTemplateValidator::fail($field, '规则引用了不可用的节点或策略组。');
                }
                $parts[$targetIndex] = $target;
            }
            $result[] = implode(',', $parts);
        }
        return $result;
    }

    private function logical(string $type, string $payload, string $client, array $providers, string $field, int $depth): string
    {
        $this->depth($depth, $field);
        $children = $this->split($this->unwrap($payload, $field), $field);
        if ($children === [] || ($type === 'NOT' && count($children) !== 1)) {
            SubscriptionTemplateValidator::fail($field, '逻辑规则需要匹配条件；NOT 只能包含一个条件。');
        }
        $normalized = [];
        foreach ($children as $condition) {
            $normalized[] = $this->condition($condition, $client, $providers, $field, $depth + 1);
        }
        return '(' . implode(',', $normalized) . ')';
    }

    private function condition(string $condition, string $client, array $providers, string $field, int $depth): string
    {
        $this->depth($depth, $field);
        $parts = $this->split($this->unwrap($condition, $field), $field);
        $type = $parts[0] ?? '';
        if (in_array($type, ['MATCH', 'FINAL', 'SUB-RULE'], true)) {
            // mihomo rejects terminal and dispatch rules inside a logical condition.
            SubscriptionTemplateValidator::fail($field, '逻辑条件不能包含 MATCH、FINAL 或 SUB-RULE。');
        }
        if (count($parts) < 2) {
            SubscriptionTemplateValidator::fail($field, '逻辑条件缺少规则类型或匹配内容。');
        }
        // Reuse primitive syntax/option validation without interpreting regex payload text.
        $withPolicy = [$parts[0], $parts[1], 'DIRECT', ...array_slice($parts, 2)];
        $this->validator->rule(implode(',', $withPolicy), $client, $field, false);
        if (in_array($type, ['AND', 'OR', 'NOT'], true)) {
            $parts[1] = $this->logical($type, $parts[1], $client, $providers, $field, $depth);
        } elseif ($type === 'RULE-SET') {
            $this->requireProvider($parts[1], $providers, $field);
        }
        return '(' . implode(',', $parts) . ')';
    }

    private function requireProvider(string $name, array $providers, string $field): void
    {
        if (!array_key_exists($name, $providers)) {
            SubscriptionTemplateValidator::fail($field, 'RULE-SET 引用了不存在的规则集。');
        }
    }

    private function unwrap(string $text, string $field): string
    {
        $text = trim($text);
        if (strlen($text) < 3 || $text[0] !== '(' || $text[strlen($text) - 1] !== ')') {
            SubscriptionTemplateValidator::fail($field, '逻辑规则的匹配条件必须使用成对括号包裹。');
        }
        $depth = 0;
        $escaped = false;
        for ($i = 0, $length = strlen($text); $i < $length; $i++) {
            if ($escaped) { $escaped = false; continue; }
            if ($text[$i] === '\\') { $escaped = true; continue; }
            if ($text[$i] === '(') $depth++;
            if ($text[$i] === ')') $depth--;
            if ($depth === 0 && $i !== $length - 1) {
                SubscriptionTemplateValidator::fail($field, '逻辑规则括号范围错误。');
            }
            if ($depth < 0) SubscriptionTemplateValidator::fail($field, '逻辑规则括号不匹配。');
        }
        if ($depth !== 0) SubscriptionTemplateValidator::fail($field, '逻辑规则括号不匹配。');
        return trim(substr($text, 1, -1));
    }

    private function split(string $text, string $field): array
    {
        $parts = []; $depth = 0; $start = 0; $escaped = false;
        for ($i = 0, $length = strlen($text); $i < $length; $i++) {
            if ($escaped) { $escaped = false; continue; }
            if ($text[$i] === '\\') { $escaped = true; continue; }
            if ($text[$i] === '(') $depth++;
            if ($text[$i] === ')') $depth--;
            if ($depth < 0) SubscriptionTemplateValidator::fail($field, '逻辑规则括号不匹配。');
            if ($text[$i] === ',' && $depth === 0) {
                $parts[] = trim(substr($text, $start, $i - $start));
                $start = $i + 1;
            }
        }
        if ($depth !== 0) SubscriptionTemplateValidator::fail($field, '逻辑规则括号不匹配。');
        $parts[] = trim(substr($text, $start));
        if (in_array('', $parts, true)) SubscriptionTemplateValidator::fail($field, '逻辑规则包含空的匹配条件。');
        return $parts;
    }

    private function depth(int $depth, string $field): void
    {
        if ($depth > self::MAX_DEPTH) {
            SubscriptionTemplateValidator::fail($field, '逻辑规则嵌套不能超过 32 层。');
        }
    }

    private function checkCycles(array $graph): void
    {
        $state = [];
        $visit = function (string $name, int $depth) use (&$visit, &$state, $graph): void {
            if (($state[$name] ?? 0) === 1) {
                SubscriptionTemplateValidator::fail('profile.base_yaml', 'sub-rules 存在循环引用。');
            }
            if (($state[$name] ?? 0) === 2) return;
            $this->depth($depth, 'profile.base_yaml');
            $state[$name] = 1;
            foreach ($graph[$name] ?? [] as $target) $visit($target, $depth + 1);
            $state[$name] = 2;
        };
        foreach (array_keys($graph) as $name) $visit((string) $name, 0);
    }
}
