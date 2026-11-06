import test from 'node:test';
import assert from 'node:assert/strict';
import { readdir } from 'node:fs/promises';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

test('every shipped JavaScript module parses before producing the browser entry', async () => {
  const root = new URL('../src/', import.meta.url);
  const files = await readdir(root, { recursive: true });
  const scripts = files.filter(file => file.endsWith('.js'));
  assert.ok(scripts.includes('app.js'));
  for (const file of scripts) execFileSync(process.execPath, ['--check', fileURLToPath(new URL(file, root))], { stdio: 'pipe' });
});
