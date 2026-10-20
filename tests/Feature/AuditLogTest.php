<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Audit writer against a real database (src/lib/audit.ts + audit-diff.ts).
 *
 * The audit table is append-only, stores only changed fields, redacts secrets,
 * dedupes VIEW rows to one per (user, entity, entityId) per minute, and NEVER
 * throws — a failed audit write must not break the business operation.
 */
class AuditLogTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    public function test_it_writes_a_plain_event_with_no_diff(): void
    {
        Audit::write('Sale', 'sale-1', 'VIEW', null, null, ['id' => 'u1', 'name' => 'A'], '9.9.9.9');

        $row = AuditLog::sole();
        $this->assertSame('u1', $row->user_id);
        $this->assertSame('Sale', $row->entity);
        $this->assertSame('sale-1', $row->entity_id);
        $this->assertSame('VIEW', $row->action);
        $this->assertNull($row->old_json);
        $this->assertNull($row->new_json);
        $this->assertSame('9.9.9.9', $row->ip);
    }

    public function test_it_stores_a_redacted_diff_of_only_the_changed_fields(): void
    {
        Audit::write(
            'User',
            'u2',
            'UPDATED',
            ['name' => 'Old', 'email' => 'same@x.test', 'passwordHash' => 'secret-hash'],
            ['name' => 'New', 'email' => 'same@x.test', 'passwordHash' => 'new-secret-hash'],
            ['id' => 'u1'],
            '9.9.9.9',
        );

        $row = AuditLog::sole();
        $this->assertSame(['name' => 'Old', 'passwordHash' => '[REDACTED]'], $row->old_json);
        $this->assertSame(['name' => 'New', 'passwordHash' => '[REDACTED]'], $row->new_json);
        $this->assertSame('9.9.9.9', $row->ip);
    }

    public function test_the_raw_columns_hold_valid_json(): void
    {
        Audit::write('User', 'u2', 'UPDATED', ['name' => 'Old'], ['name' => 'New']);

        $raw = DB::table('audit_logs')->sole();
        $decoded = json_decode((string) $raw->new_json, true);

        $this->assertSame(['name' => 'New'], $decoded);
    }

    public function test_it_stores_null_json_when_nothing_changed(): void
    {
        Audit::write('User', 'u3', 'TOUCHED', ['name' => 'Same'], ['name' => 'Same']);

        $row = AuditLog::sole();
        $this->assertNull($row->old_json);
        $this->assertNull($row->new_json);
    }

    public function test_an_anonymous_event_has_a_null_actor(): void
    {
        Audit::write('User', 'email:x@y.test', 'LOGIN_FAILED', null, ['reason' => 'unknownEmail'], null);

        $row = AuditLog::sole();
        $this->assertNull($row->user_id);
        $this->assertSame(['reason' => 'unknownEmail'], $row->new_json);
    }

    public function test_a_view_row_is_deduped_within_the_same_minute(): void
    {
        Audit::write('Patient', 'p1', 'VIEW', null, null, ['id' => 'u1']);
        Audit::write('Patient', 'p1', 'VIEW', null, null, ['id' => 'u1']);
        Audit::write('Patient', 'p1', 'VIEW', null, null, ['id' => 'u1']);

        $this->assertSame(1, AuditLog::where('action', 'VIEW')->count());
    }

    public function test_a_view_row_is_deduped_per_user_and_entity(): void
    {
        Audit::write('Patient', 'p1', 'VIEW', null, null, ['id' => 'u1']);
        Audit::write('Patient', 'p2', 'VIEW', null, null, ['id' => 'u1']);
        Audit::write('Sale', 'p1', 'VIEW', null, null, ['id' => 'u1']);
        Audit::write('Patient', 'p1', 'VIEW', null, null, ['id' => 'u2']);

        $this->assertSame(4, AuditLog::where('action', 'VIEW')->count());
    }

    public function test_a_view_row_is_not_deduped_once_the_minute_has_passed(): void
    {
        Audit::write('Patient', 'p1', 'VIEW', null, null, ['id' => 'u1']);

        // Backdate the first row beyond the one-minute window.
        AuditLog::sole()->forceFill(['created_at' => now()->subMinutes(2)])->save();

        Audit::write('Patient', 'p1', 'VIEW', null, null, ['id' => 'u1']);

        $this->assertSame(2, AuditLog::where('action', 'VIEW')->count());
    }

    public function test_an_anonymous_view_is_never_deduped(): void
    {
        Audit::write('PublicService', 'svc-1', 'VIEW', null, null, null);
        Audit::write('PublicService', 'svc-1', 'VIEW', null, null, null);

        $this->assertSame(2, AuditLog::count());
    }

    public function test_non_view_actions_are_never_deduped(): void
    {
        Audit::write('User', 'u1', 'UPDATED', ['a' => 1], ['a' => 2], ['id' => 'u9']);
        Audit::write('User', 'u1', 'UPDATED', ['a' => 2], ['a' => 3], ['id' => 'u9']);

        $this->assertSame(2, AuditLog::count());
    }

    public function test_it_never_throws_when_the_audit_table_is_unavailable(): void
    {
        Log::shouldReceive('error')->once();

        Schema::drop('audit_logs');

        // Must be a graceful no-op, not an exception bubbling into the caller.
        Audit::write('Sale', 's1', 'CREATED', null, ['total' => '100.00'], ['id' => 'u1']);

        $this->assertFalse(Schema::hasTable('audit_logs'));
    }

    public function test_it_uses_the_named_actor_id_verbatim(): void
    {
        $user = $this->createUser();

        Audit::write('User', $user->id, 'UPDATED', ['name' => 'A'], ['name' => 'B'], ['id' => $user->id]);

        $this->assertSame($user->id, AuditLog::sole()->user_id);
    }
}
