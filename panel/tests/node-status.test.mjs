import test from 'node:test';
import assert from 'node:assert/strict';
import { nodeStatus, nodeConnections } from '../src/format.js';

test('heartbeats and control connectivity never imply proxy readiness', () => {
  const node = { enabled: true, is_online: 1, last_check_at: Date.now() / 1000,
    machine_online: true, control_online: true };
  assert.deepEqual(nodeStatus(node), { label: '核心状态未知', tone: 'warning' });
  assert.deepEqual(nodeConnections(node), { machine: '机器在线', control: 'WebSocket 在线' });
  assert.equal(nodeStatus({ ...node, core_status: { state: 'stopped' } }).label, '核心未运行');
  assert.equal(nodeStatus({ ...node, core_status: { state: 'error' } }).tone, 'danger');
});

test('only explicit core readiness is shown as ready', () => {
  assert.deepEqual(nodeStatus({ core_ready: true, core_status: { state: 'running' } }), { label: '核心就绪', tone: 'success' });
  assert.equal(nodeStatus({ core_ready: false, core_status: { state: 'running' } }).label, '核心未就绪');
  assert.equal(nodeStatus({ core_ready: false, core_status: { state: 'stale', ready: true } }).label, '核心状态过期');
  assert.equal(nodeStatus({ enabled: false, core_ready: true }).label, '已停用');
  assert.equal(nodeStatus({ enabled: 0, core_ready: true }).label, '已停用');
});

test('empty users and pending configuration have distinct non-error states', () => {
  assert.deepEqual(nodeStatus({ core_status: { state: 'waiting_users' } }), { label: '等待用户', tone: 'muted' });
  assert.equal(nodeStatus({ core_status: { state: 'waiting_config' } }).label, '等待配置');
  assert.equal(nodeStatus({ core_status: { state: 'starting' } }).label, '核心启动中');
});

test('machine and control connectivity remain independent of core readiness', () => {
  assert.deepEqual(nodeConnections({ machine_online: true, control_online: false, core_ready: true }),
    { machine: '机器在线', control: 'WebSocket 断开' });
  assert.deepEqual(nodeConnections({ machine_online: false, control_online: true, core_ready: false }),
    { machine: '机器离线', control: 'WebSocket 在线' });
  assert.equal(nodeConnections({ machine_online: null }).machine, '未关联机器');
  assert.equal(nodeConnections({}).control, '控制通道未知');
});
