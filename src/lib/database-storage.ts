import { prisma } from '@/lib/prisma';
import type { StorageProvider } from './storage.ts';

function detectMime(data: Buffer): string {
  if (data.length >= 8 && data.slice(0, 8).toString('hex') === '89504e470d0a1a0a')
    return 'image/png';
  if (data.length >= 3 && data.slice(0, 3).toString('hex') === 'ffd8ff') return 'image/jpeg';
  if (
    data.length >= 12 &&
    data.slice(0, 4).toString() === 'RIFF' &&
    data.slice(8, 12).toString() === 'WEBP'
  )
    return 'image/webp';
  return 'application/octet-stream';
}

/**
 * Stores uploaded images as Postgres BYTEA blobs. Persistent across Vercel
 * deployments (unlike local disk), so staff photos and patient ID photos
 * survive a cold start.
 */
export class DatabaseStorage implements StorageProvider {
  async put(key: string, data: Buffer): Promise<string> {
    await prisma.storedFile.create({
      data: {
        key,
        mimeType: detectMime(data),
        data: data as never,
      },
    });
    return key;
  }

  async get(key: string): Promise<Buffer | null> {
    const row = await prisma.storedFile.findUnique({ where: { key } });
    if (!row) return null;
    return Buffer.from(row.data as never) as Buffer;
  }

  async remove(key: string): Promise<void> {
    await prisma.storedFile.deleteMany({ where: { key } });
  }
}
