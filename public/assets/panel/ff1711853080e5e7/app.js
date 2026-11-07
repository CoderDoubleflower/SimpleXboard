import { Api } from './api.js';
import { h, icon, button, loading, notice, runAction } from './ui.js';
import { data, errorMessage } from './format.js';
import { overviewPage } from './pages/overview.js';
import { nodesPage } from './pages/nodes.js';
import { membersPage } from './pages/members.js';
import { logsPage } from './pages/logs.js';
import { settingsPage } from './pages/settings.js';

const bootstrap = JSON.parse(document.getElementById('panel-bootstrap').textContent);
const root = document.getElementById('app');
const storageKey = 'simplexboard:admin-session';
const store = {
  read() { try { return sessionStorage.getItem(storageKey) || ''; } catch { return ''; } },
  write(value) { try { if (value) sessionStorage.setItem(storageKey, value); else sessionStorage.removeItem(storageKey); } catch {} },
};
const pages = [
  { id: 'overview', label: '概览', icon: 'overview', render: overviewPage },
  { id: 'nodes', label: 'VPS 与节点', icon: 'server', render: nodesPage },
  { id: 'members', label: '成员与订阅', icon: 'users', render: membersPage },
  { id: 'logs', label: '日志与备份', icon: 'logs', render: logsPage },
  { id: 'settings', label: '设置', icon: 'settings', render: settingsPage },
];
let profile = null;
let pageAbort;
let content;
let breadcrumb;
let links = [];
const api = new Api(bootstrap.secure_path, { token: store.read(), onUnauthorized: () => {
  if (api.token) signOut('登录已失效，请重新登录。');
} });

function signOut(message = '') {
  pageAbort?.abort();
  api.token = '';
  store.write('');
  profile = null;
  for (const dialog of document.querySelectorAll('dialog')) { dialog.close(); dialog.remove(); }
  loginPage(message);
}

function logo() {
  return h('span', { class: 'brand-symbol', 'aria-hidden': 'true' }, h('span'), h('span'), h('span'));
}

function loginPage(message = '') {
  root.replaceChildren();
  const errors = h('div', { class: 'login-message' }, message ? notice(message) : null);
  const email = h('input', { class: 'input', id: 'login-email', name: 'email', type: 'email', autocomplete: 'username', required: true, placeholder: 'admin@example.com' });
  const password = h('input', { class: 'input', id: 'login-password', name: 'password', type: 'password', autocomplete: 'current-password', required: true, placeholder: '输入管理员密码' });
  const submit = button('登录管理台', { tone: 'primary', type: 'submit', icon: 'arrow' });
  const form = h('form', { class: 'login-form' },
    h('div', { class: 'field' }, h('label', { for: 'login-email', class: 'field-label' }, '管理员邮箱'), email),
    h('div', { class: 'field' }, h('label', { for: 'login-password', class: 'field-label' }, '密码'), password), errors, submit);
  form.addEventListener('submit', event => {
    event.preventDefault();
    runAction(submit, async () => {
      errors.replaceChildren();
      try {
        const result = data(await api.login(email.value.trim(), password.value));
        if (!result?.auth_data) throw new Error('登录响应缺少会话凭据。');
        api.token = result.auth_data;
        store.write(api.token);
        profile = data(await api.get('auth/me'));
        shell();
      } catch (error) {
        errors.replaceChildren(notice(errorMessage(error), 'error'));
        password.value = '';
        password.focus();
      }
    });
  });
  root.append(h('main', { class: 'login-layout' },
    h('section', { class: 'login-intro' },
      h('div', { class: 'brand login-brand' }, logo(), h('span', {}, 'SimpleXboard')),
      h('div', { class: 'login-intro-copy' }, h('p', { class: 'eyebrow' }, 'PRIVATE NETWORK'), h('h1', {}, '自己的节点，', h('br'), '清楚地掌控。'), h('p', {}, '在一个地方管理 VPS、节点与成员订阅。轻量、直接，为你的私人网络服务。')),
      h('div', { class: 'login-footnote' }, h('span', { class: 'small-dot' }), '管理员专用 · 独立成员订阅')),
    h('section', { class: 'login-card-wrap' }, h('div', { class: 'login-card' }, h('span', { class: 'login-lock' }, icon('lock')), h('h2', {}, '登录管理台'), h('p', { class: 'muted' }, bootstrap.title || 'SimpleXboard'), form,
      h('p', { class: 'login-note' }, '成员通过专属订阅链接连接，无需登录此管理台。')))));
  document.title = `登录 · ${bootstrap.title || 'SimpleXboard'}`;
}

function navigate(target) {
  const hash = `#/${target}`;
  if (location.hash === hash) renderPage();
  else location.hash = hash;
}

function shell() {
  pageAbort?.abort();
  const logout = button('退出', { icon: 'logout', tone: 'quiet', onClick: event => runAction(event.currentTarget, async () => {
    await api.post('auth/logout');
    signOut();
  }) });
  links = pages.map(page => h('a', { href: `#/${page.id}`, class: 'nav-link', 'data-page': page.id }, icon(page.icon), h('span', {}, page.label)));
  breadcrumb = h('span', { class: 'breadcrumb-current' }, '概览');
  content = h('div', { id: 'page-content', class: 'page-content', tabindex: '-1' });
  root.replaceChildren(h('div', { class: 'app-shell' },
    h('aside', { class: 'sidebar' },
      h('a', { href: '#/overview', class: 'brand', 'aria-label': 'SimpleXboard 概览' }, logo(), h('span', {}, 'SimpleXboard')),
      h('div', { class: 'sidebar-label' }, '管理控制台'),
      h('nav', { class: 'main-nav', 'aria-label': '主导航' }, links),
      h('div', { class: 'sidebar-footer' }, h('div', { class: 'instance-label' }, h('span', { class: 'small-dot' }), '私有实例'), h('span', { class: 'build-label' }, bootstrap.version ? `版本 ${bootstrap.version}` : `构建 ${String(bootstrap.build || '').slice(0, 8)}`))),
    h('div', { class: 'workspace' },
      h('header', { class: 'topbar' }, h('div', { class: 'breadcrumb' }, h('span', { class: 'breadcrumb-root' }, bootstrap.title || 'SimpleXboard'), icon('chevron'), breadcrumb),
        h('div', { class: 'topbar-actions' }, button('刷新', { icon: 'refresh', tone: 'quiet', onClick: () => renderPage() }), h('span', { class: 'topbar-divider' }), h('div', { class: 'account-label', title: profile.email }, h('span', { class: 'avatar' }, String(profile.name || profile.email || 'A').slice(0, 1).toUpperCase()), h('span', {}, '管理员')), logout)),
      h('main', { class: 'main-content' }, content))));
  renderPage();
}

async function renderPage() {
  if (!profile || !content) return;
  const [requested, query = ''] = location.hash.replace(/^#\/?/, '').split('?');
  const page = pages.find(item => item.id === requested) || pages[0];
  for (const link of links) {
    const selected = link.dataset.page === page.id;
    link.classList.toggle('selected', selected);
    if (selected) link.setAttribute('aria-current', 'page'); else link.removeAttribute('aria-current');
  }
  breadcrumb.textContent = page.label;
  document.title = `${page.label} · ${bootstrap.title || 'SimpleXboard'}`;
  pageAbort?.abort();
  pageAbort = new AbortController();
  const signal = pageAbort.signal;
  const host = h('div', { class: 'page', 'data-page': page.id });
  content.replaceChildren(host);
  window.scrollTo({ top: 0 });
  const context = { api, profile, bootstrap, signal, query: new URLSearchParams(query), navigate, refresh: renderPage, signOut };
  try { await page.render(host, context); }
  catch (error) { if (!signal.aborted) host.append(notice(errorMessage(error), 'error')); }
}

window.addEventListener('hashchange', () => { if (profile) renderPage(); });
root.replaceChildren(loading('正在验证管理员会话…'));
if (!api.token) loginPage();
else {
  try { profile = data(await api.get('auth/me')); shell(); }
  catch (error) { if (api.token) loginPage(errorMessage(error)); }
}
