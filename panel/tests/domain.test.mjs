import test from 'node:test';
import assert from 'node:assert/strict';
import { memberPayload, nodePayload, quotaBytes, gibValue, jsonObject, trafficRate, PATH_PATTERN } from '../src/domain.js';
import { bytes, memberStatus, protocolName, subscriptionUrl } from '../src/format.js';

test('path patterns work with the HTML pattern Unicode sets flag', () => {
  const pattern = new RegExp(`^(?:${PATH_PATTERN})$`, 'v');
  assert.equal(pattern.test('private-panel_01'), true);
  assert.equal(pattern.test('s'), true);
  assert.equal(pattern.test('bad/path'), false);
  assert.equal(pattern.test('bad path'), false);
});

test('member defaults are passwordless, unlimited, permanent and share enabled nodes', () => {
  const member = memberPayload({ name: '  家人  ' });
  assert.deepEqual(member, { name: '家人', email: null, transfer_enable: null, expired_at: null,
    speed_limit: null, device_limit: null, access_mode: 'all', server_ids: [], remarks: null });
  assert.equal(Object.hasOwn(member, 'password'), false);
  assert.throws(() => memberPayload({ name: ' ', email: '' }), /名称或邮箱/);
});

test('member zero quota is preserved distinctly from unlimited', () => {
  assert.equal(memberPayload({ email: 'friend@example.test', quota: '0' }).transfer_enable, 0);
  assert.equal(quotaBytes(''), null);
  assert.equal(quotaBytes('1.5'), 1610612736);
  assert.equal(gibValue(null), '');
  assert.equal(gibValue(0), '0');
  assert.throws(() => quotaBytes('-1'), /格式无效/);
  assert.throws(() => quotaBytes('1e100'), /过大/);
});

test('restricted access serializes direct server ids and preserves member id', () => {
  const member = memberPayload({ name: 'Guest', access_mode: 'restricted', expired_at: '2030-01-02T12:00', remarks: ' hello ' }, ['3', '4', '3'], 12);
  assert.deepEqual(member.server_ids, [3, 4]);
  assert.equal(member.id, 12);
  assert.equal(member.remarks, 'hello');
  assert.ok(member.expired_at > 0);
  assert.deepEqual(memberPayload({ name: 'Guest', access_mode: 'all' }, [3]).server_ids, []);
  assert.throws(() => memberPayload({ name: 'Guest', expired_at: 'invalid' }), /到期时间/);
});

test('quota status uses charged usage independently of actual and legacy traffic', () => {
  assert.equal(memberStatus({ transfer_enable: 100, u: 40, d: 60, actual_u: 0, actual_d: 0 }).label, '额度用尽');
  assert.equal(memberStatus({ transfer_enable: 100, u: 40, d: 60, actual_u: 20, actual_d: 30 }).label, '额度用尽');
  assert.equal(memberStatus({ transfer_enable: 100, u: 20, d: 30, actual_u: 40, actual_d: 60 }).label, '已启用');
  assert.equal(memberStatus({ transfer_enable: null, u: 1e12, d: 1e12 }).label, '已启用');
  assert.equal(memberStatus({ transfer_enable: 0, u: 0, d: 0 }).label, '额度用尽');
  assert.equal(memberStatus({ banned: true, transfer_enable: null }).label, '已停用');
  assert.equal(bytes(null), '—');
  assert.equal(bytes(0), '0 B');
  assert.equal(bytes(1073741824), '1 GiB');
  assert.equal(bytes(0.25).includes('undefined'), false);
});

const commonNode = { name: ' Tokyo ', host: 'node.example.test', port: '443', server_port: '8443', enabled: true, show: false, machine_id: '5', tags: ' jp, private ', quota: '', advanced_json: '{}' };

test('node rates default to one and keep an existing fractional or zero rate', () => {
  const values = { ...commonNode, type: 'tuic', protocol_json: '{}' };
  assert.equal(nodePayload(values).rate, 1);
  assert.equal(nodePayload(values, { id: 6, rate: '0.50' }).rate, 0.5);
  assert.equal(nodePayload(values, { id: 6, rate: 0 }).rate, 0);
  assert.equal(nodePayload({ ...values, rate: '2' }, { id: 6, rate: 0 }).rate, 2);
});

test('fixed multipliers preserve fractional precision and do not scale node limits', () => {
  for (const [value, expected] of [['.5', 0.5], ['0.50', 0.5], ['2', 2], ['0', 0], [0, 0], ['1.25', 1.25], ['0.07', 0.07], ['100.00', 100]]) {
    assert.equal(trafficRate(value), expected);
    const payload = nodePayload({ ...commonNode, type: 'tuic', protocol_json: '{}', quota: '1', rate: value });
    assert.equal(payload.rate, expected);
    assert.equal(payload.transfer_enable, 1073741824);
  }
});

test('invalid rates are rejected instead of clamped or rounded', () => {
  for (const value of ['', ' ', '-0.01', '100.01', '0.001', '1.000', '2.345', 'NaN', 'Infinity', '1e2', null, true, [], {}, NaN, Infinity]) {
    assert.throws(() => nodePayload({ ...commonNode, type: 'tuic', protocol_json: '{}', rate: value }), /流量倍率须为 0–100，最多保留 2 位小数/);
  }
});

test('VLESS REALITY editor preserves extension settings and separates enable/show', () => {
  const current = { id: 6, type: 'vless', route_ids: [2], group_ids: [8], rate: 3 };
  const payload = nodePayload({ ...commonNode, type: 'vless', vless_tls: '2', network: 'tcp', flow: 'xtls-rprx-vision',
    reality_server_name: 'www.example.test', reality_server_port: '443', reality_public_key: 'public', reality_private_key: 'private', reality_short_id: 'aabb',
    protocol_json: JSON.stringify({ mux: { enabled: true }, reality_settings: { extra: 'preserved' } }),
    advanced_json: JSON.stringify({ cert_config: { provider: 'manual' }, group_ids: [9], rate: 8 }),
  }, current);
  assert.equal(payload.id, 6);
  assert.equal(payload.enabled, true);
  assert.equal(payload.show, false);
  assert.equal(payload.machine_id, 5);
  assert.equal(payload.port, '443');
  assert.equal(payload.server_port, 8443);
  assert.equal(payload.transfer_enable, null);
  assert.deepEqual(payload.tags, ['jp', 'private']);
  assert.deepEqual(payload.route_ids, [2]);
  assert.equal(payload.protocol_settings.reality_settings.extra, 'preserved');
  assert.equal(payload.protocol_settings.reality_settings.private_key, 'private');
  assert.equal(payload.protocol_settings.mux.enabled, true);
  assert.equal(Object.hasOwn(payload, 'group_ids'), false);
  assert.equal(payload.rate, 3);
  assert.equal(protocolName(payload), 'VLESS · REALITY');
});

test('Hysteria2 uses the real hysteria protocol contract', () => {
  const node = nodePayload({ ...commonNode, type: 'hysteria', protocol_json: '{"extra":"keep"}', hy_version: '2',
    tls_server_name: 'hy.example.test', tls_insecure: false, hy_obfs: true, hy_obfs_password: 'obfs-key', hy_up: '100', hy_down: '200', hy_hop: '30',
    cert_mode: 'self', cert_domain: 'hy.example.test' });
  assert.equal(node.type, 'hysteria');
  assert.equal(node.protocol_settings.version, 2);
  assert.deepEqual(node.protocol_settings.tls, { server_name: 'hy.example.test', allow_insecure: false });
  assert.deepEqual(node.protocol_settings.obfs, { open: true, type: 'salamander', password: 'obfs-key' });
  assert.deepEqual(node.protocol_settings.bandwidth, { up: 100, down: 200 });
  assert.equal(node.protocol_settings.extra, 'keep');
  assert.equal(protocolName(node), 'Hysteria2');
});

test('existing other protocols and custom outbound data round-trip through JSON', () => {
  const node = nodePayload({ ...commonNode, type: 'tuic', protocol_json: '{"congestion_control":"bbr","alpn":["h3"]}', advanced_json: '{"custom_outbounds":[{"type":"direct"}]}' }, { id: 8, type: 'tuic' });
  assert.deepEqual(node.protocol_settings, { congestion_control: 'bbr', alpn: ['h3'] });
  assert.deepEqual(node.custom_outbounds, [{ type: 'direct' }]);
  assert.throws(() => jsonObject('[1]'), /必须是一个对象/);
  assert.throws(() => jsonObject('{bad}'), /格式无效/);
});

test('subscription format updates preserve token queries without unsafe schemes', () => {
  assert.equal(subscriptionUrl('https://sub.example/s/token?x=1', 'mihomo'), 'https://sub.example/s/token?x=1&flag=mihomo');
  assert.equal(subscriptionUrl('https://sub.example/s/token?flag=clash', 'stash'), 'https://sub.example/s/token?flag=stash');
  assert.equal(subscriptionUrl('https://sub.example/s/token'), 'https://sub.example/s/token?flag=mihomo');
  assert.throws(() => subscriptionUrl('https://sub.example/s/token', 'singbox'), /仅支持/);
  assert.throws(() => subscriptionUrl('https://sub.example/s/token', 'surge'), /仅支持/);
  assert.throws(() => subscriptionUrl('javascript:alert(1)'), /格式无效/);
});
