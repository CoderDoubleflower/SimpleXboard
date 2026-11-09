import { h, icon, button, iconButton, input, pageHeader, panel, loading, emptyState, errorState, notice, table, pagination, toast, runAction, openDialog, copyText, textarea, field, tabs } from '../ui.js';
import { data, bytes, datetime } from '../format.js';

const actions = {
  'user.create': '创建成员', 'user.update': '更新成员', 'user.ban': '成员启停', 'user.destroy': '删除成员', 'user.resetSecret': '轮换成员凭据',
  'server.manage.save': '保存节点', 'server.manage.update': '更新节点', 'server.manage.drop': '删除节点',
  'server.machine.save': '保存 VPS', 'server.machine.resetToken': '轮换机器令牌', 'server.machine.drop': '删除 VPS',
  'config.save': '更新设置', 'backup.create': '创建备份', 'auth.password': '更改管理员密码', 'auth.revokeSessions': '撤销会话',
};

function readableJson(value) {
  if (typeof value !== 'string') return JSON.stringify(value ?? {}, null, 2);
  try { return JSON.stringify(JSON.parse(value), null, 2); } catch { return value; }
}

export async function logsPage(host, context) {
  const { api, signal } = context;
  let current = 1;
  let failedCurrent = 1;
  let active = ['audit', 'backups', 'failed'].includes(context.query.get('tab')) ? context.query.get('tab') : 'audit';
  const tabsHost = h('div');
  const audit = h('div');
  const backup = h('div');
  const failed = h('div');
  const auditList = h('div', {}, loading());
  const backupList = h('div', {}, loading());
  const failedList = h('div', {}, loading());
  const restoreHelp = h('div');
  const keyword = input('audit-keyword', '', { type: 'search', placeholder: '搜索操作路径或记录内容', 'aria-label': '搜索操作日志' });
  const toolbar = h('form', { class: 'toolbar', onSubmit: event => { event.preventDefault(); current = 1; loadAudit(); } }, h('div', { class: 'search-field' }, icon('search'), keyword), button('查询', { type: 'submit' }), h('span', { class: 'toolbar-spacer' }), h('span', { class: 'toolbar-note' }, '敏感请求字段由服务端脱敏'));
  audit.append(panel(null, h('div', {}, toolbar, auditList)));
  const create = button('立即备份', { tone: 'primary', icon: 'download', onClick: event => runAction(event.currentTarget, async () => {
    const response = data(await api.post('backup/create', {}, { timeout: 120000 }));
    toast(response?.file?.name ? `备份已创建：${response.file.name}` : '备份已创建');
    await loadBackups();
  }) });
  backup.append(notice('备份包含数据库中的成员与访问凭据，请妥善保管下载文件。'), panel('数据库备份', backupList, { description: '在重要变更前创建备份，并下载到你控制的安全位置。', action: create }), restoreHelp);
  failed.append(panel('失败任务', failedList, { description: '查看队列执行失败的任务和错误摘要。', action: button('刷新任务', { icon: 'refresh', onClick: () => loadFailed() }) }));
  const body = h('div', {}, audit, backup, failed);
  host.append(pageHeader('日志与备份', '查看管理员操作记录，保留数据库恢复所需的备份。'), tabsHost, body);

  function renderTab() {
    tabsHost.replaceChildren(tabs([{ value: 'audit', label: '操作日志' }, { value: 'backups', label: '数据库备份' }, { value: 'failed', label: '失败任务' }], active, value => { active = value; renderTab(); }));
    audit.hidden = active !== 'audit';
    backup.hidden = active !== 'backups';
    failed.hidden = active !== 'failed';
  }

  async function loadAudit() {
    auditList.replaceChildren(loading('正在读取操作日志…'));
    try {
      const response = await api.get('system/getAuditLog', { current, page_size: 20, keyword: keyword.value.trim() }, { signal });
      if (signal.aborted) return;
      const entries = data(response) ?? [];
      auditList.replaceChildren(entries.length ? table([
        { label: '时间', render: entry => h('span', { class: 'nowrap' }, datetime(entry.created_at)) },
        { label: '操作', render: entry => h('div', { class: 'row-identity' }, h('strong', {}, actions[entry.action] ?? entry.action ?? '管理员操作'), h('span', { class: 'mono muted log-uri' }, `${entry.method ?? ''} ${entry.uri ?? ''}`)) },
        { label: '管理员', render: entry => entry.admin?.email || `管理员 #${entry.admin_id}` },
        { label: '来源 IP', render: entry => h('span', { class: 'mono' }, entry.ip || '—') },
        { label: '详情', className: 'actions-cell', render: entry => button('查看', { tone: 'quiet', onClick: () => openDialog({ title: '操作记录详情', large: true, content: h('div', { class: 'stack-lg' },
          h('dl', { class: 'detail-grid' }, h('div', {}, h('dt', {}, '操作'), h('dd', {}, entry.action)), h('div', {}, h('dt', {}, '时间'), h('dd', {}, datetime(entry.created_at))), h('div', { class: 'detail-wide' }, h('dt', {}, '请求路径'), h('dd', { class: 'mono wrap' }, `${entry.method} ${entry.uri}`))),
          field('请求内容', textarea('audit-detail', readableJson(entry.request_data), { readonly: true, rows: 12, class: 'input mono code-input' }))) }) }) },
      ], entries) : emptyState('暂无操作记录', keyword.value ? '没有符合当前搜索条件的日志。' : '后续管理员操作会在这里保留审计记录。', null, 'logs'));
      if (response.total > 20 || current > 1) auditList.append(pagination({ current, total: response.total, perPage: 20, onChange: page => { current = page; loadAudit(); } }));
    } catch (error) { if (!signal.aborted) auditList.replaceChildren(errorState(error, loadAudit)); }
  }

  async function loadBackups() {
    backupList.replaceChildren(loading('正在读取备份…'));
    try {
      const response = data(await api.get('backup/list', {}, { signal }));
      if (signal.aborted) return;
      const files = response.files ?? [];
      backupList.replaceChildren(files.length ? table([
        { label: '备份文件', render: file => h('span', { class: 'mono backup-name' }, file.name) },
        { label: '创建时间', render: file => datetime(file.created_at) },
        { label: '大小', render: file => bytes(file.size) },
        { label: '操作', className: 'actions-cell', render: file => button('下载', { icon: 'download', tone: 'quiet', onClick: event => runAction(event.currentTarget, () => api.download('backup/download', file.name, { params: { name: file.name } })) }) },
      ], files) : emptyState('还没有备份', '在修改重要配置或升级前，先创建一份数据库备份。', null, 'logs'));
      restoreHelp.replaceChildren(panel('恢复说明', h('div', { class: 'panel-body stack-lg' },
        h('p', { class: 'muted' }, '恢复会覆盖当前数据库，需要在服务器终端按说明执行。先确认备份文件与当前实例对应。'),
        h('pre', { class: 'restore-instructions' }, response.restore_command || '请参阅项目中的数据库恢复文档。'),
        response.restore_command ? button('复制恢复说明', { icon: 'copy', onClick: () => copyText(response.restore_command, '恢复说明已复制') }) : null)));
    } catch (error) { if (!signal.aborted) backupList.replaceChildren(errorState(error, loadBackups)); }
  }

  async function loadFailed() {
    failedList.replaceChildren(loading('正在读取失败任务…'));
    try {
      const response = await api.get('system/getHorizonFailedJobs', { current: failedCurrent, page_size: 20 }, { signal });
      if (signal.aborted) return;
      const entries = data(response) ?? [];
      if (response.degraded) {
        failedList.replaceChildren(h('div', { class: 'panel-body' }, notice(response.message || '队列存储暂不可用，无法读取失败任务。', 'warning')));
        return;
      }
      failedList.replaceChildren(entries.length ? table([
        { label: '失败时间', render: entry => h('span', { class: 'nowrap' }, datetime(entry.failed_at)) },
        { label: '任务', render: entry => h('div', { class: 'row-identity' }, h('strong', {}, entry.name || '未命名任务'), h('span', { class: 'mono muted' }, `${entry.connection || '—'} / ${entry.queue || '—'}`)) },
        { label: '错误摘要', render: entry => h('span', { class: 'wrap' }, String(entry.exception || '未记录错误信息').split('\n')[0].slice(0, 240)) },
        { label: '详情', className: 'actions-cell', render: entry => button('查看错误', { tone: 'quiet', onClick: () => openDialog({ title: '任务错误详情', large: true, content: h('div', { class: 'stack-lg' },
          h('p', { class: 'mono wrap' }, entry.name || entry.id),
          field('异常信息', textarea('failed-exception', entry.exception || '未记录错误信息', { readonly: true, rows: 16, class: 'input mono code-input' }))) }) }) },
      ], entries) : emptyState('暂无失败任务', '当前队列中没有记录到失败任务。', null, 'check'));
      if (response.total > 20 || failedCurrent > 1) failedList.append(pagination({ current: failedCurrent, total: response.total, perPage: 20, onChange: page => { failedCurrent = page; loadFailed(); } }));
    } catch (error) { if (!signal.aborted) failedList.replaceChildren(errorState(error, loadFailed)); }
  }
  renderTab();
  await Promise.allSettled([loadAudit(), loadBackups(), loadFailed()]);
}
