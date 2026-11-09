import test from 'node:test';
import assert from 'node:assert/strict';
import { nodePayload } from '../src/domain.js';
import { outboundPayload, outboundFormValues, hasCustomRouting, nodeOutboundLabel, outboundAddress, outboundProtocolName } from '../src/outbounds.js';

const UUID = '279d4f89-3a2c-488d-a67c-2d39a72acdde';
const shared = { server: 'exit.example.test', server_port: 443 };
const tls = { enabled: true, insecure: false, server_name: 'tls.example.test', alpn: ['h2', 'http/1.1'] };
const imported = (type, settings) => ({ name: `Existing ${type}`, type, settings: { ...shared, ...settings } });
const save = source => outboundPayload(outboundFormValues(source), source);

test('VLESS REALITY credentials round-trip as outbound credentials without private keys or TCP-only target restrictions', () => {
  const source = { id: 7, ...imported('vless', {
    uuid: UUID, flow: 'xtls-rprx-vision',
    tls: { ...tls, utls: { enabled: true, fingerprint: 'chrome' }, reality: { enabled: true, public_key: 'a'.repeat(43), short_id: 'aabb' } },
  }), notes: 'Japan' };
  const result = save(source);
  assert.deepEqual(result, source);
  assert.equal(Object.hasOwn(result.settings, 'network'), false);
  assert.equal(Object.hasOwn(result.settings.tls.reality, 'private_key'), false);
  assert.equal(Object.hasOwn(result.settings, 'proxy_tag'), false);
});

test('switching VLESS to TLS removes REALITY fields and preserves an explicitly empty Flow', () => {
  const source = imported('vless', { uuid: UUID, tls: { ...tls, reality: { enabled: true, public_key: 'a'.repeat(43), short_id: 'aabb' } } });
  const result = outboundPayload({ ...outboundFormValues(source), security: 'tls', utls_enabled: false, flow: '', tls_insecure: true });
  assert.deepEqual(result.settings, { ...shared, uuid: UUID, tls: { ...tls, insecure: true } });
  assert.equal(outboundFormValues(imported('vless', { uuid: UUID, tls })).flow, '');
});

test('Hysteria2 keeps authentication, obfuscation and bandwidth independent of VLESS-only fields', () => {
  const source = imported('hysteria2', { password: '  auth:@ key  ', tls: { ...tls, alpn: ['h3'] }, obfs: { type: 'salamander', password: ' obfs:key ' }, up_mbps: 100, down_mbps: 200 });
  assert.deepEqual(save(source).settings, source.settings);
  const result = outboundPayload({ ...outboundFormValues(source), obfs_enabled: false, up_mbps: '', down_mbps: '', uuid: UUID, flow: 'xtls-rprx-vision', public_key: 'a'.repeat(43) });
  assert.deepEqual(result.settings, { ...shared, password: source.settings.password, tls: source.settings.tls });
});

test('SOCKS5 supports anonymous and authenticated exits without adding TLS', () => {
  for (const credentials of [{}, { username: ' user ', password: ' p:a@ss ' }]) {
    const source = imported('socks', { version: '5', ...credentials });
    assert.deepEqual(save(source).settings, source.settings);
  }
  for (const credentials of [{ username: 'user' }, { password: 'pass' }]) {
    assert.throws(() => save(imported('socks', credentials)), /用户名与密码须同时填写/);
  }
  assert.throws(() => save(imported('socks', { username: '名'.repeat(86), password: 'pass' })), error => error.field === 'username' && /255 字节/.test(error.message));
  assert.equal(outboundProtocolName({ type: 'socks' }), 'SOCKS5');
});

test('Shadowsocks preserves the complete method and password including identity-key separators', () => {
  const source = imported('shadowsocks', { method: '2022-blake3-aes-128-gcm', password: 'c2VydmVyLWtleS0xMjM0NQ==:dXNlci1rZXktMTIzNDU2Nw==' });
  assert.deepEqual(save(source).settings, source.settings);
  assert.throws(() => save(imported('shadowsocks', { method: 'unsupported-cipher', password: 'secret' })), /支持的 Shadowsocks 加密方式/);
});

test('Trojan round-trips TLS and uTLS while excluding unrelated REALITY or Flow values', () => {
  const source = imported('trojan', { password: ' secret ', tls: { ...tls, utls: { enabled: true, fingerprint: 'firefox' } } });
  const result = outboundPayload({ ...outboundFormValues(source), flow: 'xtls-rprx-vision', security: 'reality', public_key: 'a'.repeat(43) });
  assert.deepEqual(result.settings, source.settings);
});

test('VMess preserves encryption and Alter ID with either TLS or a plain TCP transport', () => {
  for (const transport of [{}, { tls: { ...tls, utls: { enabled: true, fingerprint: 'chrome' } } }]) {
    const source = imported('vmess', { uuid: UUID, security: 'aes-128-gcm', alter_id: 0, ...transport });
    assert.deepEqual(save(source).settings, source.settings);
  }
  const source = imported('vmess', { uuid: UUID, security: 'auto', alter_id: 0, tls });
  const result = outboundPayload({ ...outboundFormValues(source), tls_enabled: false, tls_insecure: true, utls_enabled: true });
  assert.equal(Object.hasOwn(result.settings, 'tls'), false);
});

test('outbound validation identifies the field that needs correction', () => {
  const values = outboundFormValues(imported('vless', { uuid: UUID, tls }));
  const cases = [
    ['name', ''], ['server', 'https://example.test/path'], ['server_port', 65536], ['server_port', '443.5'],
    ['uuid', 'bad-uuid'], ['flow', 'unsupported'], ['security', 'none'],
  ];
  for (const [field, value] of cases) assert.throws(() => outboundPayload({ ...values, [field]: value }), error => error.field === field);
  const reality = { ...values, security: 'reality', fingerprint: 'chrome', public_key: 'a'.repeat(43), short_id: 'aabb' };
  for (const [field, value] of [['public_key', 'short'], ['short_id', 'abc'], ['short_id', 'zz'], ['server_name', '']]) {
    assert.throws(() => outboundPayload({ ...reality, [field]: value }), error => error.field === field);
  }
  const hy2 = outboundFormValues(imported('hysteria2', { password: 'secret', tls }));
  assert.throws(() => outboundPayload({ ...hy2, up_mbps: 0 }), error => error.field === 'up_mbps');
  assert.throws(() => outboundPayload({ ...hy2, down_mbps: 100001 }), error => error.field === 'down_mbps');
  assert.throws(() => outboundPayload({ ...hy2, obfs_enabled: true, obfs_password: '' }), error => error.field === 'obfs_password');
});

const node = { name: 'entry', type: 'tuic', host: 'entry.example.test', port: '443', server_port: '443', protocol_json: '{}', advanced_json: '{}' };

test('node saves retain an existing outbound when the field was not submitted and allow explicit switching or direct', () => {
  const current = { id: 3, outbound_id: 7 };
  assert.equal(nodePayload(node, current).outbound_id, 7);
  assert.equal(nodePayload({ ...node, outbound_id: '8' }, current).outbound_id, 8);
  assert.equal(nodePayload({ ...node, outbound_id: '' }, current).outbound_id, null);
  assert.equal(nodePayload(node).outbound_id, null);
});

test('managed outbounds cannot silently override any retained legacy routing', () => {
  for (const key of ['route_ids', 'custom_routes', 'custom_outbounds']) {
    const current = { id: 3, [key]: [1] };
    assert.throws(() => nodePayload({ ...node, outbound_id: '7' }, current), /高级路由配置冲突/);
    const cleared = nodePayload({ ...node, outbound_id: '7', advanced_json: JSON.stringify({ [key]: [] }) }, current);
    assert.equal(cleared.outbound_id, 7);
    assert.deepEqual(cleared[key], []);
    assert.deepEqual(nodePayload({ ...node, outbound_id: '' }, current)[key], [1]);
  }
});

test('list labels distinguish direct, managed and legacy exits even if the outbound list failed', () => {
  assert.equal(nodeOutboundLabel({}), '直连');
  assert.equal(nodeOutboundLabel({ route_ids: [1] }), '高级配置管理');
  assert.equal(nodeOutboundLabel({ outbound_id: 7 }), '出站 #7');
  assert.equal(nodeOutboundLabel({ outbound_id: 7 }, [{ id: 7, name: 'Japan exit' }]), 'Japan exit');
  assert.equal(hasCustomRouting({ custom_routes: [], custom_outbounds: [], route_ids: [], cert_config: { mode: 'manual' } }), false);
});

test('IPv6 addresses have readable port separators and are saved without brackets', () => {
  const source = imported('socks', { version: '5', server: '2001:db8::1' });
  assert.equal(outboundAddress(source), '[2001:db8::1]:443');
  assert.equal(save({ ...source, settings: { ...source.settings, server: '[2001:db8::1]' } }).settings.server, '2001:db8::1');
});
