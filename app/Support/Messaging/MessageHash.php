<?php

declare(strict_types=1);

namespace App\Support\Messaging;

/**
 * Pure helpers for the Message Library: normalising message bodies into a
 * stable hash (for duplicate detection) and deriving a default title.
 * Ported from src/modules/messaging/message-hash.ts.
 */
final class MessageHash
{
    private const MAX_AUTO_TITLE = 40;

    /** Trims, collapses runs of whitespace, and lowercases a message body. */
    public static function normalizeMessageBody(string $body): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $body) ?? $body));
    }

    /**
     * Stable, dependency-free hash (FNV-1a, 32-bit) over the normalised body.
     *
     * Uniqueness is enforced per language, so collisions are only a
     * theoretical concern at this scale.
     *
     * The legacy hashed UTF-16 code units (`charCodeAt`), so the string is
     * converted to UTF-16LE and each 16-bit unit is folded in — that keeps
     * the digest identical for non-ASCII (Amharic) bodies too.
     */
    public static function messageBodyHash(string $body): string
    {
        $normalized = self::normalizeMessageBody($body);
        $utf16 = mb_convert_encoding($normalized, 'UTF-16LE', 'UTF-8');

        $hash = 0x811C9DC5;
        $length = strlen($utf16);

        for ($i = 0; $i + 1 < $length; $i += 2) {
            $codeUnit = ord($utf16[$i]) | (ord($utf16[$i + 1]) << 8);
            $hash ^= $codeUnit;
            $hash = ($hash * 0x01000193) & 0xFFFFFFFF;
        }

        return str_pad(dechex($hash), 8, '0', STR_PAD_LEFT);
    }

    /** Auto-title from the first line of the body (library default). */
    public static function titleFromBody(string $body): string
    {
        $firstLine = '';

        foreach (explode("\n", $body) as $line) {
            $trimmed = trim($line);

            if ($trimmed !== '') {
                $firstLine = $trimmed;
                break;
            }
        }

        if (mb_strlen($firstLine) <= self::MAX_AUTO_TITLE) {
            return $firstLine;
        }

        return rtrim(mb_substr($firstLine, 0, self::MAX_AUTO_TITLE - 1)).'…';
    }
}
