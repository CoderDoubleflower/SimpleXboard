import { h, icon, button, badge, panel, pageHeader, loading, emptyState, errorState, notice, table } from '../ui.js';
import { bytes, number, data, nodeStatus, protocolName, relativeTime } from '../format.js';

function metric(label, value, detail, iconName) {
  return h('article', { class: 'metric' }, h('div', { class: 'metric-top' }, h('span', {}, label), icon(iconName)), h('div', { class: 'metric-value' }, value), h('div', { class: 'metric-detail' }, detail));
}

export async function overviewPage(host, context) {
  const { api, signal, navigate, refresh } = context;
  const dashboard = h('div', { class: 'dashboard' }, loading());
  host.append(pageHeader('概览', '查看节点运行情况、成员连接与实际用量。', [button('添加节点', { icon: 'plus', tone: 'primary', onClick: () => navigate('nodes?create=node') })]), dashboard);
  const [statistics, servers, system] = await Promise.allSettled([
    api.get('stat/getStats', {}, { signal }), api.get('server/manage/getNodes', {}, { signal }), api.get('system/getSystemStatus', {}, { signal }),
  ]);
  if (signal.aborted) return;
  dashboard.replaceChildren();
  const nodes = servers.status === 'fulfilled' ? data(servers.value) : null;
  if (statistics.status === 'fulfilled') {
    const stats = data(statistics.value);
    dashboard.append(h('div', { class: 'metrics-grid' },
      metric('在线节点', h('span', {}, number(stats.onlineNodes), h('small', {}, ` / ${number(stats.enabledNodes ?? nodes?.filter(node => node.enabled !== false).length)}`)), '在线 / 已启用', 'server'),
      metric('启用成员', number(stats.activeUsers), `共 ${number(stats.totalUsers)} 位成员`, 'users'),
      metric('今日实际流量', bytes(stats.todayTraffic?.total), `↑ ${bytes(stats.todayTraffic?.upload)}  ↓ ${bytes(stats.todayTraffic?.download)}`, 'activity'),
      metric('在线成员', number(stats.onlineUsers), `${number(stats.onlineDevices)} 台在线设备`, 'globe')));
  } else dashboard.append(errorState(statistics.reason, refresh));

  const left = h('div', { class: 'dashboard-primary' });
  const right = h('div', { class: 'dashboard-secondary' });
  dashboard.append(h('div', { class: 'dashboard-columns' }, left, right));

  if (nodes) {
    const enabled = nodes.filter(node => node.enabled !== false && node.enabled !== 0);
    const offline = enabled.filter(node => !node.is_online);
    if (offline.length) left.append(notice(`${offline.length} 个启用节点尚未上报有效心跳。请检查 VPS 中的节点服务与网络连接。`, 'warning'));
    left.append(panel('节点运行状态', nodes.length ? table([
      { label: '节点', render: node => h('div', { class: 'row-identity' }, h('strong', {}, node.name), h('span', { class: 'mono muted' }, `${node.host}:${node.port}`)) },
      { label: '协议', render: node => h('span', { class: 'protocol-tag' }, protocolName(node)) },
      { label: '状态', render: node => { const status = nodeStatus(node); return h('div', { class: 'stack-sm' }, badge(status.label, status.tone), h('small', { class: 'muted' }, relativeTime(node.last_check_at))); } },
    ], nodes.slice(0, 6)) : emptyState('开始接入你的节点', '添加 VPS 和节点连接参数后，这里会显示真实的运行状态。', button('管理 VPS 与节点', { tone: 'primary', icon: 'plus', onClick: () => navigate('nodes') })), {
      description: `${nodes.length} 个节点 · 仅显示最近上报的状态`,
      action: button('查看全部', { tone: 'quiet', icon: 'chevron', onClick: () => navigate('nodes') }),
    }));
  } else left.append(panel('节点运行状态', errorState(servers.reason, refresh)));

  if (statistics.status === 'fulfilled') {
    const stats = data(statistics.value);
    const trafficRows = [
      ['今天', stats.todayTraffic], ['本月', stats.monthTraffic], ['累计实际用量', stats.totalTraffic],
    ];
    const summary = trafficRows.map(([label, value]) => h('div', { class: 'traffic-summary-row' },
      h('div', {}, h('span', {}, label), h('small', { class: 'muted' }, `↑ ${bytes(value?.upload)} · ↓ ${bytes(value?.download)}`)),
      h('strong', {}, bytes(value?.total))));
    right.append(panel('用量汇总', h('div', { class: 'traffic-summary' }, summary)));
    if (stats.trafficAccounting) dashboard.append(h('p', { class: 'accounting-note' }, icon('info'), typeof stats.trafficAccounting === 'string' ? stats.trafficAccounting : (stats.trafficAccounting.description || stats.trafficAccounting.legacy_note || '流量统计使用节点上报的实际字节。')));
  }
  if (system.status === 'fulfilled') {
    const status = data(system.value);
    const check = (label, enabled) => h('div', { class: 'health-row' }, h('span', {}, label), badge(status.degraded ? '暂不可读' : enabled ? '运行中' : '未运行', status.degraded ? 'muted' : enabled ? 'success' : 'warning'));
    right.append(panel('运行检查', h('div', { class: 'health-list' }, check('计划任务', status.schedule), check('队列服务', status.horizon),
      h('p', { class: 'field-hint' }, status.degraded ? '服务状态读取失败。节点与成员数据仍可独立查看。' : '队列和计划任务负责节点同步、流量处理与维护。'),
      button('查看日志与备份', { tone: 'quiet', icon: 'chevron', onClick: () => navigate('logs') }))));
  } else right.append(panel('运行检查', errorState(system.reason, refresh)));
  dashboard.append(h('p', { class: 'updated-at' }, `页面更新于 ${new Intl.DateTimeFormat('zh-CN', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false }).format(new Date())}`));
}
