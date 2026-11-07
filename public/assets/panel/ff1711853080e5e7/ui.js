import { errorMessage } from './format.js';

export function h(tag, attributes = {}, ...children) {
  const element = document.createElement(tag);
  for (const child of children.flat(Infinity)) {
    if (child === null || child === undefined || child === false) continue;
    element.append(child instanceof Node ? child : document.createTextNode(String(child)));
  }
  for (const [key, value] of Object.entries(attributes ?? {})) {
    if (value === undefined || value === null || value === false) continue;
    if (key.startsWith('on') && typeof value === 'function') element.addEventListener(key.slice(2).toLowerCase(), value);
    else if (key === 'class') element.className = value;
    else if (key === 'value' || key === 'checked' || key === 'disabled') element[key] = value;
    else element.setAttribute(key, value === true ? '' : String(value));
  }
  return element;
}

const paths = {
  overview: ['M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z'],
  server: ['M4 3h16v7H4zM4 14h16v7H4z', 'M8 6.5h.01M8 17.5h.01M12 6.5h5M12 17.5h5'],
  users: ['M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2', 'M16 3a4 4 0 0 1 0 8M22 21v-2a4 4 0 0 0-3-3.87', 'M9 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8'],
  logs: ['M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z', 'M14 2v6h6M8 13h8M8 17h5'],
  settings: ['M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8', 'M9 2h6l.7 3 2.4 1.4 3-.9 3 5.2-2.3 2.1v2.8l2.3 2.1-3 5.2-3-.9-2.4 1.4-.7 3H9l-.7-3-2.4-1.4-3 .9-3-5.2L2.2 13v-2.8L-.1 8.1l3-5.2 3 .9L8.3 2.4z'],
  plus: ['M12 5v14M5 12h14'],
  refresh: ['M20 11a8 8 0 0 0-14-5L3 9M3 3v6h6M4 13a8 8 0 0 0 14 5l3-3M15 15h6v6'],
  chevron: ['m9 5 7 7-7 7'],
  down: ['m6 9 6 6 6-6'],
  arrow: ['M7 17 17 7M7 7h10v10'],
  copy: ['M9 9h12v12H9zM5 15H3V3h12v2'],
  edit: ['m16 3 5 5M4 20l4-1L21 6a2 2 0 0 0-3-3L5 16z'],
  trash: ['M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7M14 10v7'],
  close: ['m6 6 12 12M6 18 18 6'],
  check: ['m5 12 4 4L19 6'],
  warning: ['m12 3 10 18H2zM12 9v4M12 17h.01'],
  info: ['M12 8h.01M12 11v6', 'M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0'],
  lock: ['M5 10h14v11H5zM8 10V6a4 4 0 0 1 8 0v4M12 14v3'],
  power: ['M12 2v10M5.6 5.6a9 9 0 1 0 12.8 0'],
  logout: ['M9 3H3v18h6M9 12h12m-4-4 4 4-4 4'],
  download: ['M12 3v12m-5-5 5 5 5-5M3 16v5h18v-5'],
  upload: ['M12 16V4m-5 5 5-5 5 5M3 16v5h18v-5'],
  search: ['M21 21l-6-6', 'M17 9a8 8 0 1 1-16 0 8 8 0 0 1 16 0'],
  eye: ['M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12', 'M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0'],
  key: ['M15 7a5 5 0 1 0 0 10 5 5 0 0 0 0-10', 'm11 16-8 7M6 20l-2-2M8 18l-2-2'],
  globe: ['M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0M2 12h20', 'M12 2a18 18 0 0 1 0 20 18 18 0 0 1 0-20'],
  clock: ['M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0M12 6v6l4 2'],
  terminal: ['m4 6 6 6-6 6M12 18h8'],
  activity: ['M2 12h4l3-9 6 18 3-9h4'],
  shield: ['m12 2 9 4v7c0 5-9 9-9 9s-9-4-9-9V6z', 'm8 12 3 3 5-6'],
  link: ['m10 13 4-4M8 16l-2 2a4 4 0 0 1-6-6l4-4a4 4 0 0 1 6 0M14 8l2-2a4 4 0 0 1 6 6l-4 4a4 4 0 0 1-6 0'],
};

export function icon(name, className = '') {
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  for (const [key, value] of Object.entries({ viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', 'stroke-width': '1.65', 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'aria-hidden': 'true', class: `icon ${className}` })) svg.setAttribute(key, value);
  const shape = name === 'settings' ? ['M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8', 'M9 3h6l1 3 3 1 2 5-2 5-3 1-1 3H9l-1-3-3-1-2-5 2-5 3-1z'] : (paths[name] ?? paths.info);
  for (const d of shape) {
    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', d);
    svg.append(path);
  }
  return svg;
}

export function button(label, { icon: iconName, tone = 'secondary', className = '', onClick, ...attributes } = {}) {
  return h('button', { type: 'button', class: `button button-${tone} ${className}`, onClick, ...attributes }, iconName ? icon(iconName) : null, label);
}

export function iconButton(label, iconName, onClick, tone = '') {
  return h('button', { type: 'button', class: `icon-button ${tone}`, title: label, 'aria-label': label, onClick }, icon(iconName));
}

export function badge(label, tone = 'muted') {
  return h('span', { class: `badge badge-${tone}` }, h('span', { class: 'badge-dot' }), label);
}

export function input(name, value = '', attributes = {}) {
  return h('input', { name, id: `field-${name}`, value: value ?? '', class: 'input', ...attributes });
}

export function select(name, options, value, attributes = {}) {
  return h('select', { name, id: `field-${name}`, class: 'input', value: value ?? '', ...attributes },
    options.map(option => h('option', { value: option.value }, option.label)));
}

export function field(label, control, { hint, wide = false, required = false } = {}) {
  const target = control.matches('input,select,textarea') ? control : control.querySelector('input,select,textarea');
  return h('div', { class: `field ${wide ? 'field-wide' : ''}` },
    h('label', { for: target?.id || undefined, class: 'field-label' }, label, required ? h('span', { class: 'required', 'aria-hidden': 'true' }, ' *') : null),
    control, hint ? h('p', { class: 'field-hint' }, hint) : null);
}

export function checkbox(name, label, checked = false, hint = '') {
  return h('label', { class: 'check-field' },
    input(name, '1', { type: 'checkbox', checked }),
    h('span', {}, h('span', { class: 'check-title' }, label), hint ? h('small', {}, hint) : null));
}

export function textarea(name, value = '', attributes = {}) {
  return h('textarea', { name, id: `field-${name}`, class: 'input', rows: 3, value: value ?? '', ...attributes });
}

export function secretInput(name, value = '', attributes = {}) {
  const control = input(name, value, { type: 'password', autocomplete: 'off', ...attributes });
  const toggle = iconButton('显示或隐藏', 'eye', () => { control.type = control.type === 'password' ? 'text' : 'password'; });
  return h('div', { class: 'secret-input', id: `control-${name}` }, control, toggle);
}

export function formValues(form) {
  const values = Object.fromEntries(new FormData(form));
  for (const control of form.querySelectorAll('input[type="checkbox"][name]')) {
    if (control.name !== 'server_ids') values[control.name] = control.checked;
  }
  return values;
}

export function pageHeader(title, description, actions = []) {
  return h('div', { class: 'page-heading' }, h('div', {}, h('h1', {}, title), h('p', {}, description)), h('div', { class: 'page-actions' }, actions));
}

export function panel(title, body, { description, action, className = '' } = {}) {
  return h('section', { class: `panel ${className}` },
    title ? h('div', { class: 'panel-heading' }, h('div', {}, h('h2', {}, title), description ? h('p', {}, description) : null), action ?? null) : null,
    body);
}

export function notice(message, tone = 'info') {
  return h('div', { class: `notice notice-${tone}`, role: tone === 'error' ? 'alert' : 'status' }, icon(tone === 'error' || tone === 'warning' ? 'warning' : 'info'), h('div', {}, message));
}

export function loading(label = '正在读取数据…') {
  return h('div', { class: 'loading-state', role: 'status', 'aria-live': 'polite' }, h('span', { class: 'spinner' }), label);
}

export function emptyState(title, description, action = null, iconName = 'server') {
  return h('div', { class: 'empty-state' }, h('span', { class: 'empty-icon' }, icon(iconName)), h('h3', {}, title), h('p', {}, description), action);
}

export function errorState(error, retry) {
  return h('div', { class: 'error-state', role: 'alert' }, icon('warning'), h('div', {}, h('strong', {}, '数据暂时无法读取'), h('p', {}, errorMessage(error))), retry ? button('重新加载', { icon: 'refresh', onClick: retry }) : null);
}

export function table(columns, rows, { className = '' } = {}) {
  return h('div', { class: `table-wrap ${className}` }, h('table', {},
    h('thead', {}, h('tr', {}, columns.map(column => h('th', { scope: 'col', class: column.className ?? '' }, column.label)))),
    h('tbody', {}, rows.map(row => h('tr', {}, columns.map(column => h('td', { 'data-label': column.label, class: column.className ?? '' }, column.render(row))))))));
}

export function pagination({ current = 1, total = 0, perPage = 20, onChange }) {
  const pages = Math.max(1, Math.ceil(total / perPage));
  return h('div', { class: 'pagination' }, h('span', {}, `共 ${total} 条 · 第 ${current} / ${pages} 页`),
    h('div', { class: 'inline-actions' },
      button('上一页', { disabled: current <= 1, onClick: () => onChange(current - 1) }),
      button('下一页', { disabled: current >= pages, onClick: () => onChange(current + 1) })));
}

export function toast(message, tone = 'success') {
  let region = document.getElementById('toast-region');
  if (!region) {
    region = h('div', { id: 'toast-region', class: 'toast-region', 'aria-live': 'polite' });
    document.body.append(region);
  }
  const item = h('div', { class: `toast toast-${tone}`, role: tone === 'error' ? 'alert' : 'status' }, icon(tone === 'error' ? 'warning' : 'check'), h('span', {}, message));
  while (region.children.length >= 3) region.firstElementChild.remove();
  region.append(item);
  setTimeout(() => item.remove(), tone === 'error' ? 7000 : 4000);
}

export async function copyText(text, message = '已复制') {
  try {
    if (navigator.clipboard?.writeText) await navigator.clipboard.writeText(String(text));
    else {
      const control = h('textarea', { class: 'clipboard-fallback', value: String(text) });
      document.body.append(control);
      control.select();
      const copied = document.execCommand('copy');
      control.remove();
      if (!copied) throw new Error('copy');
    }
    toast(message);
  } catch { toast('浏览器未允许复制，请选择内容后手动复制。', 'error'); }
}

export async function runAction(control, action) {
  if (control.disabled) return;
  control.disabled = true;
  control.setAttribute('aria-busy', 'true');
  try { return await action(); }
  catch (error) { toast(errorMessage(error), 'error'); }
  finally { control.disabled = false; control.removeAttribute('aria-busy'); }
}

export function toggleSwitch(label, checked, onChange) {
  let value = Boolean(checked);
  const control = h('button', { type: 'button', class: 'toggle', role: 'switch', 'aria-label': label, 'aria-checked': String(value), title: label }, h('span'));
  control.addEventListener('click', () => runAction(control, async () => {
    await onChange(!value);
    value = !value;
    control.setAttribute('aria-checked', String(value));
  }));
  return control;
}

export function openDialog({ title, description, content, submitLabel = '保存', onSubmit, onSuccess, large = false, danger = false }) {
  const errors = h('div', { class: 'dialog-error' });
  const submit = onSubmit ? button(submitLabel, { tone: danger ? 'danger' : 'primary', type: 'submit' }) : null;
  let busy = false;
  const dialog = h('dialog', { class: `dialog ${large ? 'dialog-large' : ''}`, 'aria-labelledby': 'dialog-title-' + (document.querySelectorAll('dialog').length + 1) });
  const close = () => { if (!busy) dialog.close(); };
  const form = h('form', { class: 'dialog-form' },
    h('header', { class: 'dialog-heading' }, h('div', {}, h('h2', { id: dialog.getAttribute('aria-labelledby') }, title), description ? h('p', {}, description) : null), iconButton('关闭', 'close', close)),
    h('div', { class: 'dialog-body' }, content, errors),
    h('footer', { class: 'dialog-footer' }, button(onSubmit ? '取消' : '关闭', { onClick: close }), submit));
  const api = { dialog, form, close };
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (!onSubmit || busy) return;
    busy = true;
    errors.replaceChildren();
    submit.disabled = true;
    submit.setAttribute('aria-busy', 'true');
    const originalLabel = submit.textContent;
    submit.replaceChildren(h('span', { class: 'spinner' }), '正在处理…');
    try {
      const result = await onSubmit(form, api);
      busy = false;
      dialog.close();
      onSuccess?.(result);
    } catch (error) {
      errors.replaceChildren(notice(errorMessage(error), 'error'));
      errors.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    } finally {
      busy = false;
      submit.disabled = false;
      submit.removeAttribute('aria-busy');
      submit.textContent = originalLabel;
    }
  });
  dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
  dialog.addEventListener('close', () => dialog.remove(), { once: true });
  dialog.append(form);
  document.body.append(dialog);
  dialog.showModal();
  setTimeout(() => form.querySelector('input:not([type="checkbox"]):not([type="hidden"]), textarea, select')?.focus(), 0);
  return api;
}

export function confirmAction({ title, description, label = '确认', onConfirm, danger = true }) {
  return openDialog({ title, content: h('p', { class: 'confirm-copy' }, description), submitLabel: label, danger, onSubmit: onConfirm });
}

export function tabs(items, selected, onChange) {
  return h('div', { class: 'tabs', role: 'tablist' }, items.map(item => button(item.label, {
    className: selected === item.value ? 'tab selected' : 'tab', role: 'tab', 'aria-selected': String(selected === item.value),
    onClick: () => onChange(item.value),
  })));
}
