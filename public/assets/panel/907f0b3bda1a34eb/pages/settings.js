import { h, icon, button, input, select, field, textarea, checkbox, secretInput, formValues, pageHeader, panel, loading, errorState, notice, table, badge, toast, runAction, confirmAction, tabs, emptyState } from '../ui.js';
import { data, datetime, errorMessage } from '../format.js';
import { PATH_PATTERN } from '../domain.js';

export async function settingsPage(host, context) {
  const { api, signal, refresh } = context;
  const content = h('div', {}, loading('正在读取设置…'));
  host.append(pageHeader('设置', '管理管理员安全、订阅入口和必要系统参数。'), content);
  let config;
  try { config = data(await api.get('config/fetch', {}, { signal })); }
  catch (error) { if (!signal.aborted) content.replaceChildren(errorState(error, refresh)); return; }
  if (signal.aborted) return;
  const tabHost = h('div');
  const general = generalSettings(config, context);
  const security = securitySettings(config, context);
  const templates = templateSettings(config, context);
  const sections = { general, security, templates };
  let active = ['general', 'security', 'templates'].includes(context.query.get('tab')) ? context.query.get('tab') : 'general';
  function show() {
    tabHost.replaceChildren(tabs([{ value: 'general', label: '基础设置' }, { value: 'security', label: '管理员安全' }, { value: 'templates', label: '订阅模板' }], active, value => { active = value; show(); }));
    for (const [id, section] of Object.entries(sections)) section.hidden = active !== id;
  }
  content.replaceChildren(tabHost, general, security, templates);
  show();
}

function settingsForm(context, sections, submitLabel = '保存设置') {
  const errors = h('div');
  const submit = button(submitLabel, { tone: 'primary', type: 'submit', icon: 'check' });
  const form = h('form', { class: 'settings-form' }, sections, errors, h('div', { class: 'settings-form-footer' }, h('p', { class: 'field-hint' }, '更改仅在保存后生效。'), submit));
  form.addEventListener('submit', event => {
    event.preventDefault();
    runAction(submit, async () => {
      errors.replaceChildren();
      const values = formValues(form);
      for (const key of ['server_pull_interval', 'server_push_interval', 'password_limit_count', 'password_limit_expire']) if (Object.hasOwn(values, key)) values[key] = Number(values[key]);
      for (const key of Object.keys(values)) if (typeof values[key] === 'string') values[key] = values[key].trim() || null;
      try {
        const response = data(await context.api.post('config/save', values));
        toast('设置已保存');
        if (values.app_name) {
          context.bootstrap.title = values.app_name;
          const label = document.querySelector('.breadcrumb-root');
          if (label) label.textContent = values.app_name;
          document.title = `设置 · ${values.app_name}`;
        }
        if (response?.admin_url) {
          const url = new URL(response.admin_url, location.origin);
          if (url.origin !== location.origin) throw new Error('后台跳转地址与当前站点不一致，请手动打开新的后台入口。');
          if (url.pathname.replace(/\/$/, '') !== location.pathname.replace(/\/$/, '')) location.assign(url.pathname + '#/settings?tab=security');
        }
      } catch (error) { errors.replaceChildren(notice(errorMessage(error), 'error')); }
    });
  });
  return form;
}

function generalSettings(config, context) {
  const site = config.site ?? {};
  const subscribe = config.subscribe ?? {};
  const server = config.server ?? {};
  const basics = panel('实例与订阅', h('div', { class: 'panel-body form-grid' },
    field('实例名称', input('app_name', site.app_name ?? 'SimpleXboard', { required: true, maxlength: 80 }), { required: true }),
    field('面板地址', input('app_url', site.app_url ?? '', { type: 'url', placeholder: 'https://panel.example.com' }), { hint: '机器接入命令使用此地址。' }),
    field('订阅入口地址', input('subscribe_url', site.subscribe_url ?? '', { placeholder: 'https://sub.example.com' }), { wide: true, hint: '留空使用当前站点；多个入口用英文逗号分隔。可使用独立的 Cloudflare 代理域名。' }),
    field('订阅路径', input('subscribe_path', subscribe.subscribe_path ?? 's', { required: true, maxlength: 64, pattern: PATH_PATTERN, placeholder: 's' }), { hint: '例如 s，对应 /s/成员令牌。修改后需向成员分享新链接。' }),
    h('div', { class: 'stack-md align-end' }, checkbox('force_https', '强制 HTTPS', Boolean(site.force_https)), checkbox('show_protocol_to_server_enable', '在订阅节点名称中显示协议', Boolean(subscribe.show_protocol_to_server_enable)))));
  const transport = panel('节点通信', h('div', { class: 'panel-body stack-lg' },
    h('div', { class: 'form-grid' },
      field('节点通信令牌', secretInput('server_token', server.server_token ?? '', { minlength: 16, maxlength: 256 }), { wide: true, hint: '供兼容节点接入使用。修改后需要更新对应节点的配置。' }),
      field('配置拉取间隔（秒）', input('server_pull_interval', server.server_pull_interval ?? 60, { type: 'number', min: 10, max: 3600, step: 1, required: true })),
      field('用量上报间隔（秒）', input('server_push_interval', server.server_push_interval ?? 60, { type: 'number', min: 10, max: 3600, step: 1, required: true })),
      checkbox('server_ws_enable', '启用节点 WebSocket 通信', Boolean(server.server_ws_enable)),
      field('WebSocket 地址', input('server_ws_url', server.server_ws_url ?? '', { placeholder: 'wss://panel.example.com/…' }), { wide: true, hint: '保留现有节点通信地址；留空使用服务端默认值。' }))), { description: '设置节点配置拉取、用量上报和实时通信。' });
  return settingsForm(context, [basics, transport]);
}

function securitySettings(config, context) {
  const safe = config.safe ?? {};
  const profile = context.profile;
  const passwordErrors = h('div');
  const passwordSubmit = button('更新密码并退出登录', { tone: 'primary', type: 'submit', icon: 'lock' });
  const passwordForm = h('form', { class: 'panel-body stack-lg' },
    field('当前密码', input('old_password', '', { type: 'password', autocomplete: 'current-password', required: true, maxlength: 256 }), { required: true }),
    field('新密码', input('new_password', '', { type: 'password', autocomplete: 'new-password', required: true, minlength: 12, maxlength: 256 }), { required: true, hint: '至少 12 个字符。更新后会撤销全部管理员会话。' }),
    field('再次输入新密码', input('new_password_confirmation', '', { type: 'password', autocomplete: 'new-password', required: true, minlength: 12, maxlength: 256 }), { required: true }),
    passwordErrors, passwordSubmit);
  passwordForm.addEventListener('submit', event => {
    event.preventDefault();
    runAction(passwordSubmit, async () => {
      passwordErrors.replaceChildren();
      const values = formValues(passwordForm);
      if (values.new_password !== values.new_password_confirmation) { passwordErrors.append(notice('两次输入的新密码不一致。', 'error')); return; }
      try { await context.api.post('auth/password', values); context.signOut('密码已更新，请重新登录。'); }
      catch (error) { passwordErrors.replaceChildren(notice(errorMessage(error), 'error')); }
    });
  });
  const sessionList = h('div', {}, loading('正在读取管理员会话…'));
  const revoke = button('撤销全部会话', { tone: 'danger-outline', icon: 'logout', onClick: () => confirmAction({ title: '撤销全部管理员会话', description: '所有设备（包括当前设备）都会退出登录。此操作不会改变成员订阅。', label: '撤销并退出', onConfirm: async () => { await context.api.post('auth/revokeSessions'); context.signOut('全部会话已撤销，请重新登录。'); } }) });
  async function loadSessions() {
    sessionList.replaceChildren(loading());
    try {
      const sessions = data(await context.api.get('auth/sessions', {}, { signal: context.signal })) ?? [];
      if (context.signal.aborted) return;
      sessionList.replaceChildren(sessions.length ? table([
        { label: '会话', render: session => h('div', { class: 'stack-sm' }, h('span', { class: 'mono small' }, `#${session.id}`), session.is_current ? badge('当前会话', 'success') : badge('其他设备')) },
        { label: '创建 / 最近使用', render: session => h('div', { class: 'stack-sm' }, h('span', {}, datetime(session.created_at)), h('small', { class: 'muted' }, session.last_used_at ? datetime(session.last_used_at) : '尚未使用')) },
        { label: '到期时间', render: session => datetime(session.expires_at) },
      ], sessions) : emptyState('暂无管理员会话', '重新登录后会显示新的会话记录。', null, 'lock'));
    } catch (error) { if (!context.signal.aborted) sessionList.replaceChildren(errorState(error, loadSessions)); }
  }
  loadSessions();
  const access = settingsForm(context, [panel('登录与入口保护', h('div', { class: 'panel-body form-grid' },
    field('后台访问路径', input('secure_path', safe.secure_path ?? context.bootstrap.secure_path, { required: true, minlength: 8, maxlength: 64, pattern: PATH_PATTERN, placeholder: 'private-admin' }), { wide: true, hint: '修改后会自动跳转到新入口，当前管理员会话保留。' }),
    checkbox('safe_mode_enable', '限制面板访问域名', Boolean(safe.safe_mode_enable), '仅接受“面板地址”中配置的域名。'),
    checkbox('password_limit_enable', '限制连续密码错误', safe.password_limit_enable !== false && safe.password_limit_enable !== 0),
    field('允许的连续错误次数', input('password_limit_count', safe.password_limit_count ?? 5, { type: 'number', min: 1, max: 20, step: 1, required: true })),
    field('临时锁定时间（分钟）', input('password_limit_expire', safe.password_limit_expire ?? 60, { type: 'number', min: 1, max: 1440, step: 1, required: true }))))], '保存安全设置');
  return h('div', { class: 'stack-lg' },
    panel(null, h('div', { class: 'admin-profile' }, h('span', { class: 'member-avatar' }, String(profile.name || profile.email || 'A').slice(0, 1).toUpperCase()), h('div', {}, h('h3', {}, profile.email), h('p', { class: 'muted' }, `管理员 · 最近登录 ${datetime(profile.last_login_at)}`)), badge('唯一管理入口', 'success'))),
    h('div', { class: 'security-grid' }, panel('更改管理员密码', passwordForm), panel('登录会话', h('div', {}, sessionList, h('div', { class: 'panel-footer' }, revoke)))), access);
}

function templateSettings(config, context) {
  const formats = [{ value: 'singbox', label: 'sing-box' }, { value: 'clashmeta', label: 'Clash Meta / Mihomo' }, { value: 'clash', label: 'Clash' }, { value: 'stash', label: 'Stash' }, { value: 'surge', label: 'Surge' }, { value: 'surfboard', label: 'Surfboard' }];
  const originals = { ...(config.subscribe_template ?? {}) };
  const drafts = { ...originals };
  let selected = 'singbox';
  const selector = select('template-format', formats, selected);
  const editor = textarea('template-content', originals.subscribe_template_singbox ?? '', { rows: 22, spellcheck: false, class: 'input mono template-editor', 'aria-label': '订阅模板内容' });
  const errors = h('div');
  selector.addEventListener('change', () => {
    drafts[`subscribe_template_${selected}`] = editor.value;
    selected = selector.value;
    editor.value = drafts[`subscribe_template_${selected}`] ?? '';
    errors.replaceChildren();
  });
  const save = button('保存当前模板', { tone: 'primary', icon: 'check', onClick: event => runAction(event.currentTarget, async () => {
    errors.replaceChildren();
    const key = `subscribe_template_${selected}`;
    try {
      await context.api.post('config/save', { [key]: editor.value });
      originals[key] = editor.value;
      drafts[key] = editor.value;
      toast('订阅模板已保存');
    } catch (error) { errors.replaceChildren(notice(errorMessage(error), 'error')); }
  }) });
  return panel('客户端订阅模板', h('div', { class: 'panel-body stack-lg' },
    notice('模板决定客户端收到的完整配置。通常可以保留现有内容，需要时再修改对应格式。'),
    h('div', { class: 'template-toolbar' }, field('客户端格式', selector), button('放弃当前修改', { onClick: () => { editor.value = originals[`subscribe_template_${selected}`] ?? ''; errors.replaceChildren(); } })),
    editor, errors, h('div', { class: 'settings-form-footer' }, h('p', { class: 'field-hint' }, '只保存当前选择的模板格式。'), save)));
}
