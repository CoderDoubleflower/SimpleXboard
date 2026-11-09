import { h, icon, button, iconButton, badge, input, select, field, textarea, checkbox, secretInput, formValues, pageHeader, panel, loading, emptyState, errorState, notice, table, toast, copyText, runAction, openDialog, confirmAction, toggleSwitch, tabs } from '../ui.js';
import { data, bytes, datetime, relativeTime, nodeStatus, nodeConnections, protocolName, errorMessage } from '../format.js';
import { nodePayload, gibValue } from '../domain.js';
import { createRealityGenerator } from '../reality.js';
import { hasCustomRouting, nodeOutboundLabel, outboundProtocolName, outboundAddress } from '../outbounds.js';
import { outboundsPanel } from './outbounds.js';
import { machineMaintenance, deleteMachine } from './node-maintenance.js';
import { certificateFields } from './certificate-fields.js';

export async function nodesPage(host, context) {
  const { api, signal, navigate } = context;
  let nodes = [];
  let machines = [];
  let outbounds = [];
  let nodeError = null;
  let machineError = null;
  let outboundError = null;
  let active = ['machines', 'outbounds'].includes(context.query.get('tab')) ? context.query.get('tab') : 'nodes';
  const content = h('div', {}, loading());
  const tabBar = h('div');
  const search = input('node-search', '', { type: 'search', placeholder: '搜索节点名称、地址或协议', 'aria-label': '搜索节点' });
  search.addEventListener('input', () => {
    const selection = search.selectionStart;
    render();
    search.focus({ preventScroll: true });
    search.setSelectionRange(selection, selection);
  });
  host.append(pageHeader('VPS 与节点', '管理机器接入、节点入口、出站及其可用状态。', [
    button('添加 VPS', { icon: 'plus', onClick: () => machineEditor(null, context, load) }),
    button('添加节点', { icon: 'plus', tone: 'primary', onClick: () => nodeEditor(null, machines, { items: outbounds, error: outboundError }, context, load) }),
  ]), tabBar, content);

  async function load() {
    content.replaceChildren(loading('正在读取节点、机器和出站…'));
    const results = await Promise.allSettled([api.get('server/manage/getNodes', {}, { signal }), api.get('server/machine/fetch', {}, { signal }), api.get('server/outbound/fetch', {}, { signal })]);
    if (signal.aborted) return;
    if (results[0].status === 'fulfilled') { nodes = data(results[0].value) ?? []; nodeError = null; } else nodeError = results[0].reason;
    if (results[1].status === 'fulfilled') { machines = data(results[1].value) ?? []; machineError = null; } else machineError = results[1].reason;
    if (results[2].status === 'fulfilled') { outbounds = data(results[2].value) ?? []; outboundError = null; } else outboundError = results[2].reason;
    render();
  }

  function render() {
    tabBar.replaceChildren(tabs([{ value: 'nodes', label: `节点 ${nodes.length}` }, { value: 'machines', label: `VPS ${machines.length}` }, { value: 'outbounds', label: `出站 ${outbounds.length}` }], active, value => { active = value; render(); }));
    content.replaceChildren();
    if (active === 'outbounds') {
      if (outboundError) { content.append(errorState(outboundError, load)); return; }
      content.append(outboundsPanel(outbounds, context, load));
      return;
    }
    if (active === 'machines') {
      if (machineError) { content.append(errorState(machineError, load)); return; }
      if (!machines.length) { content.append(panel(null, emptyState('接入第一台 VPS', '创建机器记录，复制接入命令，在目标 VPS 安装节点服务。', button('添加 VPS', { tone: 'primary', icon: 'plus', onClick: () => machineEditor(null, context, load) })))); return; }
      content.append(h('div', { class: 'machine-grid' }, machines.map(machine => machineCard(machine, context, load))));
      return;
    }
    if (nodeError) { content.append(errorState(nodeError, load)); return; }
    const term = search.value.trim().toLowerCase();
    const filtered = nodes.filter(node => `${node.name} ${node.host} ${protocolName(node)}`.toLowerCase().includes(term));
    const toolbar = h('div', { class: 'toolbar' }, h('div', { class: 'search-field' }, icon('search'), search), h('span', { class: 'toolbar-spacer' }), h('span', { class: 'toolbar-note' }, '停用会撤销授权；隐藏仅影响订阅展示'));
    const body = h('div', {}, toolbar);
    if (!filtered.length) body.append(emptyState(term ? '没有找到匹配节点' : '添加一个连接入口', term ? '试试其他名称、主机地址或协议。' : '支持 VLESS + REALITY、Hysteria2，也可维护已有协议配置。', term ? null : button('添加节点', { tone: 'primary', icon: 'plus', onClick: () => nodeEditor(null, machines, { items: outbounds, error: outboundError }, context, load) })));
    else body.append(table([
      { label: '节点', render: node => h('div', { class: 'row-identity' }, h('strong', {}, node.name), h('span', { class: 'mono muted' }, `${node.host}:${node.port}`), node.machine_id ? h('small', { class: 'muted' }, machines.find(machine => Number(machine.id) === Number(node.machine_id))?.name ?? `VPS #${node.machine_id}`) : null) },
      { label: '协议 / 出站', render: node => h('div', { class: 'stack-sm node-outbound' }, h('span', { class: 'protocol-tag' }, protocolName(node)), h('small', { class: 'muted' }, `出口：${nodeOutboundLabel(node, outbounds)}`)) },
      { label: '运行状态', className: 'runtime-cell', render: node => {
        const status = nodeStatus(node);
        const connection = nodeConnections(node);
        const core = node.core_status;
        return h('div', { class: 'stack-sm node-runtime-state' }, badge(status.label, status.tone),
          h('small', { class: 'muted' }, `${connection.machine} · ${connection.control}`),
          core?.users != null && core?.applied_users != null ? h('small', { class: 'muted' }, `已应用 ${core.applied_users} / ${core.users} 位用户`) : null,
          core?.error ? h('details', { class: 'runtime-error' }, h('summary', {}, '查看核心错误'), h('p', {}, core.error)) : null,
          h('small', { class: 'muted' }, core?.updated_at ? `核心报告 ${relativeTime(core.updated_at)}` : '尚无核心报告'));
      } },
      { label: '实际流量 / 倍率', render: node => h('div', { class: 'traffic-cell' },
        h('strong', {}, bytes(Number(node.u ?? 0) + Number(node.d ?? 0))),
        h('small', { class: 'muted' }, `↑ ${bytes(node.u ?? 0)} · ↓ ${bytes(node.d ?? 0)}`),
        h('small', { class: 'muted' }, `额度倍率 ${Number(node.rate ?? 1)}×${Number(node.rate ?? 1) === 0 ? ' · 不扣额度' : ''}`)) },
      { label: '启用', className: 'compact-cell', render: node => toggleSwitch(`启用节点 ${node.name}`, node.enabled !== false && node.enabled !== 0, async enabled => { await api.post('server/manage/update', { id: node.id, enabled }); toast(enabled ? '节点已启用' : '节点已停用'); await load(); }) },
      { label: '订阅展示', className: 'compact-cell', render: node => toggleSwitch(`在订阅中展示 ${node.name}`, Boolean(node.show), async show => { await api.post('server/manage/update', { id: node.id, show: show ? 1 : 0 }); toast(show ? '节点已在订阅中显示' : '节点已从订阅中隐藏'); await load(); }) },
      { label: '操作', className: 'actions-cell', render: node => h('div', { class: 'row-actions' },
        iconButton(`编辑节点 ${node.name}`, 'edit', () => nodeEditor(node, machines, { items: outbounds, error: outboundError }, context, load)),
        iconButton(`删除节点 ${node.name}`, 'trash', () => confirmAction({ title: '删除节点', description: `确认删除“${node.name}”？此节点的配置和成员授权会被移除，VPS 机器记录会保留。`, label: '删除节点', onConfirm: async () => { await api.post('server/manage/drop', { id: node.id }); toast('节点已删除'); await load(); } }), 'danger-text')) },
    ], filtered));
    content.append(panel(null, body));
    if (machineError) content.append(notice('VPS 列表暂不可读取，节点状态仍可独立查看。', 'warning'));
    if (outboundError) content.append(notice('出站列表暂不可读取，已绑定的出站仍按编号显示。重新加载后可管理出站。', 'warning'));
  }
  await load();
  if (!signal.aborted && context.query.get('create') === 'node') nodeEditor(null, machines, { items: outbounds, error: outboundError }, context, load);
  if (!signal.aborted && context.query.get('create') === 'machine') machineEditor(null, context, load);
}

function machineCard(machine, context, reload) {
  const connected = machine.is_active && machine.last_seen_at && Date.now() / 1000 - machine.last_seen_at < 300;
  const status = !machine.is_active ? ['接入已关闭', 'muted'] : connected ? ['机器在线', 'success'] : [machine.last_seen_at ? '机器离线' : '等待心跳', 'warning'];
  const controls = h('div', { class: 'machine-actions' },
    button('接入命令', { icon: 'terminal', onClick: event => runAction(event.currentTarget, () => machineCredentials(machine, context)) }),
    button('节点维护', { icon: 'settings', 'aria-label': `节点维护 ${machine.name}`, onClick: () => machineMaintenance(machine, context) }),
    h('div', { class: 'row-actions' },
      iconButton(`编辑 VPS ${machine.name}`, 'edit', () => machineEditor(machine, context, reload)),
      iconButton(`轮换 ${machine.name} 的机器令牌`, 'key', () => openDialog({ title: '轮换机器令牌', content: h('p', { class: 'confirm-copy' }, '原令牌将立即失效。请在 VPS 更新节点服务的认证配置，使其恢复接入。'), submitLabel: '确认轮换', danger: true,
        onSubmit: () => context.api.post('server/machine/resetToken', { id: machine.id }),
        onSuccess: () => { toast('机器令牌已轮换'); machineCredentials(machine, context).catch(error => toast(error.message, 'error')); },
      })),
      iconButton(`删除 VPS ${machine.name}`, 'trash', () => deleteMachine(machine, context, reload), 'danger-text')));
  return panel(null, h('div', { class: 'machine-card' },
    h('div', { class: 'machine-heading' }, h('span', { class: 'machine-icon' }, icon('server')), h('div', {}, h('h3', {}, machine.name), h('span', { class: 'muted small' }, `机器 #${machine.id}`)), badge(...status)),
    h('p', { class: 'machine-notes' }, machine.notes || '未添加备注'),
    h('dl', { class: 'machine-facts' }, h('div', {}, h('dt', {}, '关联节点'), h('dd', {}, machine.servers_count ?? 0)), h('div', {}, h('dt', {}, '最近心跳'), h('dd', { title: datetime(machine.last_seen_at) }, relativeTime(machine.last_seen_at)))), controls));
}

function machineEditor(machine, context, reload) {
  openDialog({ title: machine ? '编辑 VPS' : '添加 VPS', description: '先创建机器记录，再在对应 VPS 安装节点服务。',
    content: h('div', { class: 'stack-lg' },
      field('机器名称', input('name', machine?.name ?? '', { required: true, maxlength: 255, placeholder: '例如：东京 · VPS 01' }), { required: true }),
      field('备注', textarea('notes', machine?.notes ?? '', { placeholder: '供应商、区域或运维说明' })),
      checkbox('is_active', '允许此机器接入', machine?.is_active ?? true, '关闭后，机器令牌的后续请求将被拒绝。')),
    submitLabel: machine ? '保存更改' : '创建 VPS',
    onSubmit: async form => {
      const values = formValues(form);
      const response = data(await context.api.post('server/machine/save', { ...(machine ? { id: machine.id } : {}), name: values.name.trim(), notes: values.notes.trim() || null, is_active: values.is_active }));
      return { result: response, name: values.name.trim() };
    },
    onSuccess: ({ result, name }) => { toast(machine ? 'VPS 已更新' : 'VPS 记录已创建'); reload(); if (!machine && result?.id) showMachineCredentials(name, result.token, result.install_command); },
  });
}

async function machineCredentials(machine, context) {
  const [token, command] = await Promise.all([context.api.get('server/machine/getToken', { id: machine.id }), context.api.get('server/machine/installCommand', { id: machine.id })]);
  showMachineCredentials(machine.name, data(token).token, data(command).command);
}

function showMachineCredentials(name, token, command) {
  openDialog({ title: `接入 VPS · ${name}`, large: true, content: h('div', { class: 'stack-lg' },
    notice('在目标 VPS 的终端执行接入命令。命令包含机器令牌，请不要公开分享。'),
    field('接入命令', textarea('machine-command', command ?? '', { readonly: true, rows: 5, class: 'input mono code-input' })),
    button('复制接入命令', { tone: 'primary', icon: 'copy', onClick: () => copyText(command, '接入命令已复制') }),
    field('机器令牌', secretInput('machine-token', token ?? '', { readonly: true })),
    button('复制机器令牌', { icon: 'copy', onClick: () => copyText(token, '机器令牌已复制') }),
    h('p', { class: 'field-hint' }, '安装完成后，将节点绑定到这台 VPS，等待机器心跳。这里不会替你执行远程命令。')) });
}

function defaultProtocol(type) {
  if (type === 'vless') return { tls: 2, network: 'tcp', flow: 'xtls-rprx-vision', reality_settings: { server_name: '', server_port: 443, public_key: '', private_key: '', short_id: '' }, utls: { enabled: true, fingerprint: 'chrome' } };
  if (type === 'hysteria') return { version: 2, tls: { server_name: '', allow_insecure: false }, obfs: { open: false, type: 'salamander', password: '' }, bandwidth: { up: 100, down: 100 } };
  return {};
}

function nodeEditor(node, machines, outboundState, context, reload) {
  let editor;
  let generation = null;
  let certificateEditor = null;
  let saving = false;
  let syncGenerationControls = () => {};
  const types = [{ value: 'vless', label: 'VLESS + REALITY / TLS' }, { value: 'hysteria', label: 'Hysteria2' }];
  if (node && !types.some(type => type.value === node.type)) types.push({ value: node.type, label: protocolName(node) });
  const type = select('type', types, node?.type ?? 'vless', { disabled: Boolean(node) });
  const protocolBody = h('div', { class: 'form-grid' });
  const certificateBody = h('div');
  const certificateSection = h('section', { class: 'form-section' }, h('h3', {}, '服务端 TLS 证书'), certificateBody);
  const advancedHint = h('span');
  const protocolJson = textarea('protocol_json', '', { rows: 12, spellcheck: false, class: 'input mono code-input' });
  const advanced = {};
  for (const key of ['route_ids', 'excludes', 'ips', 'parent_id', 'spectific_key', 'custom_routes', 'custom_outbounds', 'cert_config']) {
    if (key === 'cert_config' && node?.type === 'hysteria') continue;
    if (node?.[key] !== undefined && node[key] !== null) advanced[key] = node[key];
  }
  const advancedJson = textarea('advanced_json', JSON.stringify(advanced, null, 2), { rows: 7, spellcheck: false, class: 'input mono code-input' });
  const machine = select('machine_id', [{ value: '', label: '独立节点（不绑定 VPS）' }, ...machines.map(item => ({ value: item.id, label: item.name }))], node?.machine_id ?? '');
  const outboundOptions = [{ value: '', label: '直连（使用当前 VPS 出口）' }, ...outboundState.items.map(item => ({ value: item.id, label: `${item.name} · ${outboundProtocolName(item)} · ${outboundAddress(item)}` }))];
  if (node?.outbound_id != null && !outboundState.items.some(item => Number(item.id) === Number(node.outbound_id))) {
    outboundOptions.push({ value: node.outbound_id, label: `已选出站 #${node.outbound_id}（暂不可读取）` });
  }
  const outbound = select('outbound_id', outboundOptions, node?.outbound_id ?? '');
  const outboundHint = h('div', { class: 'stack-sm' });

  function describeOutbound() {
    let advancedSettings;
    try { advancedSettings = JSON.parse(advancedJson.value || '{}'); } catch { advancedSettings = advanced; }
    // Omitted advanced keys are retained by nodePayload; the hint must describe the same effective settings.
    const hasLegacy = hasCustomRouting({ ...advanced, ...advancedSettings });
    outbound.options[0].textContent = hasLegacy ? '不选托管出站（保留高级配置）' : '直连（使用当前 VPS 出口）';
    const selected = outboundState.items.find(item => String(item.id) === outbound.value);
    const supported = machine.value ? machines.find(item => String(item.id) === machine.value)?.managed_outbound_supported === true : !node?.machine_id && node?.managed_outbound_supported === true;
    const retained = Boolean(node?.outbound_id != null && String(node.outbound_id) === outbound.value && String(node.machine_id ?? '') === machine.value);
    for (const option of outbound.options) {
      const original = node?.outbound_id != null && option.value === String(node.outbound_id) && String(node.machine_id ?? '') === machine.value;
      option.disabled = option.value !== '' && option.value !== outbound.value && !original && (Boolean(outboundState.error) || !supported);
    }
    outbound.setCustomValidity('');
    const messages = [];
    if (outboundState.error) messages.push(notice('出站列表暂不可读取。当前选择已保留，请关闭后重新加载再选择其他出站。', 'warning'));
    if (outbound.value && hasLegacy) {
      const message = '所选出站与高级路由配置冲突。请先将高级配置中的 route_ids、custom_routes 和 custom_outbounds 清空为 []，或取消选择出站。';
      outbound.setCustomValidity(message);
      messages.push(notice(message, 'error'));
    } else if (outbound.value) {
      messages.push(notice(`用户流量经本节点转发至“${selected?.name ?? `出站 #${outbound.value}`}”。修改出站可能短暂中断连接；出口不可用时不会自动改为直连。`));
    } else if (hasLegacy) messages.push(notice('此节点的出口由高级路由配置管理。若要改用上方出站或直连，请先明确清空原有路由；保存其他字段会保留高级配置。', 'warning'));
    else messages.push(h('p', { class: 'field-hint' }, '不选择出站时，用户流量直接从当前 VPS 访问目标。先在“出站”标签页保存现成节点，即可在此选择。'));
    if (!supported && !retained) {
      const message = machine.value || node ? '请先升级并接入支持出站管理的节点端（sing-box），再选择出站。' : '独立节点请先创建并接入支持出站管理的节点端（sing-box），然后编辑此节点选择出站。';
      messages.push(notice(message, 'warning'));
      if (outbound.value) outbound.setCustomValidity(message);
    }
    outboundHint.replaceChildren(...messages);
  }
  outbound.addEventListener('change', describeOutbound);
  machine.addEventListener('change', describeOutbound);
  advancedJson.addEventListener('input', describeOutbound);
  describeOutbound();
  const content = h('div', { class: 'stack-lg' },
    h('div', { class: 'form-grid' },
      field('节点名称', input('name', node?.name ?? '', { required: true, maxlength: 255, placeholder: '例如：东京 · REALITY' }), { required: true }),
      field('协议', type),
      field('连接地址', input('host', node?.host ?? '', { required: true, placeholder: 'node.example.com 或 IP' }), { required: true }),
      field('连接端口', input('port', node?.port ?? '443', { required: true, placeholder: '443', inputmode: 'numeric' }), { required: true, hint: '客户端连接使用的端口。' }),
      field('服务监听端口', input('server_port', node?.server_port ?? '443', { type: 'number', min: 1, max: 65535, required: true }), { required: true }),
      field('绑定 VPS', machine),
      field('节点流量上限（GiB）', input('quota', gibValue(node?.transfer_enable), { type: 'number', min: 0, step: 'any', placeholder: '不限' }), { hint: '按实际流量计算，不受倍率影响；留空或 0 均为不限。' }),
      field('流量倍率（×）', input('rate', node?.rate ?? 1, { type: 'number', min: 0, max: 100, step: '0.01', inputmode: 'decimal', required: true }), { required: true, hint: '0–100，最多 2 位小数。成员额度按此倍率累计，0× 不扣额度；仅对后续收到的上报生效。' }),
      field('标签', input('tags', (node?.tags ?? []).join(', '), { placeholder: '地区、用途等，用逗号分隔' }), { wide: true })),
    h('div', { class: 'checkbox-grid' }, checkbox('enabled', '启用节点', node?.enabled !== false && node?.enabled !== 0, '停用后不再向此节点授权成员。'), checkbox('show', '在订阅中展示', node ? Boolean(node.show) : true, '隐藏只影响订阅内容，不等同于停用。')),
    h('section', { class: 'form-section' }, h('h3', {}, '连接与安全'), protocolBody),
    certificateSection,
    h('section', { class: 'form-section stack-lg' }, h('h3', {}, '流量出口'), field('出站节点', outbound), outboundHint),
    h('details', { class: 'advanced-section' }, h('summary', {}, icon('settings'), '高级连接配置'),
      h('div', { class: 'stack-lg advanced-content' },
        field('协议参数 JSON', protocolJson, { hint: '维护传输、复用等额外参数。上方已填写的连接字段优先，其他字段保持原值。' }),
        field('节点高级配置 JSON', advancedJson, { hint: advancedHint }))));

  function renderProtocol() {
    generation?.cancel();
    generation = null;
    certificateEditor = null;
    syncGenerationControls = () => {};
    const settings = type.value === node?.type ? (node.protocol_settings ?? {}) : defaultProtocol(type.value);
    protocolJson.value = JSON.stringify(settings, null, 2);
    protocolBody.replaceChildren();
    certificateBody.replaceChildren();
    certificateSection.hidden = type.value !== 'hysteria';
    advancedHint.textContent = type.value === 'hysteria'
      ? '可维护 custom_routes、custom_outbounds、route_ids 等参数。证书请使用上方“服务端 TLS 证书”；现有证书扩展字段会保留。'
      : '可维护 cert_config、custom_routes、custom_outbounds、route_ids 等现有节点参数。';
    if (type.value === 'vless') {
      const tls = select('vless_tls', [{ value: '2', label: 'REALITY' }, { value: '1', label: 'TLS' }, { value: '0', label: '不启用 TLS' }], String(settings.tls ?? 2));
      const publicKey = input('reality_public_key', settings.reality_settings?.public_key ?? '', { required: true, class: 'input mono', spellcheck: false });
      const privateKey = secretInput('reality_private_key', settings.reality_settings?.private_key ?? '', { required: true });
      const privateInput = privateKey.querySelector('input');
      const shortId = input('reality_short_id', settings.reality_settings?.short_id ?? '', { class: 'input mono', maxlength: 16, pattern: '[0-9a-fA-F]{0,16}', placeholder: '十六进制，例如 a1b2c3d4' });
      const generatePair = button('一键生成密钥和 Short ID', { icon: 'key', onClick: () => generator.generateKeyPair() });
      const generateShort = button('生成 Short ID', { onClick: () => generator.generateShortId() });
      const generationStatus = h('div', { class: 'generation-status field-wide' });
      let progress = null;
      const reality = h('div', { class: 'form-grid field-wide' },
        field('REALITY 目标域名 / SNI', input('reality_server_name', settings.reality_settings?.server_name ?? '', { required: true, placeholder: 'www.example.com' }), { required: true }),
        field('REALITY 目标端口', input('reality_server_port', settings.reality_settings?.server_port ?? 443, { required: true, type: 'number', min: 1, max: 65535 })),
        h('div', { class: 'field-wide inline-actions' }, generatePair, h('span', { class: 'field-hint' }, '仅填入当前表单，保存节点后生效。')),
        generationStatus,
        field('公钥', publicKey, { wide: true, required: true }),
        field('私钥', privateKey, { wide: true, required: true }),
        field('Short ID', h('div', { class: 'input-action' }, shortId, generateShort)),
        field('客户端指纹', input('utls_fingerprint', settings.utls?.fingerprint ?? 'chrome')));
      const generator = createRealityGenerator({
        api: context.api,
        isActive: () => Boolean(editor?.dialog.open && reality.isConnected && !context.signal.aborted && !saving && type.value === 'vless' && tls.value === '2'),
        onBusy: (busy, kind) => {
          syncGenerationControls();
          if (busy) {
            progress = notice(kind === 'pair' ? '正在生成密钥和 Short ID…' : '正在生成 Short ID…');
            generationStatus.replaceChildren(progress);
          } else if (generationStatus.firstChild === progress) generationStatus.replaceChildren();
          for (const control of [generatePair, generateShort]) control.removeAttribute('aria-busy');
          if (busy) (kind === 'pair' ? generatePair : generateShort).setAttribute('aria-busy', 'true');
        },
        onResult: (values, kind) => {
          if (kind === 'pair') {
            publicKey.value = values.public_key;
            privateInput.value = values.private_key;
          }
          shortId.value = values.short_id;
          generationStatus.replaceChildren(notice(kind === 'pair' ? '密钥和 Short ID 已生成，保存节点后生效。' : 'Short ID 已生成，公钥和私钥保持不变；保存节点后生效。', 'success'));
        },
        onError: error => generationStatus.replaceChildren(notice(`生成失败：${errorMessage(error)} 原有值已保留，请重试。`, 'error')),
      });
      generation = generator;
      syncGenerationControls = () => {
        const disabled = tls.value !== '2' || generator.busy || saving;
        for (const control of [generatePair, generateShort, publicKey, privateInput, shortId]) control.disabled = disabled;
        const submit = editor?.form.querySelector('button[type="submit"]');
        if (submit) submit.disabled = generator.busy || saving;
      };
      const tlsFields = h('div', { class: 'form-grid field-wide' },
        field('TLS 服务器名称 / SNI', input('tls_server_name', settings.tls_settings?.server_name ?? '', { placeholder: 'node.example.com' })),
        checkbox('tls_insecure', '跳过证书校验', Boolean(settings.tls_settings?.allow_insecure), '仅在确实需要时启用。'));
      const showFields = () => {
        if (tls.value !== '2') generator.cancel();
        for (const [group, active] of [[reality, tls.value === '2'], [tlsFields, tls.value === '1']]) {
          group.hidden = !active;
          for (const control of group.querySelectorAll('input,select,button')) control.disabled = !active;
        }
        syncGenerationControls();
      };
      tls.addEventListener('change', showFields);
      protocolBody.append(field('安全方式', tls), field('传输方式', input('network', settings.network || 'tcp', { required: true, placeholder: 'tcp / ws / grpc / xhttp' })), field('Flow', input('flow', settings.flow ?? '', { placeholder: 'xtls-rprx-vision' }), { wide: true }), reality, tlsFields);
      showFields();
    } else if (type.value === 'hysteria') {
      const password = secretInput('hy_obfs_password', settings.obfs?.password ?? '');
      const obfs = checkbox('hy_obfs', '启用 Salamander 混淆', Boolean(settings.obfs?.open));
      const toggleObfs = () => { password.querySelector('input').required = obfs.querySelector('input').checked; };
      obfs.querySelector('input').addEventListener('change', toggleObfs);
      protocolBody.append(
        field('协议版本', select('hy_version', [{ value: 2, label: 'Hysteria2' }, { value: 1, label: 'Hysteria（已有节点兼容）' }], settings.version ?? 2)),
        field('TLS 服务器名称 / SNI', input('tls_server_name', settings.tls?.server_name ?? '', { required: true, placeholder: 'hy.example.com' }), { required: true, hint: '客户端期望的证书名称。请在下方另外配置服务端证书。' }),
        field('上行带宽（Mbps）', input('hy_up', settings.bandwidth?.up ?? '', { type: 'number', min: 0, step: 1 })),
        field('下行带宽（Mbps）', input('hy_down', settings.bandwidth?.down ?? '', { type: 'number', min: 0, step: 1 })),
        field('跳跃间隔（秒）', input('hy_hop', settings.hop_interval ?? '', { type: 'number', min: 0, step: 1, placeholder: '使用默认值' })),
        checkbox('tls_insecure', '跳过证书验证', Boolean(settings.tls?.allow_insecure), '仅影响客户端验证；服务端仍必须配置证书和私钥。'),
        obfs, field('混淆密码', password));
      certificateEditor = certificateFields(node?.cert_config);
      certificateBody.append(certificateEditor.element);
      toggleObfs();
    } else protocolBody.append(h('div', { class: 'field-wide' }, notice('此节点使用已有协议。请在“高级连接配置”中编辑原有协议参数 JSON，通用连接字段仍可直接修改。')));
  }
  type.addEventListener('change', renderProtocol);
  renderProtocol();
  editor = openDialog({ title: node ? '编辑节点' : '添加节点', description: node ? `节点 #${node.id} · 保存后，节点服务将在下次同步时读取新配置。` : '配置节点的连接入口，然后将订阅分享给成员。', content, large: true,
    submitLabel: node ? '保存节点' : '创建节点',
    onSubmit: async form => {
      if (generation?.busy) throw new Error('正在生成，请等待完成后再保存。');
      try {
        const payload = nodePayload({ ...formValues(form), type: type.value }, node);
        saving = true;
        syncGenerationControls();
        return await context.api.post('server/manage/save', payload);
      } catch (error) {
        certificateEditor?.reportError(error);
        if (error.field === 'advanced_json') {
          advancedJson.closest('details').open = true;
          setTimeout(() => advancedJson.focus(), 0);
        }
        throw error;
      }
      finally { saving = false; syncGenerationControls(); }
    },
    onSuccess: () => { toast(node ? '节点配置已保存' : '节点已创建'); reload(); },
  });
  // Block Enter/requestSubmit too, without making the dialog impossible to close.
  editor.form.addEventListener('submit', event => {
    if (!generation?.busy) return;
    event.preventDefault();
    event.stopImmediatePropagation();
  }, { capture: true });
  const leave = () => { generation?.cancel(); editor.close(); };
  context.signal.addEventListener('abort', leave, { once: true });
  editor.dialog.addEventListener('close', () => {
    generation?.cancel();
    context.signal.removeEventListener('abort', leave);
  }, { once: true });
}
