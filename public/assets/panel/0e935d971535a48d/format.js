export function bytes(value, digits = 1) {
  if (value === null || value === undefined || !Number.isFinite(Number(value))) return '—';
  const amount = Math.max(0, Number(value));
  const unit = amount > 0 ? Math.max(0, Math.min(Math.floor(Math.log(amount) / Math.log(1024)), 5)) : 0;
  return `${new Intl.NumberFormat('zh-CN', { maximumFractionDigits: unit ? digits : 0 }).format(amount / 1024 ** unit)} ${['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'][unit]}`;
}

export function number(value) {
  return value === null || value === undefined ? '—' : new Intl.NumberFormat('zh-CN').format(Number(value));
}

export function datetime(value, options = {}) {
  if (!value) return '—';
  const date = new Date(typeof value === 'number' || /^\d+$/.test(String(value)) ? Number(value) * 1000 : value);
  if (!Number.isFinite(date.getTime())) return '—';
  return new Intl.DateTimeFormat('zh-CN', {
    year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false, ...options,
  }).format(date);
}

export function relativeTime(value) {
  if (!value) return '尚无心跳';
  const seconds = Math.max(0, Math.round(Date.now() / 1000 - Number(value)));
  if (seconds < 60) return '刚刚';
  if (seconds < 3600) return `${Math.floor(seconds / 60)} 分钟前`;
  if (seconds < 86400) return `${Math.floor(seconds / 3600)} 小时前`;
  return datetime(value);
}

export function dateInput(value) {
  if (!value) return '';
  const date = new Date(Number(value) * 1000);
  date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
  return date.toISOString().slice(0, 16);
}

export function protocolName(node) {
  if (node.type === 'vless' && Number(node.protocol_settings?.tls) === 2) return 'VLESS · REALITY';
  if (node.type === 'hysteria') return Number(node.protocol_settings?.version ?? 2) === 2 ? 'Hysteria2' : 'Hysteria';
  return ({ shadowsocks: 'Shadowsocks', vmess: 'VMess', vless: 'VLESS', trojan: 'Trojan', tuic: 'TUIC', socks: 'SOCKS', anytls: 'AnyTLS', naive: 'Naïve', http: 'HTTP', mieru: 'Mieru' })[node.type] ?? node.type;
}

export function memberStatus(member) {
  if (member.banned) return { label: '已停用', tone: 'muted' };
  if (member.expired_at !== null && member.expired_at !== undefined && Number(member.expired_at) <= Date.now() / 1000) return { label: '已到期', tone: 'warning' };
  if (member.transfer_enable !== null && member.transfer_enable !== undefined && Number(member.u ?? 0) + Number(member.d ?? 0) >= Number(member.transfer_enable)) return { label: '额度用尽', tone: 'warning' };
  return member.eligible === false ? { label: '不可用', tone: 'warning' } : { label: '已启用', tone: 'success' };
}

export function nodeStatus(node) {
  if (node.enabled === false || node.enabled === 0) return { label: '已停用', tone: 'muted' };
  if (node.is_online) return { label: '在线', tone: 'success' };
  return { label: node.last_check_at ? '离线' : '等待心跳', tone: 'warning' };
}

export function data(response) {
  return response && Object.hasOwn(response, 'data') ? response.data : response;
}

export function errorMessage(error) {
  if (error?.errors && typeof error.errors === 'object') {
    const messages = Object.values(error.errors).flat().filter(value => typeof value === 'string');
    if (messages.length) return [...new Set(messages)].join('；');
  }
  return error?.message || '操作未完成，请稍后重试。';
}

export function subscriptionUrl(raw, flag = '') {
  const url = new URL(raw, globalThis.location?.origin ?? 'https://localhost');
  if (!['https:', 'http:'].includes(url.protocol)) throw new Error('订阅地址格式无效。');
  if (flag) url.searchParams.set('flag', flag);
  return url.href;
}
