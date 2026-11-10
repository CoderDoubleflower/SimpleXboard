export const SUBSCRIPTION_CLIENTS = Object.freeze([
  { value: 'mihomo', label: 'mihomo' },
  { value: 'stash', label: 'Stash' },
]);

export const TEMPLATE_PRESETS = Object.freeze([
  { value: 'original', label: '保留基础 YAML 的规则' },
  { value: 'minimal', label: '极简：国内、局域网与海外' },
  { value: 'balanced', label: '均衡：常用服务分流' },
  { value: 'comprehensive', label: '完整：全部规则分类' },
  { value: 'custom', label: '自选规则分类' },
]);

export function copyProfile(profile) {
  return JSON.parse(JSON.stringify(profile));
}

// Several fetches can finish after rapid client switches. Once a draft exists,
// a later initial-load response must never replace the administrator's edits.
export function rememberTemplate(records, client, metadata) {
  if (records.has(client)) return records.get(client);
  const record = { metadata, original: copyProfile(metadata.profile), draft: copyProfile(metadata.profile), warnings: metadata.warnings ?? [], preview: null, userId: '' };
  records.set(client, record);
  return record;
}

// Only whole-line comments are ignored: commas, #, quotes, and rule order are
// meaningful in the native rule syntax and must not be rewritten by the UI.
export function ruleLines(text) {
  return String(text ?? '').split(/\r?\n/).map(line => line.trim()).filter(line => line && !line.startsWith('#'));
}

export function referenceLines(text) {
  return [...new Set(ruleLines(text))];
}

export function selectedCategories(profile, presets = {}) {
  if (profile.preset === 'original') return [];
  if (profile.preset === 'custom') return [...(profile.selected_rules ?? [])];
  return [...(presets[profile.preset] ?? [])];
}

export function newRuleProvider(policy = '节点选择') {
  return { name: '', url: '', behavior: 'domain', format: 'yaml', interval: 86400, policy };
}

export function referenceLabel(reference, nodes = [], policies = []) {
  const value = String(reference ?? '');
  const node = nodes.find(item => (item.ref ?? `node:${item.id}`) === value);
  if (node) return `${node.name} · #${node.id}`;
  if (value.startsWith('node:')) return `节点 #${value.slice(5)}（当前不可用）`;
  if (value.startsWith('group:')) return `${value.slice(6)}（策略组）`;
  if (value.startsWith('proxy:')) return `${value.slice(6)}（模板节点）`;
  return policies.includes(value) ? `${value}（策略）` : value;
}

export function chainTargetOptions(metadata, profile) {
  const builtins = new Set(['DIRECT', 'REJECT', 'REJECT-DROP', 'PASS', 'COMPATIBLE', 'GLOBAL']);
  const options = new Map();
  for (const node of metadata.nodes ?? []) {
    const suffix = node.enabled === false || node.enabled === 0 ? ' · 已停用' : node.show === false || node.show === 0 ? ' · 订阅隐藏' : '';
    const ref = node.ref ?? `node:${node.id}`;
    options.set(ref, { value: ref, label: `${node.name} · #${node.id}${suffix}` });
  }
  const targets = Array.isArray(metadata.chain_targets) ? metadata.chain_targets : (metadata.policies ?? []).map(name => ({ ref: name, name, type: 'unknown' }));
  for (const target of targets) {
    if (builtins.has(target.name)) continue;
    const suffix = target.type === 'proxy' ? '（模板节点）' : target.type === 'group' ? '（策略组）' : '（策略或模板节点）';
    options.set(target.ref, { value: target.ref, label: target.name + suffix });
  }
  const dynamic = [profile.default_policy, ...selectedCategories(profile, metadata.presets)];
  for (const name of dynamic) {
    if (!name || builtins.has(name) || targets.some(target => target.name === name)) continue;
    options.set(`group:${name}`, { value: `group:${name}`, label: `${name}（策略组）` });
  }
  return [...options.values()];
}

export function templateExport(client, profile) {
  if (!SUBSCRIPTION_CLIENTS.some(item => item.value === client)) throw new Error('仅支持 mihomo 与 Stash 模板。');
  return JSON.stringify({ format: 'simplexboard-subscription-template', version: 1, client, profile: copyProfile(profile) }, null, 2) + '\n';
}

export function templatePayload(profile) {
  const result = copyProfile(profile);
  if (typeof result.base_yaml !== 'string') throw new Error('基础 YAML 不能为空。');
  if (!TEMPLATE_PRESETS.some(item => item.value === result.preset)) throw new Error('请选择规则预设。');
  result.rule_providers = (result.rule_providers ?? []).map((provider, index) => {
    const item = { ...provider, interval: Number(provider.interval) };
    if (!Number.isSafeInteger(item.interval) || item.interval < 60) throw new Error(`规则集 ${index + 1} 的更新间隔须为不少于 60 的整数秒。`);
    if (item.behavior === 'classical' && item.format === 'mrs') throw new Error(`规则集 ${index + 1}：classical 不支持 MRS，请选择 YAML 或 text。`);
    return item;
  });
  result.custom_rules = (result.custom_rules ?? []).map(value => String(value).trim()).filter(Boolean);
  if (result.custom_rules.some(rule => /^(MATCH|FINAL)\s*,/i.test(rule))) throw new Error('自定义规则中不能加入 MATCH / FINAL；请使用默认策略设置兜底规则。');
  result.chain_rules = (result.chain_rules ?? []).map((chain, index) => {
    const item = { exit_nodes: [...new Set(chain.exit_nodes ?? [])], entry: String(chain.entry ?? '').trim() };
    if (!item.exit_nodes.length || !item.entry) throw new Error(`链路 ${index + 1} 需要选择入口和至少一个落地节点。`);
    if (item.exit_nodes.includes(item.entry)) throw new Error(`链路 ${index + 1} 的入口与落地节点不能相同。`);
    return item;
  });
  return result;
}
