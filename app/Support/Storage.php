<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\StoredFile;

/**
 * Blob storage for uploaded images, ported from src/lib/database-storage.ts.
 *
 * Bytes live in the `stored_files` table (MySQL LONGBLOB) so uploads survive
 * disk wipes and are shared across app servers. Keys are path-traversal-safe
 * and the bytes are only ever served to signed-in users through /uploads/{key}.
 */
final class Storage
{
    private const SAFE_KEY = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    /** Prefix used for every patient ID photo key. */
    public const PATIENT_ID_PHOTO_PREFIX = '/uploads/';

    /**
     * Legacy URLs pointed at `/api/uploads/{key}`; the Laravel route serves the
     * same keys from `/uploads/{key}`. Accept both so old rows keep resolving.
     */
    private const LEGACY_URL_PREFIX = '/api/uploads/';

    /** Stores bytes under a safe key and returns that key. */
    public static function put(string $key, string $data): string
    {
        $key = self::safeKey($key);

        StoredFile::create([
            'key' => $key,
            'mime_type' => self::detectMime($data),
            'data' => $data,
        ]);

        return $key;
    }

    /** Returns bytes for a key, or null when missing. */
    public static function get(string $key): ?string
    {
        $file = StoredFile::where('key', self::safeKey($key))->first();

        if ($file === null) {
            return null;
        }

        return (string) $file->getRawOriginal('data');
    }

    /** Removes the object (no error when missing). */
    public static function remove(string $key): void
    {
        StoredFile::where('key', self::safeKey($key))->delete();
    }

    /** MIME sniffing from magic bytes, matching legacy detectMime. */
    public static function detectMime(string $data): string
    {
        if (strlen($data) >= 8 && bin2hex(substr($data, 0, 8)) === '89504e470d0a1a0a') {
            return 'image/png';
        }

        if (strlen($data) >= 3 && bin2hex(substr($data, 0, 3)) === 'ffd8ff') {
            return 'image/jpeg';
        }

        if (strlen($data) >= 12 && substr($data, 0, 4) === 'RIFF' && substr($data, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        return 'application/octet-stream';
    }

    /** Extract the storage key from a stored URL, or null when it is not ours. */
    public static function keyFromUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $prefix = null;

        if (str_starts_with($url, self::PATIENT_ID_PHOTO_PREFIX)) {
            $prefix = self::PATIENT_ID_PHOTO_PREFIX;
        } elseif (str_starts_with($url, self::LEGACY_URL_PREFIX)) {
            $prefix = self::LEGACY_URL_PREFIX;
        }

        if ($prefix === null) {
            return null;
        }

        $raw = substr($url, strlen($prefix));

        // decodeURIComponent equivalent — invalid escapes yield null.
        $decoded = rawurldecode($raw);

        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $raw) === 1) {
            return null;
        }

        return $decoded === '' ? null : $decoded;
    }

    private static function safeKey(string $key): string
    {
        if (preg_match(self::SAFE_KEY, $key) !== 1 || str_contains($key, '..')) {
            throw new \InvalidArgumentException("Unsafe storage key: {$key}");
        }

        return $key;
    }
}
