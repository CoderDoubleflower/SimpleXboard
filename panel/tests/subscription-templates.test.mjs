import test from 'node:test';
import assert from 'node:assert/strict';
import { SUBSCRIPTION_CLIENTS, copyProfile, rememberTemplate, ruleLines, referenceLines, selectedCategories, newRuleProvider, referenceLabel, chainTargetOptions, templateExport, templatePayload } from '../src/subscription-templates.js';

const profile = () => ({ version: 1, base_yaml: 'proxies: []\nproxy-groups: []\nrules:\n  - MATCH,DIRECT\n', preset: 'original', selected_rules: [], default_policy: '节点选择', rule_format: 'yaml', rule_base_url: 'https://example.test/geo', rule_providers: [], custom_rules: [], chain_rules: [] });

test('only mihomo and Stash are exposed and each draft owns its nested configuration', () => {
  assert.deepEqual(SUBSCRIPTION_CLIENTS.map(client => client.value), ['mihomo', 'stash']);
  const original = { ...profile(), chain_rules: [{ exit_nodes: ['node:2'], entry: 'node:1' }] };
  const draft = copyProfile(original);
  draft.chain_rules[0].exit_nodes.push('node:3');
  assert.deepEqual(original.chain_rules[0].exit_nodes, ['node:2']);
});

test('late initial-load responses cannot overwrite an existing draft after rapid client switches', () => {
  const records = new Map();
  const mihomo = rememberTemplate(records, 'mihomo', { profile: profile() });
  mihomo.draft.custom_rules.push('DOMAIN,work.example,DIRECT');
  const stash = rememberTemplate(records, 'stash', { profile: profile() });
  stash.draft.default_policy = 'DIRECT';
  const late = rememberTemplate(records, 'mihomo', { profile: profile() });
  assert.equal(late, mihomo);
  assert.deepEqual(late.draft.custom_rules, ['DOMAIN,work.example,DIRECT']);
  assert.deepEqual(late.original.custom_rules, []);
  assert.equal(records.get('stash').draft.default_policy, 'DIRECT');
});

test('native rule parsing preserves priority, logic expressions and inline characters', () => {
  const lines = ruleLines(' # Explanation\r\n DOMAIN-SUFFIX,example.com,工作组 \r\n\r\nAND,((NETWORK,TCP),(DST-PORT,443)),节点选择\nDOMAIN-REGEX,^a#b$,DIRECT');
  assert.deepEqual(lines, ['DOMAIN-SUFFIX,example.com,工作组', 'AND,((NETWORK,TCP),(DST-PORT,443)),节点选择', 'DOMAIN-REGEX,^a#b$,DIRECT']);
  assert.deepEqual(referenceLines('proxy:US\nnode:2\nproxy:US\n# skip'), ['proxy:US', 'node:2']);
});

test('category selection honors explicit custom emptiness and leaves presets immutable', () => {
  const presets = { balanced: ['Private', 'Google'] };
  assert.deepEqual(selectedCategories({ preset: 'original', selected_rules: ['Google'] }, presets), []);
  assert.deepEqual(selectedCategories({ preset: 'custom', selected_rules: [] }, presets), []);
  const selected = selectedCategories({ preset: 'balanced' }, presets);
  selected.push('Youtube');
  assert.deepEqual(presets.balanced, ['Private', 'Google']);
});

test('stable node references survive renamed and unavailable nodes without silently switching', () => {
  assert.equal(referenceLabel('node:2', [{ id: 2, ref: 'node:2', name: '[vless]新名称' }]), '[vless]新名称 · #2');
  assert.equal(referenceLabel('node:99', []), '节点 #99（当前不可用）');
  assert.equal(referenceLabel('proxy:外部出口'), '外部出口（模板节点）');
  assert.equal(referenceLabel('group:入口'), '入口（策略组）');
});

test('chain targets keep template proxies distinct from groups and omit builtins', () => {
  const metadata = { nodes: [{ id: 1, name: '入口', enabled: false }], chain_targets: [
    { ref: 'proxy:美国落地', name: '美国落地', type: 'proxy' },
    { ref: 'group:选择入口', name: '选择入口', type: 'group' },
    { ref: 'DIRECT', name: 'DIRECT', type: 'unknown' },
    { ref: 'REJECT-DROP', name: 'REJECT-DROP', type: 'unknown' },
  ], presets: { minimal: ['Private', 'Location:CN', 'Non-China'] } };
  const choices = chainTargetOptions(metadata, { preset: 'minimal', default_policy: '节点选择' });
  assert.equal(choices.find(item => item.value === 'proxy:美国落地').label, '美国落地（模板节点）');
  assert.equal(choices.find(item => item.value === 'node:1').label, '入口 · #1 · 已停用');
  assert.ok(choices.some(item => item.value === 'group:Private'));
  assert.ok(!choices.some(item => item.value.includes('DIRECT') || item.value.includes('REJECT')));
  assert.ok(!chainTargetOptions(metadata, { preset: 'original', default_policy: 'DIRECT' }).some(item => item.value === 'group:Private'));
});

test('legacy untyped target metadata preserves literal references without claiming they are groups', () => {
  const choices = chainTargetOptions({ policies: ['外部节点', 'DIRECT', 'REJECT'], presets: {} }, { preset: 'original', default_policy: 'DIRECT' });
  assert.deepEqual(choices, [{ value: '外部节点', label: '外部节点（策略或模板节点）' }]);
});

test('native exports preserve exact YAML and stable chain refs for backend import', () => {
  const draft = { ...profile(), base_yaml: 'proxies:\n  - name: "a#b"\n    password: " literal "\n', chain_rules: [{ exit_nodes: ['node:5'], entry: 'group:入口' }] };
  const exported = JSON.parse(templateExport('mihomo', draft));
  assert.equal(exported.format, 'simplexboard-subscription-template');
  assert.equal(exported.client, 'mihomo');
  assert.deepEqual(exported.profile, draft);
  assert.throws(() => templateExport('singbox', draft), /仅支持/);
});

test('payload serialization rejects invalid provider and terminal rule combinations', () => {
  const p = { ...profile(), rule_providers: [{ ...newRuleProvider(), name: 'work', url: 'https://example.test/work.yaml', interval: '86400' }] };
  assert.equal(templatePayload(p).rule_providers[0].interval, 86400);
  assert.equal(p.rule_providers[0].interval, '86400');
  assert.throws(() => templatePayload({ ...p, rule_providers: [{ ...p.rule_providers[0], interval: '' }] }), /更新间隔/);
  assert.throws(() => templatePayload({ ...p, rule_providers: [{ ...p.rule_providers[0], behavior: 'classical', format: 'mrs' }] }), /classical/);
  assert.throws(() => templatePayload({ ...p, custom_rules: ['MATCH,DIRECT', 'DOMAIN,example.com,REJECT'] }), /MATCH/);
  assert.throws(() => templatePayload({ ...p, custom_rules: ['FINAL,DIRECT'] }), /FINAL/);
});

test('chain payloads preserve entry direction and reject incomplete or direct self chains', () => {
  const p = { ...profile(), chain_rules: [{ exit_nodes: ['node:2', 'node:2', 'node:3'], entry: 'node:1' }] };
  assert.deepEqual(templatePayload(p).chain_rules, [{ exit_nodes: ['node:2', 'node:3'], entry: 'node:1' }]);
  assert.deepEqual(p.chain_rules[0].exit_nodes, ['node:2', 'node:2', 'node:3']);
  assert.throws(() => templatePayload({ ...p, chain_rules: [{ exit_nodes: [], entry: 'node:1' }] }), /至少一个/);
  assert.throws(() => templatePayload({ ...p, chain_rules: [{ exit_nodes: ['node:1'], entry: 'node:1' }] }), /不能相同/);
});
