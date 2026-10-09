import { mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { env } from '@/env';

/**
 * Storage abstraction (staff photos today, anything file-backed tomorrow).
 * Local-disk implementation lives behind this interface so a cloud provider
 * can be swapped in without touching module code.
 */
export interface StorageProvider {
  /** Stores bytes under a safe key and returns that key. */
  put(key: string, data: Buffer): Promise<string>;
  /** Returns bytes for a key, or null when missing. */
  get(key: string): Promise<Buffer | null>;
  /** Removes the object (no error when missing). */
  remove(key: string): Promise<void>;
}

const SAFE_KEY = /^[A-Za-z0-9][A-Za-z0-9._-]*$/;

function safeKey(key: string): string {
  if (!SAFE_KEY.test(key) || key.includes('..')) {
    throw new Error(`Unsafe storage key: ${key}`);
  }
  return key;
}

export class LocalDiskStorage implements StorageProvider {
  private readonly root: string;

  constructor(rootDir: string) {
    this.root = resolve(rootDir);
  }

  private path(key: string): string {
    const full = resolve(this.root, safeKey(key));
    if (!full.startsWith(this.root + '/')) throw new Error(`Path escapes storage root: ${key}`);
    return full;
  }

  async put(key: string, data: Buffer): Promise<string> {
    const path = this.path(key);
    await mkdir(dirname(path), { recursive: true });
    await writeFile(path, data);
    return safeKey(key);
  }

  async get(key: string): Promise<Buffer | null> {
    try {
      return await readFile(this.path(key));
    } catch {
      return null;
    }
  }

  async remove(key: string): Promise<void> {
    await rm(this.path(key), { force: true });
  }
}

export const storage: StorageProvider = new LocalDiskStorage(
  // UPLOAD_DIR is a relative folder resolved at runtime — don't let the
  // bundler trace the whole project because of it.
  resolve(/* turbopackIgnore: true */ process.cwd(), env.UPLOAD_DIR),
);
