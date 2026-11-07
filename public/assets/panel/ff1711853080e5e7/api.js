export class ApiError extends Error {
  constructor(message, status = 0, errors = null) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.errors = errors;
  }
}

export class Api {
  constructor(securePath, { token = '', fetcher = globalThis.fetch.bind(globalThis), onUnauthorized = () => {} } = {}) {
    const path = String(securePath).split('/').filter(Boolean).map(encodeURIComponent).join('/');
    this.base = `/api/v2/${path}`;
    this.token = token;
    this.fetcher = fetcher;
    this.onUnauthorized = onUnauthorized;
  }

  async request(path, { method = 'GET', body, params, signal, binary = false, publicPath = false, timeout = 30000 } = {}) {
    const query = new URLSearchParams();
    for (const [key, value] of Object.entries(params ?? {})) {
      if (value !== null && value !== undefined && value !== '') query.set(key, String(value));
    }
    const url = (publicPath ? path : `${this.base}/${path}`) + (query.size ? `?${query}` : '');
    const controller = new AbortController();
    const abort = () => controller.abort(signal?.reason);
    if (signal?.aborted) abort();
    else signal?.addEventListener('abort', abort, { once: true });
    const timer = setTimeout(() => controller.abort(new Error('请求超时，请检查服务状态后重试。')), timeout);
    const headers = { Accept: binary ? '*/*' : 'application/json' };
    if (this.token && !publicPath) headers.Authorization = this.token.startsWith('Bearer ') ? this.token : `Bearer ${this.token}`;
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    try {
      const response = await this.fetcher(url, {
        method, headers, credentials: 'same-origin', signal: controller.signal,
        body: body === undefined ? undefined : JSON.stringify(body),
      });
      if (binary && response.ok) return { blob: await response.blob(), headers: response.headers };
      let result;
      try { result = await response.json(); }
      catch { throw new ApiError('服务器未返回有效数据，请检查服务是否可用。', response.status); }
      if (!response.ok || result.status === 'fail') {
        const error = new ApiError(result.message || `请求失败（${response.status}）`, response.status, result.errors ?? result.error);
        if (!publicPath && (response.status === 401 || (response.status === 403 && /unauthorized/i.test(error.message)))) this.onUnauthorized();
        throw error;
      }
      return result;
    } catch (error) {
      if (signal?.aborted) throw error;
      if (controller.signal.aborted) throw new ApiError('请求超时，请检查服务状态后重试。');
      if (error instanceof ApiError) throw error;
      throw new ApiError('无法连接到服务器，请检查网络后重试。');
    } finally {
      clearTimeout(timer);
      signal?.removeEventListener('abort', abort);
    }
  }

  get(path, params = {}, options = {}) { return this.request(path, { ...options, params }); }
  post(path, body = {}, options = {}) { return this.request(path, { ...options, method: 'POST', body }); }
  login(email, password) { return this.post('/api/v1/passport/auth/login', { email, password }, { publicPath: true }); }

  async download(path, filename, options = {}) {
    const { blob } = await this.request(path, { ...options, binary: true });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = filename.replace(/[\\/\r\n]/g, '_');
    document.body.append(anchor);
    anchor.click();
    anchor.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }
}
