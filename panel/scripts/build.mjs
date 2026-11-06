import { createHash } from 'node:crypto';
import { readdir, readFile, mkdir, rm, writeFile } from 'node:fs/promises';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const panel = dirname(dirname(fileURLToPath(import.meta.url)));
const source = join(panel, 'src');
const output = join(panel, '..', 'public', 'assets', 'panel');

async function list(directory) {
  const entries = await readdir(directory, { withFileTypes: true });
  const files = await Promise.all(entries.map(entry => entry.isDirectory()
    ? list(join(directory, entry.name)) : join(directory, entry.name)));
  return files.flat().sort();
}

const files = await Promise.all((await list(source)).map(async file => ({
  name: relative(source, file).replaceAll('\\', '/'),
  content: await readFile(file),
})));
const fingerprint = createHash('sha256');
for (const file of files) fingerprint.update(file.name).update('\0').update(file.content).update('\0');
const version = fingerprint.digest('hex').slice(0, 16);
await rm(output, { recursive: true, force: true });
await mkdir(join(output, version), { recursive: true });
const integrity = {};
for (const file of files) {
  const destination = join(output, version, file.name);
  await mkdir(dirname(destination), { recursive: true });
  await writeFile(destination, file.content);
  integrity[`${version}/${file.name}`] = `sha256-${createHash('sha256').update(file.content).digest('base64')}`;
}
const manifest = {
  version,
  'index.html': { file: `${version}/app.js`, css: [`${version}/styles.css`], isEntry: true },
  integrity,
};
await writeFile(join(output, 'manifest.json'), JSON.stringify(manifest, null, 2) + '\n');
console.log(`SimpleXboard panel ${version}: ${files.length} files built.`);
