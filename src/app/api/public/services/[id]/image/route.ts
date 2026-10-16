import { prisma } from '@/lib/prisma';
import { storage } from '@/lib/storage';

const IMAGE_URL_PREFIX = '/api/uploads/';

const CONTENT_TYPES: Record<string, string> = {
  png: 'image/png',
  jpg: 'image/jpeg',
  jpeg: 'image/jpeg',
  webp: 'image/webp',
  gif: 'image/gif',
};

/**
 * Public image for a PUBLISHED service only (`isPublic: true` + `active: true`).
 * Service images uploaded via the ERP live behind the authenticated
 * `/api/uploads/…` route; this narrow route exposes just the image of services
 * the clinic has chosen to show publicly, and never any other field.
 * Site-relative paths (e.g. `/services/foo.png`) are served directly by the
 * browser/Next Image and never hit this route.
 */
export async function GET(_request: Request, { params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;

  const service = await prisma.service.findFirst({
    where: { id, isPublic: true, active: true },
    select: { imageUrl: true },
  });

  if (!service?.imageUrl || !service.imageUrl.startsWith(IMAGE_URL_PREFIX)) {
    return new Response(null, { status: 404 });
  }

  const key = service.imageUrl.slice(IMAGE_URL_PREFIX.length);
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
