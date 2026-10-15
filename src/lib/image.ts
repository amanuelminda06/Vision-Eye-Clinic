/**
 * Resizes an image in the browser before upload so stored blobs stay small.
 * Max 800 px on the long edge, JPEG quality 0.7. PNG/WebP inputs are
 * converted to JPEG; the returned File is always `image/jpeg`.
 */
export async function resizeImageFile(file: File, maxPx = 800, quality = 0.7): Promise<File> {
  if (typeof window === 'undefined' || !window.HTMLCanvasElement) return file;

  const bitmap = await createImageBitmap(file);
  const scale = Math.min(1, maxPx / Math.max(bitmap.width, bitmap.height));
  const width = Math.max(1, Math.round(bitmap.width * scale));
  const height = Math.max(1, Math.round(bitmap.height * scale));

  const canvas = document.createElement('canvas');
  canvas.width = width;
  canvas.height = height;
  const ctx = canvas.getContext('2d');
  if (!ctx) return file;
  ctx.drawImage(bitmap, 0, 0, width, height);
  bitmap.close();

  const blob = await new Promise<Blob | null>((resolve) =>
    canvas.toBlob((b) => resolve(b), 'image/jpeg', quality),
  );
  if (!blob) return file;

  const base = file.name.replace(/\.[^.]+$/, '');
  return new File([blob], `${base || 'photo'}.jpg`, { type: 'image/jpeg' });
}
