#!/usr/bin/env node
import { createHash } from 'node:crypto';
import { readdir, readFile, writeFile } from 'node:fs/promises';
import { join } from 'node:path';

const root = process.cwd();
const files = (await readdir(root)).filter((file) => file.endsWith('.tgz')).sort();
if (files.length === 0) {
  console.error('No .tgz package artifacts found. Run npm pack first.');
  process.exit(1);
}

const lines = [];
for (const file of files) {
  const data = await readFile(join(root, file));
  const hash = createHash('sha256').update(data).digest('hex');
  lines.push(`${hash}  ${file}`);
}

await writeFile(join(root, 'SHA256SUMS'), `${lines.join('\n')}\n`);
console.log(`Wrote SHA256SUMS for ${files.length} artifact(s).`);
