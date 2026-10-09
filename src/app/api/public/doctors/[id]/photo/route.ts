import { connection } from 'next/server';
import { prisma } from '@/lib/prisma';
import { storage } from '@/lib/storage';

const PHOTO_URL_PREFIX = '/api/uploads/';

const CONTENT_TYPES: Record<string, string> = {
  png: 'image/png',
  jpg: 'image/jpeg',
  jpeg: 'image/jpeg',
  webp: 'image/webp',
  gif: 'image/gif',
};

/**
 * Public photo for a PUBLISHED doctor only (flagged `isDoctor` +
 * `showOnWebsite` + active). Staff photos normally live behind the
 * authenticated `/api/uploads/…` route; this narrow route exposes just the
 * photo of doctors the clinic has chosen to show, and never any other field.
 */
export async function GET(_request: Request, { params }: { params: Promise<{ id: string }> }) {
  await connection();
  const { id } = await params;

  const staff = await prisma.staff.findFirst({
    where: { id, isDoctor: true, showOnWebsite: true, active: true },
    select: { photoUrl: true },
  });

  if (!staff?.photoUrl || !staff.photoUrl.startsWith(PHOTO_URL_PREFIX)) {
    return new Response(null, { status: 404 });
  }

  const key = staff.photoUrl.slice(PHOTO_URL_PREFIX.length);
  const bytes = await storage.get(key);
  if (!bytes) return new Response(null, { status: 404 });

  const ext = key.split('.').pop()?.toLowerCase() ?? '';
  return new Response(new Uint8Array(bytes), {
    headers: {
      'Content-Type': CONTENT_TYPES[ext] ?? 'application/octet-stream',
      'Cache-Control': 'public, max-age=3600, stale-while-revalidate=86400',
    },
  });
}
