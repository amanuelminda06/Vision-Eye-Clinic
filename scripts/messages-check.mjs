/**
 * Verifies i18n completeness:
 *  1. messages/en.json and messages/am.json have identical key structure
 *  2. every literal `t('key')` / `getTranslations('ns')…t('key')` call in src/
 *     resolves to an existing key
 *
 * Run: npm run messages
 */
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';

const root = process.cwd();
let errors = 0;

function fail(message) {
  console.error(`✗ ${message}`);
  errors += 1;
}

function flatten(value, prefix = '', out = new Set()) {
  for (const [key, child] of Object.entries(value)) {
    const path = prefix ? `${prefix}.${key}` : key;
    if (child && typeof child === 'object' && !Array.isArray(child)) {
      flatten(child, path, out);
    } else {
      out.add(path);
    }
  }
  return out;
}

const en = flatten(JSON.parse(readFileSync(join(root, 'messages/en.json'), 'utf8')));
const am = flatten(JSON.parse(readFileSync(join(root, 'messages/am.json'), 'utf8')));

for (const key of en) if (!am.has(key)) fail(`missing in messages/am.json: ${key}`);
for (const key of am) if (!en.has(key)) fail(`missing in messages/en.json: ${key}`);

const namespaces = new Set([...en].map((key) => key.split('.')[0]));

function walk(dir, files = []) {
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) {
      if (entry === 'node_modules' || entry === '.next') continue;
      walk(full, files);
    } else if (/\.(ts|tsx)$/.test(entry) && !/\.test\.(ts|tsx)$/.test(entry)) {
      files.push(full);
    }
  }
  return files;
}

const HOOK_RE =
  /const\s+([A-Za-z_$][\w$]*)\s*=\s*(?:await\s+)?(?:useTranslations|getTranslations)\(\s*'([^']+)'\s*\)/g;
const CALL_RE = /(?<![.\w$])([A-Za-z_$][\w$]*)\(\s*'([^']+)'/g;

for (const file of walk(join(root, 'src'))) {
  const source = readFileSync(file, 'utf8');
  const rel = relative(root, file);
  const scope = new Map();

  for (const match of source.matchAll(HOOK_RE)) {
    scope.set(match[1], match[2]);
  }

  for (const match of source.matchAll(CALL_RE)) {
    const [callee, rawKey] = [match[1], match[2]];
    if (!scope.has(callee)) continue;
    const fullKey = `${scope.get(callee)}.${rawKey}`;
    if (!en.has(fullKey)) fail(`${rel}: unknown message key '${fullKey}'`);
  }
}

for (const ns of namespaces) {
  if (!ns) fail('empty namespace in messages/en.json');
}

if (errors > 0) {
  console.error(`\n${errors} problem(s) found`);
  process.exit(1);
}
console.log(`✓ messages OK — ${en.size} keys × 2 locales, all literal lookups resolve`);
