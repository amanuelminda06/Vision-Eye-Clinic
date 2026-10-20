<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Audit writer, ported from src/lib/audit.ts + src/lib/audit-diff.ts.
 *
 * Rules:
 * - stores ONLY changed fields (old_json / new_json hold the diff halves)
 * - redacts secret-looking keys with [REDACTED]
 * - never throws: a failed audit write must not roll back the business op
 * - VIEW rows are deduped: one per (user, entity, entityId) per minute
 */
final class Audit
{
    public const REDACTED = '[REDACTED]';

    /** @var list<string> */
    private const SECRET_KEYS = [
        'password', 'passwordhash', 'password_hash', 'newpassword', 'oldpassword',
        'currentpassword', 'secret', 'token', 'authtoken', 'apisecret', 'otp',
    ];

    /** Client IP for audit rows (x-forwarded-for first hop, else x-real-ip). */
    public static function clientIp(?Request $request = null): ?string
    {
        $request ??= request();

        $forwarded = $request->header('X-Forwarded-For');
        if ($forwarded !== null && $forwarded !== '') {
            return trim(explode(',', $forwarded)[0]);
        }

        $real = $request->header('X-Real-IP');

        return $real === null || $real === '' ? null : $real;
    }

    /**
     * Legacy hashClientIp (src/lib/ip.ts): sha-256 hex for a raw IP, plain `''`
     * for null/empty — never null, never throws. The raw IP is never persisted;
     * only the digest, for abuse correlation on public request endpoints.
     */
    public static function hashIp(?string $ip): string
    {
        if ($ip === null || $ip === '') {
            return '';
        }

        return hash('sha256', $ip);
    }

    private static function isSecretKey(string $key): bool
    {
        $normalized = strtolower(preg_replace('/[^a-zA-Z_]/', '', $key) ?? $key);

        return in_array($normalized, self::SECRET_KEYS, true);
    }

    /** Deep copy replacing secret-looking fields with [REDACTED]. */
    public static function redact(mixed $value): mixed
    {
        if (is_array($value)) {
            $isList = array_is_list($value);
            $out = [];

            foreach ($value as $key => $item) {
                if (! $isList && is_string($key) && self::isSecretKey($key)) {
                    $out[$key] = self::REDACTED;
                } else {
                    $out[$key] = self::redact($item);
                }
            }

            return $out;
        }

        return $value;
    }

    private static function deepEqual(mixed $a, mixed $b): bool
    {
        if ($a === $b) {
            return true;
        }

        if ($a instanceof \DateTimeInterface && $b instanceof \DateTimeInterface) {
            return $a->getTimestamp() === $b->getTimestamp()
                && $a->format('u') === $b->format('u');
        }

        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b)) {
                return false;
            }

            foreach ($a as $key => $value) {
                if (! array_key_exists($key, $b) || ! self::deepEqual($value, $b[$key])) {
                    return false;
                }
            }

            return true;
        }

        if (is_numeric($a) && is_numeric($b) && ! is_string($a) && ! is_string($b)) {
            return (string) $a === (string) $b;
        }

        return false;
    }

    /**
     * Shallow-key diff (top-level fields, deep value comparison).
     * Both sides are redacted for secrets.
     *
     * @return array{old: array<string, mixed>, new: array<string, mixed>}
     */
    public static function diffObjects(mixed $before, mixed $after): array
    {
        $oldOut = [];
        $newOut = [];

        if (! is_array($before) || ! is_array($after)) {
            return ['old' => $oldOut, 'new' => $newOut];
        }

        $keys = array_unique(array_merge(array_keys($before), array_keys($after)));

        foreach ($keys as $key) {
            $from = $before[$key] ?? null;
            $to = $after[$key] ?? null;

            if (! self::deepEqual($from, $to)) {
                if (array_key_exists($key, $before)) {
                    $oldOut[$key] = $from;
                }

                if (array_key_exists($key, $after)) {
                    $newOut[$key] = $to;
                }
            }
        }

        return [
            'old' => self::redact($oldOut),
            'new' => self::redact($newOut),
        ];
    }

    /** @param array{old: array<string, mixed>, new: array<string, mixed>} $diff */
    public static function hasChanges(array $diff): bool
    {
        return $diff['old'] !== [] || $diff['new'] !== [];
    }

    /**
     * Write one audit_log row. Never throws.
     *
     * @param  array<string, mixed>|object|null  $before
     * @param  array<string, mixed>|object|null  $after
     * @param  array{id?: string|null, name?: string|null}|null  $user
     */
    public static function write(
        string $entity,
        string $entityId,
        string $action,
        array|object|null $before = null,
        array|object|null $after = null,
        ?array $user = null,
        ?string $ip = '__capture__',
    ): void {
        try {
            $oldJson = null;
            $newJson = null;

            if ($before !== null || $after !== null) {
                $diff = self::diffObjects(
                    self::toArray($before) ?? [],
                    self::toArray($after) ?? [],
                );

                if (self::hasChanges($diff)) {
                    // Keep the native slices: the AuditLog model's `array` cast
                    // encodes once. Pre-encoding here would double-encode the
                    // JSON and hand back a string when read.
                    $oldJson = $diff['old'];
                    $newJson = $diff['new'];
                }
            }

            // The actor is always explicit (legacy writeAudit takes `user`);
            // passing null records an anonymous event (failed login, public POSTs).
            $actorId = $user['id'] ?? null;

            // VIEW dedupe: same user + entity + entityId within a minute collapses.
            if ($action === 'VIEW' && $actorId !== null) {
                $recent = AuditLog::where('user_id', $actorId)
                    ->where('entity', $entity)
                    ->where('entity_id', $entityId)
                    ->where('action', 'VIEW')
                    ->where('created_at', '>=', now()->subMinute())
                    ->exists();

                if ($recent) {
                    return;
                }
            }

            AuditLog::create([
                'id' => (string) Str::ulid(),
                'user_id' => $actorId,
                'entity' => $entity,
                'entity_id' => $entityId,
                'action' => $action,
                'old_json' => $oldJson,
                'new_json' => $newJson,
                'ip' => $ip === '__capture__' ? self::clientIp() : $ip,
            ]);
        } catch (\Throwable $e) {
            Log::error('[audit] failed to write audit log: '.$e->getMessage());
        }
    }

    private static function toArray(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof Model) {
            return $value->getAttributes();
        }

        if (is_object($value)) {
            return get_object_vars($value);
        }

        return null;
    }
}
