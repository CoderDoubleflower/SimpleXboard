import test from 'node:test';
import assert from 'node:assert/strict';
import { certificateMode, certificateFromForm, validateCertificate } from '../src/certificates.js';
import { nodePayload } from '../src/domain.js';

const common = { type: 'hysteria', name: 'HY2', host: 'hy.example.test', port: '443', server_port: '443', enabled: true,
  protocol_json: '{}', advanced_json: '{}', tls_server_name: 'hy.example.test', hy_version: '2' };
const file = { cert_mode: 'file', cert_file: '/certs/fullchain.pem', key_file: '/certs/key.pem' };

test('SNI and insecure mode cannot save Hysteria without a server certificate, even while disabled', () => {
  for (const enabled of [true, false]) {
    for (const tls_insecure of [true, false]) {
      for (const config of [null, {}, { cert_mode: 'none' }, { cert_mode: 'unknown' }, { cert_file: '/certs/cert.pem' }]) {
        assert.throws(() => nodePayload({ ...common, enabled, tls_insecure, advanced_json: JSON.stringify({ cert_config: config }) }), /证书/);
      }
    }
  }
});

test('file mode requires both usable path strings and identifies the missing field', () => {
  assert.deepEqual(validateCertificate({ ...file, cert_file: ' /certs/fullchain.pem ' }), file);
  for (const value of [undefined, null, '', '  ', [], {}, '/certs/a\0b']) {
    assert.throws(() => validateCertificate({ ...file, cert_file: value }), error => error.field === 'cert_config.cert_file');
    assert.throws(() => validateCertificate({ ...file, key_file: value }), error => error.field === 'cert_config.key_file');
  }
});

test('legacy mode aliases and inferred complete pairs normalize without masking conflicts', () => {
  assert.equal(validateCertificate({ mode: ' FILE ', cert_file: '/cert.pem', key_file: '/key.pem' }).cert_mode, 'file');
  assert.equal(Object.hasOwn(validateCertificate({ mode: 'self' }), 'mode'), false);
  assert.equal(certificateMode({ cert_file: '/cert.pem', key_file: '/key.pem' }), 'file');
  assert.equal(certificateMode({ cert_content: 'certificate', key_content: 'key', ...file, cert_mode: '' }), 'content');
  assert.throws(() => validateCertificate({ ...file, mode: 'self' }), /冲突/);
  assert.throws(() => validateCertificate({ ...file, cert_mode: 'none' }), /证书来源/);
});

test('Hysteria validates the effective advanced configuration and refuses duplicate certificate sources', () => {
  const current = { id: 8, type: 'hysteria', cert_config: file };
  assert.deepEqual(nodePayload(common, current).cert_config, file);
  assert.throws(() => nodePayload({ ...common, advanced_json: '{"cert_config":null}' }, current), /证书/);
  assert.throws(() => nodePayload({ ...common, cert_mode: 'self', advanced_json: '{"cert_config":{"cert_mode":"none"}}' }), error => error.field === 'advanced_json');
  assert.throws(() => nodePayload({ ...common, cert_mode: '', tls_insecure: true, advanced_json: '{}' }, current), /证书来源/);
});

test('the certificate form preserves extensions and removes unused source credentials', () => {
  const saved = { ...file, private_extension: { retain: true }, dns_env: { CLOUDFLARE_DNS_API_TOKEN: 'old-test-token' } };
  const actual = certificateFromForm({ cert_mode: 'self', cert_domain: 'hy.example.test' }, saved);
  assert.deepEqual(actual, { private_extension: { retain: true }, cert_mode: 'self', domain: 'hy.example.test' });
  const payload = nodePayload({ ...common, cert_mode: 'file', cert_file: '/new/cert.pem', cert_key_file: '/new/key.pem' }, { cert_config: saved });
  assert.deepEqual(payload.cert_config.private_extension, { retain: true });
  assert.equal(payload.cert_config.cert_file, '/new/cert.pem');
  assert.equal(Object.hasOwn(payload.cert_config, 'dns_env'), false);
});

test('self-signed mode is an explicit certificate source and does not force clients to skip verification', () => {
  const payload = nodePayload({ ...common, cert_mode: 'self', tls_insecure: false });
  assert.deepEqual(payload.cert_config, { cert_mode: 'self' });
  assert.equal(payload.protocol_settings.tls.allow_insecure, false);
  assert.deepEqual(validateCertificate({ mode: 'self', domain: 'hy.example.test' }), { cert_mode: 'self', domain: 'hy.example.test' });
});

test('PEM content requires a certificate and unencrypted private key, while cryptographic checks remain server-side', () => {
  const content = { cert_mode: 'content', cert_content: '-----BEGIN CERTIFICATE-----\nY2VydA==\n-----END CERTIFICATE-----',
    key_content: '-----BEGIN PRIVATE KEY-----\na2V5\n-----END PRIVATE KEY-----' };
  assert.deepEqual(validateCertificate(content), content);
  for (const key of ['cert_content', 'key_content']) {
    assert.throws(() => validateCertificate({ ...content, [key]: '' }), error => error.field === 'cert_config.' + key);
    assert.throws(() => validateCertificate({ ...content, [key]: 'not PEM' }), error => error.field === 'cert_config.' + key);
  }
  assert.throws(() => validateCertificate({ ...content, key_content: '-----BEGIN ENCRYPTED PRIVATE KEY-----\neA==\n-----END ENCRYPTED PRIVATE KEY-----' }), /未加密/);
});

test('HTTP issuance requires an explicit domain and accepts default or custom validation ports', () => {
  for (const http_port of [undefined, null, '', 0, 80, 8080, 65535]) {
    assert.equal(validateCertificate({ cert_mode: 'http', domain: 'hy.example.test', http_port }).cert_mode, 'http');
  }
  for (const http_port of [-1, 65536, '80', 1.5]) {
    assert.throws(() => validateCertificate({ cert_mode: 'http', domain: 'hy.example.test', http_port }), error => error.field === 'cert_config.http_port');
  }
  assert.throws(() => nodePayload({ ...common, cert_mode: 'http' }), /域名/);
  for (const domain of ['', ' ', 'https://hy.example.test', 'hy.example.test:443', '*.example.test']) {
    assert.throws(() => validateCertificate({ cert_mode: 'http', domain }), error => error.field === 'cert_config.domain');
  }
  assert.equal(certificateFromForm({ cert_mode: 'http', cert_domain: 'hy.example.test', cert_http_port: '8080' }).http_port, 8080);
});

test('DNS issuance validates provider-specific credentials and preserves supported aliases', () => {
  const dns = { cert_mode: 'dns', domain: '*.example.test', dns_provider: 'cf', dns_env: { CF_API_TOKEN: 'test-token' } };
  assert.equal(validateCertificate(dns).dns_provider, 'cloudflare');
  assert.throws(() => validateCertificate({ ...dns, dns_env: {} }), /Cloudflare.*凭据/);
  assert.throws(() => validateCertificate({ ...dns, dns_provider: 'unsupported' }), /DNS 服务商/);
  assert.throws(() => validateCertificate({ ...dns, dns_env: { CF_API_TOKEN: 123 } }), /字符串/);
  assert.throws(() => validateCertificate({ ...dns, dns_env: [] }), /JSON 对象/);
  assert.equal(validateCertificate({ ...dns, dns_provider: 'aliyun', dns_env: { ALIYUN_ACCESS_KEY_ID: 'id', ALI_ACCESS_KEY_SECRET: 'secret' } }).dns_provider, 'alidns');
  assert.throws(() => validateCertificate({ ...dns, dns_provider: 'aliyun', dns_env: { ALI_ACCESS_KEY_ID: 'id' } }), /SECRET/);
  assert.equal(validateCertificate({ ...dns, dns_provider: 'godaddy', dns_env: { GODADDY_API_KEY: 'key', GODADDY_API_SECRET: 'secret' } }).dns_provider, 'godaddy');
  assert.throws(() => validateCertificate({ ...dns, dns_provider: 'godaddy', dns_env: { GODADDY_API_KEY: 'key' } }), /GODADDY/);
});

test('DNS cloud identity remains usable without blanket credential requirements', () => {
  const dns = { cert_mode: 'dns', domain: 'hy.example.test' };
  assert.deepEqual(validateCertificate({ ...dns, dns_provider: 'aws' }).dns_env, {});
  assert.equal(validateCertificate({ ...dns, dns_provider: 'azure', dns_env: { AZURE_SUBSCRIPTION_ID: 'subscription', AZURE_RESOURCE_GROUP: 'group' } }).dns_provider, 'azure');
  assert.equal(validateCertificate({ ...dns, dns_provider: 'gcp', dns_env: { GCP_PROJECT: 'project' } }).dns_provider, 'googleclouddns');
  assert.throws(() => validateCertificate({ ...dns, dns_provider: 'azure' }), /AZURE_SUBSCRIPTION_ID/);
  assert.throws(() => validateCertificate({ ...dns, dns_provider: 'gcp' }), /GCE_PROJECT/);
});

test('DNS form JSON errors identify the credential input and cannot become arrays', () => {
  const values = { cert_mode: 'dns', cert_domain: 'hy.example.test', cert_dns_provider: 'route53' };
  assert.deepEqual(certificateFromForm({ ...values, cert_dns_env: '{}' }).dns_env, {});
  for (const cert_dns_env of ['{bad}', '[]', 'null', '{"AWS_REGION":123}']) {
    assert.throws(() => certificateFromForm({ ...values, cert_dns_env }), error => error.field === 'cert_config.dns_env');
  }
});

test('non-Hysteria forms retain their existing certificate behavior', () => {
  const result = nodePayload({ ...common, type: 'vless', vless_tls: '2', advanced_json: '{"cert_config":{"mode":"none"}}' });
  assert.deepEqual(result.cert_config, { mode: 'none' });
});
