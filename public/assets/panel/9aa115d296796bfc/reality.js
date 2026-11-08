import { data } from './format.js';

function generatedFields(response, kind) {
  const values = data(response);
  const key = /^[A-Za-z0-9_-]{43}$/;
  const shortId = /^(?:[0-9a-fA-F]{2}){1,8}$/;
  const matches = (value, pattern) => typeof value === 'string' && pattern.test(value);
  if (!values || !matches(values.short_id, shortId) ||
      (kind === 'pair' && (!matches(values.public_key, key) || !matches(values.private_key, key)))) {
    throw new Error('生成结果不完整或格式无效，请重试。');
  }
  return kind === 'pair'
    ? { public_key: values.public_key, private_key: values.private_key, short_id: values.short_id }
    : { short_id: values.short_id };
}

// A cancelled request may still finish; only the current, active editor can accept it.
export function createRealityGenerator({ api, isActive, onBusy, onResult, onError }) {
  let pending = null;

  async function generate(kind) {
    if (pending || !isActive()) return false;
    const request = { controller: new AbortController(), kind };
    pending = request;
    onBusy(true, kind);
    try {
      const endpoint = kind === 'pair' ? 'generateRealityKeyPair' : 'generateShortId';
      const response = await api.post(`server/manage/${endpoint}`, {}, { signal: request.controller.signal });
      if (pending !== request || !isActive()) return false;
      onResult(generatedFields(response, kind), kind);
      return true;
    } catch (error) {
      if (pending === request && !request.controller.signal.aborted && isActive()) onError(error, kind);
      return false;
    } finally {
      if (pending === request) {
        pending = null;
        onBusy(false, kind);
      }
    }
  }

  return {
    get busy() { return pending !== null; },
    generateKeyPair: () => generate('pair'),
    generateShortId: () => generate('short'),
    cancel() {
      if (!pending) return;
      const request = pending;
      pending = null;
      request.controller.abort();
      onBusy(false, request.kind);
    },
  };
}
