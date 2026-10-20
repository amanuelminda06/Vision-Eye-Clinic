<?php

declare(strict_types=1);

namespace App\Support\Patients;

/**
 * Deterministic tag colouring, ported from src/modules/patients/tags.ts.
 *
 * `hash = (hash * 31 + charCodeAt(i)) | 0` over the name, then the palette
 * index `|hash| % 8`. `charCodeAt` is UTF-16 code-unit based, so PHP iterates
 * the UTF-16LE code units of the (already lowercased) name — Amharic tags
 * therefore get the same colour as they did in the legacy app.
 */
final class TagColors
{
    /** @var list<string> */
    public const PALETTE = [
        '#3B82F6',
        '#10B981',
        '#F59E0B',
        '#EF4444',
        '#8B5CF6',
        '#06B6D4',
        '#EC4899',
        '#84CC16',
    ];

    public static function for(string $name): string
    {
        $utf16 = mb_convert_encoding($name, 'UTF-16LE', 'UTF-8');
        $length = intdiv(strlen($utf16), 2);

        $hash = 0;

        for ($i = 0; $i < $length; $i++) {
            $codeUnit = ord($utf16[$i * 2]) | (ord($utf16[$i * 2 + 1]) << 8);

            // 32-bit signed integer overflow semantics, matching `| 0`.
            $hash = self::toInt32($hash * 31 + $codeUnit);
        }

        $index = abs($hash) % count(self::PALETTE);

        return self::PALETTE[$index];
    }

    /** Wrap a PHP int into the signed 32-bit range (JS `| 0`). */
    private static function toInt32(int $value): int
    {
        $value &= 0xFFFFFFFF;

        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }
}
