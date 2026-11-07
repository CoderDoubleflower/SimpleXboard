import test from 'node:test';
import assert from 'node:assert/strict';
import { createRealityGenerator } from '../src/reality.js';

const pair = { public_key: 'a'.repeat(43), private_key: 'b'.repeat(43), short_id: '0123456789abcdef' };
const original = { public_key: 'existing-public', private_key: 'existing-private', short_id: 'aabb' };

function editor() {
  const calls = [];
  const values = { ...original };
  const errors = [];
  const states = [];
  let active = true;
  const generator = createRealityGenerator({
    api: { post(path, body, options) {
      return new Promise((resolve, reject) => calls.push({ path, body, ...options, resolve, reject }));
    } },
    isActive: () => active,
    onBusy: busy => states.push(busy),
    onResult: fields => Object.assign(values, fields),
    onError: error => errors.push(error),
  });
  return { generator, calls, values, errors, states, deactivate() { active = false; } };
}

test('opening an editor preserves existing values until explicit generation succeeds', async () => {
  const state = editor();
  assert.equal(state.calls.length, 0);
  assert.deepEqual(state.values, original);
  const request = state.generator.generateKeyPair();
  assert.equal(state.calls[0].path, 'server/manage/generateRealityKeyPair');
  assert.deepEqual(state.calls[0].body, {});
  assert.ok(state.calls[0].signal instanceof AbortSignal);
  assert.equal(state.generator.busy, true);
  assert.deepEqual(state.values, original);
  state.calls[0].resolve({ data: pair });
  assert.equal(await request, true);
  assert.deepEqual(state.values, pair);
  assert.deepEqual(state.states, [true, false]);
});

test('independent Short ID generation cannot overwrite public or private keys', async () => {
  const state = editor();
  const request = state.generator.generateShortId();
  assert.equal(state.calls[0].path, 'server/manage/generateShortId');
  state.calls[0].resolve({ data: pair });
  assert.equal(await request, true);
  assert.deepEqual(state.values, { ...original, short_id: pair.short_id });
});

test('both generation actions share one pending request lock', async () => {
  const state = editor();
  const request = state.generator.generateKeyPair();
  assert.equal(await state.generator.generateKeyPair(), false);
  assert.equal(await state.generator.generateShortId(), false);
  assert.equal(state.calls.length, 1);
  assert.equal(state.generator.busy, true);
  state.calls[0].resolve({ data: pair });
  await request;
  assert.equal(state.generator.busy, false);
});

test('generation failure preserves all fields and allows an immediate retry', async () => {
  const state = editor();
  const request = state.generator.generateKeyPair();
  state.calls[0].reject(new Error('服务暂时不可用'));
  assert.equal(await request, false);
  assert.equal(state.generator.busy, false);
  assert.deepEqual(state.values, original);
  assert.equal(state.errors[0].message, '服务暂时不可用');
  const retry = state.generator.generateKeyPair();
  state.calls[1].resolve({ data: pair });
  assert.equal(await retry, true);
  assert.deepEqual(state.values, pair);
});

test('incomplete or invalid results never partially replace the current credentials', async () => {
  for (const result of [null, {}, { ...pair, private_key: '' }, { ...pair, public_key: 'invalid' }, { ...pair, short_id: 'abc' }, { ...pair, short_id: 'zz' }, { ...pair, short_id: 12345678 }]) {
    const state = editor();
    const request = state.generator.generateKeyPair();
    state.calls[0].resolve({ data: result });
    assert.equal(await request, false);
    assert.deepEqual(state.values, original);
    assert.match(state.errors[0].message, /生成结果不完整或格式无效/);
  }
});

test('closing or switching the editor aborts and ignores late responses', async () => {
  for (const fails of [false, true]) {
    const state = editor();
    const request = state.generator.generateKeyPair();
    state.generator.cancel();
    assert.equal(state.calls[0].signal.aborted, true);
    assert.equal(state.generator.busy, false);
    if (fails) state.calls[0].reject(new Error('late failure'));
    else state.calls[0].resolve({ data: pair });
    assert.equal(await request, false);
    assert.deepEqual(state.values, original);
    assert.deepEqual(state.errors, []);
    assert.deepEqual(state.states, [true, false]);
  }
});

test('a cancelled request cannot unlock or overwrite a newer request', async () => {
  const state = editor();
  const stale = state.generator.generateKeyPair();
  state.generator.cancel();
  const current = state.generator.generateShortId();
  state.calls[0].resolve({ data: pair });
  assert.equal(await stale, false);
  assert.equal(state.generator.busy, true);
  assert.deepEqual(state.values, original);
  state.calls[1].resolve({ data: { short_id: 'fedcba9876543210' } });
  assert.equal(await current, true);
  assert.deepEqual(state.values, { ...original, short_id: 'fedcba9876543210' });
  assert.deepEqual(state.states, [true, false, true, false]);
});

test('an inactive form accepts no result, error or new generation request', async () => {
  for (const fails of [false, true]) {
    const state = editor();
    const request = state.generator.generateKeyPair();
    state.deactivate();
    if (fails) state.calls[0].reject(new Error('late failure'));
    else state.calls[0].resolve({ data: pair });
    assert.equal(await request, false);
    assert.deepEqual(state.values, original);
    assert.deepEqual(state.errors, []);
    assert.equal(await state.generator.generateShortId(), false);
    assert.equal(state.calls.length, 1);
    assert.equal(state.generator.busy, false);
  }
});
