import { h, icon, button, iconButton, badge, input, select, field, textarea, checkbox, formValues, pageHeader, panel, loading, emptyState, errorState, notice, table, pagination, toast, copyText, runAction, openDialog, confirmAction, toggleSwitch } from '../ui.js';
import { data, bytes, datetime, dateInput, memberStatus, protocolName, subscriptionUrl } from '../format.js';
import { memberPayload, gibValue } from '../domain.js';

export async function membersPage(host, context) {
  const { api, signal } = context;
  let nodes = null;
  let currentPage = 1;
  const pageSize = 20;
  const list = h('div', {}, loading());
  const search = input('member-search', '', { type: 'search', placeholder: '搜索成员名称或邮箱', 'aria-label': '搜索成员名称或邮箱' });
  const status = select('member-status', [{ value: '', label: '所有状态' }, { value: '0', label: '已启用' }, { value: '1', label: '已停用' }], '', { 'aria-label': '成员启停状态' });
  const fetchNodes = async () => {
    if (!nodes) nodes = data(await api.get('server/manage/getNodes', {}, { signal }));
    return nodes;
  };
  const edit = async member => {
    try { memberEditor(member, await fetchNodes(), context, load); }
    catch (error) { if (!signal.aborted) toast(error.message, 'error'); }
  };
  const filters = h('form', { class: 'toolbar', onSubmit: event => { event.preventDefault(); currentPage = 1; load(); } },
    h('div', { class: 'search-field' }, icon('search'), search), status, button('查询', { type: 'submit' }),
    h('span', { class: 'toolbar-spacer' }), h('span', { class: 'toolbar-note' }, '每人独立订阅，无需成员账户'));
  status.addEventListener('change', () => { currentPage = 1; load(); });
  host.append(pageHeader('成员与订阅', '分配独立订阅，管理成员启停和节点访问范围。', [
    button('导出全部', { icon: 'download', onClick: event => runAction(event.currentTarget, () => api.download('user/dumpCSV', 'members.csv', { method: 'POST', body: {} })) }),
    button('添加成员', { icon: 'plus', tone: 'primary', onClick: () => edit(null) }),
  ]), panel(null, h('div', {}, filters, list)));

  async function load() {
    list.replaceChildren(loading('正在读取成员…'));
    try {
      const response = await api.get('user/fetch', { current: currentPage, pageSize, search: search.value.trim(), banned: status.value }, { signal });
      if (signal.aborted) return;
      const members = data(response) ?? [];
      list.replaceChildren();
      if (!members.length) {
        list.append(emptyState(search.value || status.value ? '没有符合条件的成员' : '把订阅分享给熟悉的人', search.value || status.value ? '调整搜索词或状态后再试。' : '创建成员后即可复制专属订阅链接，默认永久有效并共享所有启用节点。', search.value || status.value ? null : button('添加第一位成员', { icon: 'plus', tone: 'primary', onClick: () => edit(null) }), 'users'));
      } else list.append(table([
        { label: '成员', render: member => h('div', { class: 'row-identity' }, h('strong', {}, member.name || member.email || `成员 #${member.id}`), h('span', { class: 'muted' }, member.name ? member.email || `ID ${member.id}` : `ID ${member.id}`)) },
        { label: '状态', render: member => { const state = memberStatus(member); return h('div', { class: 'stack-sm' }, badge(state.label, state.tone), h('small', { class: 'muted' }, member.expired_at ? datetime(member.expired_at) : '永久有效')); } },
        { label: '实际流量 / 额度', render: member => trafficCell(member) },
        { label: '节点范围', render: member => h('div', { class: 'stack-sm' }, h('span', {}, member.access_mode === 'restricted' ? `指定 ${member.server_ids?.length ?? 0} 个节点` : '共享启用节点'), h('small', { class: 'muted' }, member.access_mode === 'restricted' ? '保留专属访问范围' : '新启用节点自动共享')) },
        { label: '启用', className: 'compact-cell', render: member => toggleSwitch(`启用成员 ${member.name || member.email}`, !member.banned, async enabled => {
          await api.post('user/ban', { id: member.id, banned: !enabled });
          toast(enabled ? '成员已启用' : '成员已停用');
          await load();
        }) },
        { label: '操作', className: 'actions-cell', render: member => h('div', { class: 'row-actions' },
          iconButton(`查看 ${member.name || member.email} 的订阅`, 'link', () => memberSubscription(member, context, load)),
          iconButton(`复制 ${member.name || member.email} 的订阅`, 'copy', () => copyText(member.subscribe_url, '订阅链接已复制')),
          iconButton(`编辑 ${member.name || member.email}`, 'edit', () => edit(member)),
          iconButton(`删除 ${member.name || member.email}`, 'trash', () => confirmAction({
            title: '删除成员', description: `删除“${member.name || member.email}”后，其订阅与节点凭据将失效。此操作不能撤销。`, label: '删除成员',
            onConfirm: async () => { await api.post('user/destroy', { id: member.id }); toast('成员已删除'); await load(); },
          }), 'danger-text')) },
      ], members));
      if (response.total > pageSize || currentPage > 1) list.append(pagination({ current: currentPage, total: response.total, perPage: pageSize, onChange: page => { currentPage = page; load(); } }));
    } catch (error) { if (!signal.aborted) list.replaceChildren(errorState(error, load)); }
  }
  await load();
  if (context.query.get('create') === 'member' && !signal.aborted) await edit(null);
}

function trafficCell(member) {
  const actual = Number(member.actual_u ?? 0) + Number(member.actual_d ?? 0);
  const quotaUsed = Number(member.u ?? 0) + Number(member.d ?? 0);
  const unlimited = member.transfer_enable === null || member.transfer_enable === undefined;
  const ratio = unlimited ? 0 : Math.min(100, member.transfer_enable > 0 ? quotaUsed / member.transfer_enable * 100 : 100);
  const cell = h('div', { class: 'traffic-cell' },
    h('strong', {}, `实际 ${bytes(actual)}`),
    h('small', { class: 'muted' }, `额度已用 ${bytes(quotaUsed)}${unlimited ? ' · 不限额度' : ` / ${bytes(member.transfer_enable)}`}`));
  if (!unlimited) {
    const meter = h('span', { class: `meter-fill ${ratio >= 90 ? 'meter-warning' : ''}` });
    meter.style.width = `${ratio}%`;
    cell.append(h('span', { class: 'meter', role: 'meter', 'aria-label': '额度使用比例', 'aria-valuenow': Math.round(ratio), 'aria-valuemin': 0, 'aria-valuemax': 100 }, meter));
  }
  return cell;
}

function memberEditor(member, nodes, context, reload) {
  const selectedIds = new Set((member?.server_ids ?? []).map(Number));
  const mode = select('access_mode', [{ value: 'all', label: '共享全部启用节点' }, { value: 'restricted', label: '仅使用指定节点' }], member?.access_mode ?? 'all');
  const choices = h('div', { class: 'node-checklist', hidden: mode.value !== 'restricted' }, nodes.length
    ? nodes.map(node => h('label', { class: 'node-choice' }, input('server_ids', node.id, { type: 'checkbox', checked: selectedIds.has(Number(node.id)), id: `member-node-${node.id}` }), h('span', {}, h('strong', {}, node.name), h('small', {}, protocolName(node))), node.enabled === false || node.enabled === 0 ? badge('已停用') : null))
    : h('p', { class: 'field-hint' }, '尚无可选择的节点。'));
  const scopeHint = h('p', { class: 'field-hint' }, '仅选择的节点可供此成员使用；未选择时没有节点访问权限。');
  const scope = h('div', { class: 'field field-wide' }, h('label', { for: mode.id, class: 'field-label' }, '节点访问范围'), mode, choices, scopeHint);
  const updateScope = () => { choices.hidden = mode.value !== 'restricted'; scopeHint.textContent = mode.value === 'restricted' ? '仅选择的节点可供此成员使用；未选择时没有节点访问权限。' : '共享所有启用节点，包括以后新启用的节点。'; };
  mode.addEventListener('change', updateScope);
  updateScope();
  const body = h('div', { class: 'form-grid' },
    field('成员名称', input('name', member?.name ?? '', { maxlength: 255, placeholder: '例如：小林' })),
    field('邮箱（可选）', input('email', member?.email ?? '', { type: 'email', maxlength: 64, placeholder: '用于标识，不创建登录账户' })),
    scope,
    field('流量硬上限（GiB）', input('quota', gibValue(member?.transfer_enable), { type: 'number', min: '0', step: 'any', placeholder: '留空表示不限' }), { hint: '额度按节点倍率累计，实际流量单独统计。0 表示没有可用额度；留空表示不限。' }),
    field('有效期', input('expired_at', dateInput(member?.expired_at), { type: 'datetime-local' }), { hint: '留空表示永久有效，时间使用当前设备时区。' }),
    field('速度限制（Mbps）', input('speed_limit', member?.speed_limit ?? '', { type: 'number', min: 0, step: 1, placeholder: '不限' })),
    field('设备限制', input('device_limit', member?.device_limit ?? '', { type: 'number', min: 0, step: 1, placeholder: '不限' })),
    field('备注', textarea('remarks', member?.remarks ?? '', { placeholder: '仅管理员可见' }), { wide: true }));
  openDialog({
    title: member ? '编辑成员' : '添加成员', description: '成员名称或邮箱至少填写一项。订阅凭据由系统自动生成。', content: body, large: true,
    submitLabel: member ? '保存更改' : '创建成员',
    onSubmit: async form => data(await context.api.post(member ? 'user/update' : 'user/create', memberPayload(formValues(form), new FormData(form).getAll('server_ids'), member?.id))),
    onSuccess: result => { toast(member ? '成员配置已更新' : '成员已创建'); reload(); if (!member && result?.subscribe_url) memberSubscription(result, context, reload); },
  });
}

function memberSubscription(member, context, reload) {
  const format = select('client-format', [
    { value: '', label: '通用订阅' }, { value: 'meta', label: 'Clash Meta / Mihomo' }, { value: 'sing-box', label: 'sing-box' }, { value: 'surge', label: 'Surge' },
  ], '');
  const address = textarea('subscription-address', member.subscribe_url ?? '', { readonly: true, rows: 3, class: 'input mono' });
  format.addEventListener('change', () => { try { address.value = subscriptionUrl(member.subscribe_url, format.value); } catch (error) { toast(error.message, 'error'); } });
  const state = memberStatus(member);
  const content = h('div', { class: 'stack-lg' },
    h('div', { class: 'subscription-title' }, h('span', { class: 'member-avatar' }, String(member.name || member.email || 'M').slice(0, 1).toUpperCase()), h('div', {}, h('h3', {}, member.name || member.email), badge(state.label, state.tone))),
    notice('订阅链接包含此成员的访问凭据，请只分享给对应成员。'),
    field('客户端格式', format), field('订阅地址', address),
    button('复制订阅链接', { icon: 'copy', tone: 'primary', onClick: () => copyText(address.value, '订阅链接已复制') }),
    h('dl', { class: 'detail-grid' },
      h('div', {}, h('dt', {}, '有效期'), h('dd', {}, member.expired_at ? datetime(member.expired_at) : '永久有效')),
      h('div', {}, h('dt', {}, '节点范围'), h('dd', {}, member.access_mode === 'restricted' ? `指定 ${member.server_ids?.length ?? 0} 个节点` : '共享全部启用节点')),
      h('div', {}, h('dt', {}, '实际上传'), h('dd', {}, bytes(member.actual_u ?? 0))),
      h('div', {}, h('dt', {}, '实际下载'), h('dd', {}, bytes(member.actual_d ?? 0))),
      h('div', { class: 'detail-wide' }, h('dt', {}, '节点 UUID'), h('dd', { class: 'mono copy-line' }, member.uuid, iconButton('复制节点 UUID', 'copy', () => copyText(member.uuid))))),
    h('section', { class: 'danger-section' }, h('h3', {}, '轮换访问凭据'), h('p', {}, '重新生成订阅 token 和节点 UUID。旧订阅将失效，成员需要重新导入新链接。'),
      button('轮换凭据', { icon: 'key', tone: 'danger-outline', onClick: () => openDialog({
        title: '轮换成员凭据', content: h('p', { class: 'confirm-copy' }, `确认轮换“${member.name || member.email}”的订阅和节点凭据？旧链接将无法继续更新。`), submitLabel: '确认轮换', danger: true,
        onSubmit: async () => data(await context.api.post('user/resetSecret', { id: member.id })),
        onSuccess: updated => { details.close(); toast('凭据已轮换，请重新分享订阅'); reload(); memberSubscription(updated, context, reload); },
      }) })));
  const details = openDialog({ title: '成员订阅', content });
}
