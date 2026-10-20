<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The OWNER-only shell surfaces: the user accounts, the staff records, the
 * settings and the audit viewer.
 */
class ShellAdminTest extends TestCase
{
    use CreatesUsers;
    use RefreshDatabase;

    public function test_the_owner_only_surfaces_are_closed_to_the_other_roles(): void
    {
        foreach ([Roles::RECEPTION, Roles::STAFF] as $role) {
            $user = $this->createUser(['role' => $role]);

            foreach (['/users', '/users/new', '/staff', '/staff/new', '/settings', '/audit', '/dashboard/daily'] as $path) {
                $this->actingAs($user)->get($path)->assertRedirect('/403');
            }

            $this->actingAs($user->fresh())->post('/users', [])->assertForbidden();
            $this->actingAs($user->fresh())->post('/staff', [])->assertForbidden();
            $this->actingAs($user->fresh())->post('/settings', [])->assertForbidden();
        }
    }

    public function test_the_owner_creates_a_user(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $this->actingAs($owner)->post('/users', [
            'name' => 'New Receptionist',
            'email' => 'Reception@Example.com',
            'role' => 'RECEPTION',
            'password' => 'CorrectHorseBattery9',
        ])->assertRedirect('/users');

        $user = User::query()->where('email', 'reception@example.com')->firstOrFail();

        $this->assertSame('New Receptionist', (string) $user->name);
        $this->assertSame('RECEPTION', (string) $user->role);
        $this->assertTrue((bool) $user->must_change_password);
        $this->assertTrue((bool) $user->active);

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'User',
            'entity_id' => $user->id,
            'action' => 'USER_CREATED',
        ]);
    }

    public function test_a_duplicate_email_is_refused(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $this->createUser(['role' => Roles::STAFF, 'email' => 'taken@example.com']);

        $this->actingAs($owner)->post('/users', [
            'name' => 'Another',
            'email' => 'taken@example.com',
            'role' => 'STAFF',
            'password' => 'CorrectHorseBattery9',
        ])->assertSessionHasErrors('email');
    }

    public function test_a_weak_password_is_refused(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $this->actingAs($owner)->post('/users', [
            'name' => 'Weak',
            'email' => 'weak@example.com',
            'role' => 'STAFF',
            'password' => 'short',
        ])->assertSessionHasErrors('password');

        $this->assertSame(0, User::query()->where('email', 'weak@example.com')->count());
    }

    public function test_the_owner_updates_a_user(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $target = $this->createUser(['role' => Roles::STAFF, 'name' => 'Old Name']);

        $this->actingAs($owner)->post("/users/{$target->id}", [
            'name' => 'New Name',
            'email' => $target->email,
            'role' => 'RECEPTION',
        ])->assertRedirect('/users');

        $target->refresh();

        $this->assertSame('New Name', (string) $target->name);
        $this->assertSame('RECEPTION', (string) $target->role);

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'User',
            'entity_id' => $target->id,
            'action' => 'USER_UPDATED',
        ]);
    }

    public function test_a_user_cannot_deactivate_themselves(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $this->actingAs($owner)->post("/users/{$owner->id}/status", ['active' => 'false'])
            ->assertSessionHas('actionError', 'cannotDeactivateSelf');

        $this->assertTrue((bool) $owner->fresh()->active);
    }

    public function test_deactivating_a_user_is_audited_and_never_deletes_them(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $target = $this->createUser(['role' => Roles::STAFF]);

        $this->actingAs($owner)->post("/users/{$target->id}/status", ['active' => 'false'])
            ->assertRedirect();

        $this->assertFalse((bool) $target->fresh()->active);
        $this->assertSame(1, User::query()->where('id', $target->id)->count());

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'User',
            'entity_id' => $target->id,
            'action' => 'USER_STATUS_CHANGED',
        ]);
    }

    public function test_a_no_op_status_toggle_audits_nothing(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $target = $this->createUser(['role' => Roles::STAFF]);

        $this->actingAs($owner)->post("/users/{$target->id}/status", ['active' => 'true'])
            ->assertRedirect();

        $this->assertSame(0, AuditLog::query()->where('action', 'USER_STATUS_CHANGED')->count());
    }

    public function test_the_password_reset_shows_the_temporary_password_once_and_never_audits_it(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $target = $this->createUser(['role' => Roles::STAFF]);

        $response = $this->actingAs($owner)->post("/users/{$target->id}/reset-password");

        $response->assertRedirect();
        $response->assertSessionHas('tempPassword');

        $temp = (string) session('tempPassword');
        $this->assertSame(14, mb_strlen($temp));
        $this->assertTrue((bool) $target->fresh()->must_change_password);

        $audit = AuditLog::query()->where('action', 'USER_PASSWORD_RESET')->firstOrFail();
        $this->assertArrayNotHasKey('password', $audit->new_json);
        $this->assertStringNotContainsString($temp, (string) json_encode($audit->new_json));
    }

    public function test_the_owner_creates_a_staff_member(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $this->actingAs($owner)->post('/staff', [
            'fullName' => 'Dr. Abebe Kebede',
            'jobTitle' => 'Ophthalmologist',
            'isDoctor' => 'true',
            'specialty' => 'Retina',
            'phone' => '0911223344',
            'showOnWebsite' => 'true',
        ])->assertRedirect('/staff');

        $staff = Staff::query()->firstOrFail();

        $this->assertSame('Dr. Abebe Kebede', (string) $staff->full_name);
        $this->assertTrue((bool) $staff->is_doctor);
        $this->assertSame('+251911223344', (string) $staff->phone);
        $this->assertTrue((bool) $staff->show_on_website);

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'Staff',
            'entity_id' => $staff->id,
            'action' => 'STAFF_CREATED',
        ]);
    }

    public function test_an_invalid_phone_is_refused(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $this->actingAs($owner)->post('/staff', [
            'fullName' => 'Bad Phone',
            'jobTitle' => 'Receptionist',
            'phone' => '12345',
        ])->assertSessionHasErrors('phone');
    }

    public function test_the_owner_saves_the_settings_and_the_change_is_audited(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $this->actingAs($owner)->post('/settings', [
            'clinic_name' => 'Vision Eye Clinic',
            'currency' => 'ETB',
            'timezone' => 'Africa/Addis_Ababa',
            'reminder_send_time' => '14:00',
            'reminder_send_by_offset_minutes' => 60,
            'followup_lead_days' => 3,
            'recall_months' => 12,
            'review_link' => 'https://g.page/vision-eye-clinic/review',
            'sms_mode' => 'MANUAL_LINK',
            'campaign_max_recipients' => 300,
            'digest_email' => '',
            'default_language' => 'en',
            'group_sms_max_recipients' => 15,
        ])->assertRedirect();

        $this->assertSame('Vision Eye Clinic', (string) Setting::query()->where('key', 'clinic_name')->value('value'));

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'Setting',
            'entity_id' => 'settings',
            'action' => 'SETTING_UPDATED',
        ]);
    }

    public function test_the_settings_form_renders_with_the_working_hours(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        // The working_hours value is an array — the form must render it without
        // passing an array to the escaping helper.
        Setting::create([
            'key' => 'working_hours',
            'value' => [
                'mon' => ['open' => '08:00', 'close' => '17:00', 'closed' => false],
                'sun' => ['open' => '08:00', 'close' => '12:00', 'closed' => true],
            ],
        ]);

        $response = $this->actingAs($owner)->get('/settings');

        $response->assertOk();
        $response->assertSee('name="working_hours[mon][open]"', false);
        $response->assertSee('name="working_hours[sun][closed]"', false);
    }

    public function test_an_invalid_setting_is_refused(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        $this->actingAs($owner)->post('/settings', [
            'clinic_name' => 'Clinic',
            'currency' => 'ETB',
            'timezone' => 'Not/AZone',
            'reminder_send_time' => '14:00',
            'reminder_send_by_offset_minutes' => 60,
            'followup_lead_days' => 3,
            'recall_months' => 12,
            'review_link' => '',
            'sms_mode' => 'MANUAL_LINK',
            'campaign_max_recipients' => 300,
            'digest_email' => '',
            'default_language' => 'en',
            'group_sms_max_recipients' => 15,
        ])->assertSessionHasErrors('timezone');
    }

    public function test_the_audit_viewer_lists_the_rows_and_exports_them(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        AuditLog::create([
            'user_id' => $owner->id,
            'entity' => 'Patient',
            'entity_id' => 'pat-1',
            'action' => 'PATIENT_CREATED',
            'old_json' => null,
            'new_json' => ['name' => 'Someone'],
            'ip' => '203.0.113.1',
        ]);

        $response = $this->actingAs($owner)->get('/audit');

        $response->assertOk();
        $response->assertSee('PATIENT_CREATED');
        $response->assertSee('Patient');

        $export = $this->actingAs($owner->fresh())->get('/audit/export?format=csv');

        $export->assertOk();
        $export->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        $this->assertStringContainsString('PATIENT_CREATED', $export->getContent());
    }

    public function test_the_audit_viewer_filters_by_action(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);

        AuditLog::create([
            'user_id' => $owner->id,
            'entity' => 'Patient',
            'entity_id' => 'pat-1',
            'action' => 'PATIENT_CREATED',
            'old_json' => null,
            'new_json' => null,
            'ip' => null,
        ]);
        AuditLog::create([
            'user_id' => $owner->id,
            'entity' => 'Sale',
            'entity_id' => 'sale-1',
            'action' => 'SALE_CREATED',
            'old_json' => null,
            'new_json' => null,
            'ip' => null,
        ]);

        $body = $this->actingAs($owner->fresh())->get('/audit?action=SALE_CREATED')->getContent();

        $this->assertStringContainsString('SALE_CREATED', $body);
        $this->assertStringNotContainsString('PATIENT_CREATED', $body);
    }

    public function test_the_daily_summary_shows_the_day_totals(): void
    {
        $owner = $this->createUser(['role' => Roles::OWNER]);
        $service = Service::factory()->create(['current_price' => '300.00']);

        $sale = Sale::create([
            'receipt_number' => '2026-000123',
            'sale_date' => now(),
            'status' => 'CONFIRMED',
            'payment_method' => 'CASH',
            'total' => '300.00',
            'created_by' => $owner->id,
            'confirmed_at' => now(),
        ]);

        SaleLine::create([
            'sale_id' => $sale->id,
            'service_id' => $service->id,
            'service_name_snapshot' => $service->name,
            'qty' => 1,
            'unit_price' => '300.00',
            'line_total' => '300.00',
        ]);

        $response = $this->actingAs($owner)->get('/dashboard/daily');

        $response->assertOk();
        $response->assertSee('2026-000123');
        $response->assertSee('ETB 300.00');
    }
}
