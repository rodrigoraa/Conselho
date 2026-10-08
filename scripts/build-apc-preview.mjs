import { build } from 'esbuild';
import { createHash } from 'node:crypto';
import { readFile, readdir, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';

const root = new URL('../', import.meta.url);
const bundle = new URL('apps/preconselho-web/public/assets/apc-docx-viewer.js', root);
const result = await build({
  absWorkingDir: fileURLToPath(root),
  entryPoints: [fileURLToPath(new URL('apps/apc/frontend/docx-viewer.js', root))],
  outfile: fileURLToPath(bundle),
  bundle: true,
  minify: true,
  format: 'iife',
  target: ['es2020'],
  legalComments: 'linked',
  metafile: true,
});
const version = createHash('sha256').update(await readFile(bundle)).digest('hex').slice(0, 16);
const template = await readFile(new URL('apps/apc/frontend/docx-viewer.html', root), 'utf8');
await writeFile(new URL('apps/preconselho-web/public/assets/apc-docx-viewer.html', root), template.replace('{{version}}', version));
const packageDirectories = new Set(Object.keys(result.metafile.inputs).filter(input => input.includes('node_modules/')).map(input => {
  const offset = input.lastIndexOf('node_modules/') + 'node_modules/'.length;
  const parts = input.slice(offset).split('/');
  return input.slice(0, offset) + parts.slice(0, parts[0].startsWith('@') ? 2 : 1).join('/');
}));
const licenses = [];
for (const packageDirectory of [...packageDirectories].sort()) {
  const directory = new URL(`${packageDirectory}/`, root);
  const metadata = JSON.parse(await readFile(new URL('package.json', directory), 'utf8'));
  const files = (await readdir(directory)).filter(file => /^licen[cs]e(?:\..*)?$/i.test(file));
  if (!files.length) throw new Error(`Licença não encontrada: ${metadata.name}`);
  for (const file of files) licenses.push(`${metadata.name} ${metadata.version}\n${await readFile(new URL(file, directory), 'utf8')}`);
}
await writeFile(new URL('apps/preconselho-web/public/assets/apc-docx-licenses.txt', root), licenses.join('\n\n----------------------------------------\n\n'));
