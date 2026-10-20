<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Audit;
use DateTimeImmutable;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Port of src/lib/audit-diff.test.ts, src/lib/ip.test.ts and the clientIp
 * cases of src/lib/audit.test.ts: deep redaction, changed-fields-only diffs,
 * value-based date comparison, IP capture order and public IP hashing.
 */
class AuditDiffTest extends TestCase
{
    public function test_redacts_secret_looking_fields_anywhere_in_the_value(): void
    {
        $value = [
            'name' => 'Abebe',
            'passwordHash' => '$argon2id$…',
            'nested' => ['token' => 'abc', 'keep' => 'visible'],
            'list' => [['password' => 'hunter2']],
        ];

        $this->assertSame([
            'name' => 'Abebe',
            'passwordHash' => '[REDACTED]',
            'nested' => ['token' => '[REDACTED]', 'keep' => 'visible'],
            'list' => [['password' => '[REDACTED]']],
        ], Audit::redact($value));
    }

    public function test_stores_only_changed_fields_on_each_side(): void
    {
        $diff = Audit::diffObjects(
            ['name' => 'Old', 'email' => 'same@x.test', 'role' => 'STAFF'],
            ['name' => 'New', 'email' => 'same@x.test', 'role' => 'OWNER'],
        );

        $this->assertSame(['name' => 'Old', 'role' => 'STAFF'], $diff['old']);
        $this->assertSame(['name' => 'New', 'role' => 'OWNER'], $diff['new']);
        $this->assertArrayNotHasKey('email', $diff['old']);
        $this->assertArrayNotHasKey('email', $diff['new']);
        $this->assertTrue(Audit::hasChanges($diff));
    }

    public function test_reports_no_changes_for_identical_objects(): void
    {
        $value = ['a' => 1, 'nested' => ['b' => [1, 2]]];
        $diff = Audit::diffObjects($value, ['a' => 1, 'nested' => ['b' => [1, 2]]]);
        $this->assertFalse(Audit::hasChanges($diff));
    }

    public function test_redacts_secrets_inside_the_diff_output(): void
    {
        $diff = Audit::diffObjects(
            ['passwordHash' => 'old-hash', 'active' => true],
            ['passwordHash' => 'new-hash', 'active' => false],
        );

        $this->assertSame('[REDACTED]', $diff['old']['passwordHash']);
        $this->assertSame('[REDACTED]', $diff['new']['passwordHash']);
        $this->assertFalse($diff['new']['active']);
    }

    public function test_treats_a_missing_field_on_one_side_as_a_change(): void
    {
        $diff = Audit::diffObjects(['staffId' => 'staff-1'], []);
        $this->assertSame(['staffId' => 'staff-1'], $diff['old']);
        $this->assertSame([], $diff['new']);
        $this->assertTrue(Audit::hasChanges($diff));
    }

    public function test_compares_dates_by_value_not_reference(): void
    {
        $diff = Audit::diffObjects(
            ['at' => new DateTimeImmutable('2026-01-01T00:00:00Z')],
            ['at' => new DateTimeImmutable('2026-01-01T00:00:00Z')],
        );
        $this->assertFalse(Audit::hasChanges($diff));

        $changed = Audit::diffObjects(
            ['at' => new DateTimeImmutable('2026-01-01T00:00:00Z')],
            ['at' => new DateTimeImmutable('2026-01-02T00:00:00Z')],
        );
        $this->assertTrue(Audit::hasChanges($changed));
    }

    public function test_non_array_sides_diff_to_nothing(): void
    {
        $diff = Audit::diffObjects(null, ['a' => 1]);
        $this->assertSame(['old' => [], 'new' => []], $diff);
        $this->assertFalse(Audit::hasChanges($diff));
    }

    public function test_client_ip_uses_the_first_x_forwarded_for_value(): void
    {
        $request = Request::create('/', 'GET', server: ['HTTP_X_FORWARDED_FOR' => '1.2.3.4, 5.6.7.8']);
        $this->assertSame('1.2.3.4', Audit::clientIp($request));
    }

    public function test_client_ip_falls_back_to_x_real_ip(): void
    {
        $request = Request::create('/', 'GET', server: ['HTTP_X_REAL_IP' => '1.2.3.4']);
        $this->assertSame('1.2.3.4', Audit::clientIp($request));
    }

    public function test_client_ip_is_null_when_no_proxy_headers_exist(): void
    {
        $request = Request::create('/', 'GET');
        $this->assertNull(Audit::clientIp($request));
    }

    public function test_x_forwarded_for_wins_over_x_real_ip(): void
    {
        $request = Request::create('/', 'GET', server: [
            'HTTP_X_FORWARDED_FOR' => '203.0.113.7, 10.0.0.1',
            'HTTP_X_REAL_IP' => '10.0.0.2',
        ]);
        $this->assertSame('203.0.113.7', Audit::clientIp($request));
    }

    public function test_hash_ip_returns_a_sha256_hex_digest(): void
    {
        $out = Audit::hashIp('196.188.12.34');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $out);
        $this->assertSame(hash('sha256', '196.188.12.34'), $out);
    }

    public function test_hash_ip_is_deterministic(): void
    {
        $this->assertSame(Audit::hashIp('1.2.3.4'), Audit::hashIp('1.2.3.4'));
    }

    public function test_hash_ip_handles_ipv6(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', Audit::hashIp('::ffff:192.0.2.1'));
    }

    public function test_hash_ip_returns_empty_string_for_null_or_empty(): void
    {
        $this->assertSame('', Audit::hashIp(null));
        $this->assertSame('', Audit::hashIp(''));
    }
}
