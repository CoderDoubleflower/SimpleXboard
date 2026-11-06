import test from 'node:test';
import assert from 'node:assert/strict';
import { Api, ApiError } from '../src/api.js';
import { errorMessage } from '../src/format.js';

const json = (data, status = 200) => new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } });

test('private requests use the configured admin path and one Bearer prefix', async () => {
  let actual;
  const api = new Api('private-panel', { token: 'Bearer secret', fetcher: async (url, options) => { actual = { url, options }; return json({ data: [], total: 0 }); } });
  const result = await api.get('user/fetch', { current: 1, banned: 0, search: '' });
  assert.equal(actual.url, '/api/v2/private-panel/user/fetch?current=1&banned=0');
  assert.equal(actual.options.headers.Authorization, 'Bearer secret');
  assert.deepEqual(result, { data: [], total: 0 });
});

test('login only sends credentials to the public administrator login endpoint', async () => {
  let actual;
  const api = new Api('private-panel', { token: 'old-session', fetcher: async (url, options) => { actual = { url, options }; return json({ data: { auth_data: 'Bearer new' } }); } });
  await api.login('admin@example.test', 'test-password');
  assert.equal(actual.url, '/api/v1/passport/auth/login');
  assert.equal(actual.options.method, 'POST');
  assert.equal(Object.hasOwn(actual.options.headers, 'Authorization'), false);
  assert.deepEqual(JSON.parse(actual.options.body), { email: 'admin@example.test', password: 'test-password' });
});

test('validation errors remain actionable without logging out the administrator', async () => {
  let signouts = 0;
  const api = new Api('private-panel', { onUnauthorized: () => signouts++, fetcher: async () => json({ message: 'invalid', errors: { name: ['请填写名称'], quota: ['额度错误'] } }, 422) });
  await assert.rejects(api.post('user/create', {}), error => error instanceof ApiError && error.status === 422 && errorMessage(error) === '请填写名称；额度错误');
  assert.equal(signouts, 0);
  api.fetcher = async () => json({ message: '操作被拒绝' }, 403);
  await assert.rejects(api.post('config/save', {}), { status: 403 });
  assert.equal(signouts, 0);
  api.fetcher = async () => json({ message: 'Unauthenticated.' }, 401);
  await assert.rejects(api.get('auth/me'), { status: 401 });
  assert.equal(signouts, 1);
});

test('backup downloads use authorization and return binary content', async () => {
  const api = new Api('private-panel', { token: 'secret', fetcher: async (url, options) => {
    assert.equal(url, '/api/v2/private-panel/backup/download?name=backup.sqlite.gz');
    assert.equal(options.headers.Authorization, 'Bearer secret');
    assert.equal(url.includes('secret'), false);
    return new Response(new Uint8Array([31, 139, 8]), { status: 200 });
  } });
  const result = await api.request('backup/download', { params: { name: 'backup.sqlite.gz' }, binary: true });
  assert.deepEqual(new Uint8Array(await result.blob.arrayBuffer()), new Uint8Array([31, 139, 8]));
});

test('timeout, unavailable services and invalid responses have honest error states', async () => {
  const api = new Api('private-panel', { fetcher: async () => { throw new Error('network failed'); } });
  await assert.rejects(api.get('auth/me'), /无法连接到服务器/);
  api.fetcher = async () => new Response('<html>Error</html>', { status: 502 });
  await assert.rejects(api.get('auth/me'), /未返回有效数据/);
  api.fetcher = (_url, { signal }) => new Promise((_resolve, reject) => signal.addEventListener('abort', () => reject(signal.reason), { once: true }));
  await assert.rejects(api.request('auth/me', { timeout: 5 }), /请求超时/);
});
