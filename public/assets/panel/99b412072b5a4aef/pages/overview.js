import { h, icon, button, badge, panel, pageHeader, loading, emptyState, errorState, notice, table } from '../ui.js';
import { bytes, number, data, nodeStatus, nodeConnections, protocolName } from '../format.js';

function metric(label, value, detail, iconName, primary = false) {
  return h('article', { class: `metric${primary ? ' metric-primary' : ''}` },
    h('div', { class: 'metric-top' }, h('span', {}, label), h('span', { class: 'metric-icon' }, icon(iconName))),
    h('div', { class: 'metric-value' }, value),
    h('div', { class: 'metric-detail' }, detail));
}

function trafficSummaryRow(label, value) {
  const upload = value?.upload == null ? NaN : Number(value.upload);
  const download = value?.download == null ? NaN : Number(value.download);
  const total = upload + download;
  const available = Number.isFinite(total) && upload >= 0 && download >= 0;
  const hasTraffic = available && total > 0;
  const uploadShare = hasTraffic ? upload / total * 100 : 0;
  return h('div', { class: 'traffic-summary-row' },
    h('div', { class: 'traffic-summary-heading' }, h('span', {}, label), h('strong', {}, bytes(value?.total))),
    h('div', { class: `traffic-split${hasTraffic ? '' : ' traffic-split-empty'}`, 'aria-hidden': 'true' },
      hasTraffic ? [
        h('span', { class: 'traffic-split-upload', style: `width:${uploadShare}%` }),
        h('span', { class: 'traffic-split-download', style: `width:${100 - uploadShare}%` }),
      ] : null),
    h('div', { class: 'traffic-breakdown' }, hasTraffic ? [
      h('span', {}, icon('upload'), `上行 ${bytes(upload)}`),
      h('span', {}, icon('download'), `下行 ${bytes(download)}`),
    ] : h('small', { class: 'muted' }, available ? '暂无上报流量' : '暂无流量明细')));
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
      metric('就绪节点', h('span', {}, number(stats.readyNodes ?? nodes?.filter(node => node.core_ready === true && node.enabled !== false && node.enabled !== 0).length), h('small', {}, ` / ${number(stats.enabledNodes ?? nodes?.filter(node => node.enabled !== false && node.enabled !== 0).length)}`)), '核心就绪 / 已启用', 'server', true),
      metric('启用成员', number(stats.activeUsers), `共 ${number(stats.totalUsers)} 位成员`, 'users'),
      metric('今日实际流量', bytes(stats.todayTraffic?.total), `↑ ${bytes(stats.todayTraffic?.upload)}  ↓ ${bytes(stats.todayTraffic?.download)}`, 'activity'),
      metric('在线成员', number(stats.onlineUsers), `${number(stats.onlineDevices)} 台在线设备`, 'globe')));
  } else dashboard.append(errorState(statistics.reason, refresh));

  const left = h('div', { class: 'dashboard-primary' });
  const right = h('div', { class: 'dashboard-secondary' });
  dashboard.append(h('div', { class: 'dashboard-columns' }, left, right));

  if (nodes) {
    const enabled = nodes.filter(node => node.enabled !== false && node.enabled !== 0);
    const unready = enabled.filter(node => node.core_ready !== true);
    const warning = unready.length ? notice(`${number(unready.length)} 个启用节点的核心尚未就绪。请在节点页查看用户、控制通道和核心状态。`, 'warning') : null;
    warning?.classList.add('node-status-summary');
    const nodeTable = nodes.length ? table([
      { label: '节点', render: node => h('div', { class: 'row-identity' }, h('strong', {}, node.name), h('span', { class: 'mono muted' }, `${node.host}:${node.port}`)) },
      { label: '协议', render: node => h('span', { class: 'protocol-tag' }, protocolName(node)) },
      { label: '状态', render: node => { const status = nodeStatus(node); return h('div', { class: 'stack-sm' }, badge(status.label, status.tone), h('small', { class: 'muted' }, nodeConnections(node).control)); } },
    ], nodes.slice(0, 6)) : emptyState('开始接入你的节点', '添加 VPS 和节点连接参数后，这里会显示真实的运行状态。', button('管理 VPS 与节点', { tone: 'primary', icon: 'plus', onClick: () => navigate('nodes') }));
    left.append(panel('节点运行状态', [warning, nodeTable], {
      className: 'overview-nodes',
      description: `共 ${number(nodes.length)} 个节点 · ${number(enabled.length)} 个已启用`,
      action: button('查看全部', { tone: 'quiet', icon: 'chevron', onClick: () => navigate('nodes') }),
    }));
  } else left.append(panel('节点运行状态', errorState(servers.reason, refresh), { className: 'overview-nodes' }));

  if (system.status === 'fulfilled') {
    const status = data(system.value);
    const check = (label, enabled, iconName) => h('div', { class: 'health-row' },
      h('span', { class: 'health-identity' }, h('span', { class: 'health-icon' }, icon(iconName)), h('span', {}, label)),
      badge(status.degraded ? '暂不可读' : enabled ? '运行中' : '未运行', status.degraded ? 'muted' : enabled ? 'success' : 'warning'));
    right.append(panel('运行检查', h('div', { class: 'health-list' }, check('计划任务', status.schedule, 'clock'), check('队列服务', status.horizon, 'activity'),
      h('p', { class: 'field-hint' }, status.degraded ? '服务状态读取失败。节点与成员数据仍可独立查看。' : '负责节点同步、流量处理与日常维护。'),
      button('查看日志与备份', { tone: 'quiet', icon: 'chevron', onClick: () => navigate('logs') })), { className: 'overview-health' }));
  } else right.append(panel('运行检查', errorState(system.reason, refresh), { className: 'overview-health' }));

  if (statistics.status === 'fulfilled') {
    const stats = data(statistics.value);
    const trafficRows = [
      ['今天', stats.todayTraffic], ['本月', stats.monthTraffic], ['累计实际用量', stats.totalTraffic],
    ];
    const summary = trafficRows.map(([label, value]) => trafficSummaryRow(label, value));
    right.append(panel('用量汇总', h('div', { class: 'traffic-summary' }, summary), { className: 'overview-traffic' }));
    if (stats.trafficAccounting) dashboard.append(h('p', { class: 'accounting-note' }, icon('info'), typeof stats.trafficAccounting === 'string' ? stats.trafficAccounting : (stats.trafficAccounting.description || stats.trafficAccounting.legacy_note || '流量统计使用节点上报的实际字节。')));
  }
  dashboard.append(h('p', { class: 'updated-at' }, `页面更新于 ${new Intl.DateTimeFormat('zh-CN', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false }).format(new Date())}`));
}
