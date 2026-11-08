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

function brandCopy() {
  return h('span', { class: 'brand-copy' }, h('strong', {}, 'SimpleXboard'), h('small', {}, '个人网络控制台'));
}

function loginNetwork() {
  return h('div', { class: 'login-network', 'aria-hidden': 'true' },
    h('div', { class: 'network-hub' }, icon('lock'), h('span', {}, '控制台')),
    h('div', { class: 'network-branches' }, [
      ['server', 'VPS'], ['globe', '节点'], ['users', '成员'],
    ].map(([symbol, label]) => h('div', { class: 'network-node' },
      h('span', { class: 'network-node-icon' }, icon(symbol)), h('span', {}, label)))));
}

function loginPage(message = '') {
  root.replaceChildren();
  const errors = h('div', { class: 'login-message' }, message ? notice(message) : null);
  const email = h('input', { class: 'input', id: 'login-email', name: 'email', type: 'email', autocomplete: 'username', autocapitalize: 'none', spellcheck: 'false', required: true, placeholder: 'admin@example.com' });
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
    h('section', { class: 'login-intro', 'aria-labelledby': 'login-intro-title' },
      h('div', { class: 'brand login-brand' }, logo(), brandCopy()),
      h('div', { class: 'login-intro-copy' },
        h('p', { class: 'eyebrow' }, 'PRIVATE NETWORK'),
        h('h1', { id: 'login-intro-title' }, '自己的网络，', h('br'), '清楚地掌控。'),
        h('p', {}, 'VPS、节点与成员订阅，在同一个地方管理。')),
      loginNetwork(),
      h('div', { class: 'login-footnote' }, icon('shield'), h('span', {}, '管理员专用 · 独立成员订阅'))),
    h('section', { class: 'login-card-wrap', 'aria-labelledby': 'login-title' },
      h('div', { class: 'login-card' },
        h('div', { class: 'login-card-heading' },
          h('span', { class: 'login-lock', 'aria-hidden': 'true' }, icon('lock')),
          h('div', {}, h('p', { class: 'login-kicker' }, '管理员入口'), h('h2', { id: 'login-title' }, '登录管理台'))),
        h('p', { class: 'login-description' }, '使用管理员账户访问 ', h('strong', {}, bootstrap.title || 'SimpleXboard'), '。'),
        form,
        h('p', { class: 'login-note' }, '成员使用专属订阅链接，无需登录管理台。')))));
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
  breadcrumb = h('span', { id: 'current-page-label', class: 'breadcrumb-current' }, '概览');
  content = h('div', { id: 'page-content', class: 'page-content', role: 'region', 'aria-label': '页面内容', tabindex: '-1' });
  const skipLink = h('a', { class: 'skip-link', href: '#page-content', onClick: event => {
    // The app routes by hash; focus the region without replacing that route.
    event.preventDefault();
    content.focus();
  } }, '跳至主要内容');
  root.replaceChildren(h('div', { class: 'app-shell' },
    skipLink,
    h('aside', { class: 'sidebar' },
      h('a', { href: '#/overview', class: 'brand', 'aria-label': 'SimpleXboard 概览' }, logo(), brandCopy()),
      h('div', { class: 'sidebar-label' }, '工作区'),
      h('nav', { class: 'main-nav', 'aria-label': '主导航' }, links),
      h('div', { class: 'sidebar-footer' },
        h('div', { class: 'instance-label' }, icon('lock'), h('span', {}, '私有实例')),
        h('p', { class: 'instance-description' }, '成员使用独立订阅'),
        h('span', { class: 'build-label' }, bootstrap.version ? `版本 ${bootstrap.version}` : `构建 ${String(bootstrap.build || '').slice(0, 8)}`))),
    h('div', { class: 'workspace' },
      h('header', { class: 'topbar' },
        h('div', { class: 'workspace-context' },
          h('span', { class: 'workspace-caption' }, '管理控制台'),
          h('div', { class: 'breadcrumb' }, h('span', { class: 'breadcrumb-root' }, bootstrap.title || 'SimpleXboard'), icon('chevron'), breadcrumb)),
        h('div', { class: 'topbar-actions' },
          button('刷新', { icon: 'refresh', tone: 'quiet', title: '刷新当前页面', onClick: () => renderPage() }),
          h('span', { class: 'topbar-divider', 'aria-hidden': 'true' }),
          h('div', { class: 'account-label', title: profile.email },
            h('span', { class: 'avatar', 'aria-hidden': 'true' }, String(profile.name || profile.email || 'A').slice(0, 1).toUpperCase()),
            h('span', { class: 'account-copy' },
              h('span', { class: 'account-name' }, profile.name || '管理员'),
              h('span', { class: 'account-email' }, profile.email))), logout)),
      h('main', { class: 'main-content', 'aria-labelledby': 'current-page-label' }, content))));
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
