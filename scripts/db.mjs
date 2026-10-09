/**
 * Manages the local embedded PostgreSQL used for development.
 *   npm run db:up          start (idempotent) + ensure database exists
 *   npm run db:up -- --stop  stop the server
 *
 * Uses the official binaries shipped with the `embedded-postgres` package
 * (pg_ctl start/stop) so no sudo, Docker or system install is required.
 * DATABASE_URL remains the single switch for pointing at a real server.
 */
import { createRequire } from 'node:module';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const require = createRequire(import.meta.url);
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

// ── tiny .env loader (no dependency) ────────────────────────────────────────
function loadEnv() {
  const file = path.join(ROOT, '.env');
  if (!fs.existsSync(file)) return;
  for (const line of fs.readFileSync(file, 'utf8').split('\n')) {
    const m = line.match(/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*"?([^"\n]*)"?\s*$/);
    if (m && process.env[m[1]] === undefined) process.env[m[1]] = m[2];
  }
}
loadEnv();

const PG_PORT = Number(process.env.PG_PORT || 5433);
const PG_USER = process.env.PG_USER || 'postgres';
const PG_PASSWORD = process.env.PG_PASSWORD || 'postgres';
const PG_DATABASE = process.env.PG_DATABASE || 'vision_erp';
const DATA_DIR = path.join(ROOT, '.pgdata');

const PLATFORM_PKG = {
  linux: 'linux-x64',
  darwin: process.arch === 'arm64' ? 'darwin-arm64' : 'darwin-x64',
  win32: 'windows-x64',
}[process.platform];

function bin(name) {
  // The platform package has restricted "exports", so resolve by path.
  const pkgRoot = path.join(ROOT, 'node_modules', '@embedded-postgres', PLATFORM_PKG);
  const p = path.join(pkgRoot, 'native', 'bin', name);
  if (!fs.existsSync(p)) throw new Error(`PostgreSQL binary not found: ${p}`);
  return p;
}

function canConnect(timeoutMs = 700) {
  return new Promise((resolve) => {
    const socket = net.connect({ host: '127.0.0.1', port: PG_PORT });
    const done = (ok) => {
      socket.removeAllListeners();
      socket.destroy();
      resolve(ok);
    };
    socket.setTimeout(timeoutMs, () => done(false));
    socket.once('connect', () => done(true));
    socket.once('error', () => done(false));
  });
}

async function waitForReady(attempts = 40, delayMs = 300) {
  for (let i = 0; i < attempts; i++) {
    if (await canConnect()) return true;
    await new Promise((r) => setTimeout(r, delayMs));
  }
  return false;
}

function run(cmd, args, opts = {}) {
  const res = spawnSync(cmd, args, { stdio: 'inherit', ...opts });
  if (res.status !== 0) {
    throw new Error(`${path.basename(cmd)} exited with code ${res.status}`);
  }
}

async function ensureDatabase() {
  const { Client } = require('pg');
  const client = new Client({
    host: '127.0.0.1',
    port: PG_PORT,
    user: PG_USER,
    password: PG_PASSWORD,
    database: 'postgres',
  });
  await client.connect();
  try {
    const exists = await client.query('SELECT 1 FROM pg_database WHERE datname = $1', [
      PG_DATABASE,
    ]);
    if (exists.rowCount === 0) {
      if (!/^[a-z_][a-z0-9_]*$/.test(PG_DATABASE)) {
        throw new Error(`Invalid PG_DATABASE name: ${PG_DATABASE}`);
      }
      await client.query(`CREATE DATABASE "${PG_DATABASE}"`);
      console.log(`Created database "${PG_DATABASE}".`);
    } else {
      console.log(`Database "${PG_DATABASE}" already exists.`);
    }
  } finally {
    await client.end();
  }
}

async function main() {
  const stop = process.argv.includes('--stop');

  if (stop) {
    if (!fs.existsSync(path.join(DATA_DIR, 'PG_VERSION'))) {
      console.log('Embedded Postgres is not initialised — nothing to stop.');
      return;
    }
    run(bin('pg_ctl'), ['-D', DATA_DIR, '-m', 'fast', 'stop']);
    console.log('Embedded Postgres stopped.');
    return;
  }

  if (await canConnect()) {
    console.log(`PostgreSQL already running on port ${PG_PORT}.`);
    await ensureDatabase();
    return;
  }

  const versionFile = path.join(DATA_DIR, 'PG_VERSION');
  if (!fs.existsSync(versionFile)) {
    // Never initialised: clear any stray files (e.g. a failed earlier run).
    fs.rmSync(DATA_DIR, { recursive: true, force: true });
    fs.mkdirSync(DATA_DIR, { recursive: true });
    console.log('Initialising embedded PostgreSQL (one-time)…');
    const pwFile = path.join(os.tmpdir(), `erp-pg-pw-${process.pid}`);
    fs.writeFileSync(pwFile, `${PG_PASSWORD}\n`);
    try {
      run(bin('initdb'), [
        '-D',
        DATA_DIR,
        '-U',
        PG_USER,
        '--pwfile',
        pwFile,
        '--auth-local=trust',
        '--auth-host=scram-sha-256',
        '--encoding=UTF8',
        '--no-locale',
      ]);
    } finally {
      fs.rmSync(pwFile, { force: true });
    }
  }

  console.log(`Starting embedded PostgreSQL on port ${PG_PORT}…`);
  run(bin('pg_ctl'), [
    '-D',
    DATA_DIR,
    '-l',
    path.join(DATA_DIR, 'server.log'),
    '-o',
    `-p ${PG_PORT} -k /tmp -c listen_addresses=127.0.0.1`,
    'start',
  ]);

  const ready = await waitForReady();
  if (!ready) {
    const log = path.join(DATA_DIR, 'server.log');
    const tail = fs.existsSync(log)
      ? fs.readFileSync(log, 'utf8').split('\n').slice(-15).join('\n')
      : '';
    throw new Error(`PostgreSQL did not become ready.\n${tail}`);
  }

  await ensureDatabase();
  console.log(`Ready → postgresql://${PG_USER}@127.0.0.1:${PG_PORT}/${PG_DATABASE}`);
}

main().catch((err) => {
  console.error(err.message || err);
  process.exit(1);
});
