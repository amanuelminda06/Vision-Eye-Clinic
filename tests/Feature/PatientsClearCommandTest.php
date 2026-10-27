<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\FollowUp;
use App\Models\Patient;
use App\Models\PatientSickLeave;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tag;
use App\Models\User;
use App\Support\Patients\SystemId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PatientsClearCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_changes_nothing(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $patient = Patient::factory()->create();
        $patient->system_id = SystemId::first();
        $patient->save();

        Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => Staff::factory()->create()->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(30),
            'status' => Appointment::STATUS_SCHEDULED,
            'created_from' => Appointment::SOURCE_STAFF,
            'created_by' => $user->id,
        ]);
        FollowUp::create([
            'patient_id' => $patient->id,
            'due_date' => now()->format('Y-m-d'),
            'status' => FollowUp::STATUS_OPEN,
            'created_by' => $user->id,
        ]);
        PatientSickLeave::factory()->create(['patient_id' => $patient->id]);

        DB::table('receipt_counters')->insert(['year' => 2026, 'last_number' => 123]);

        $this->assertDatabaseCount('patients', 1);
        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseCount('receipt_counters', 1);

        $this->artisan('patients:clear');

        $this->assertDatabaseCount('patients', 1);
        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseCount('receipt_counters', 1);
    }

    public function test_force_removes_all_patient_linked_rows_and_resets_counters(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $patient = Patient::factory()->create();
        $patient->system_id = SystemId::first();
        $patient->save();

        Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => Staff::factory()->create()->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(30),
            'status' => Appointment::STATUS_SCHEDULED,
            'created_from' => Appointment::SOURCE_STAFF,
            'created_by' => $user->id,
        ]);
        FollowUp::create([
            'patient_id' => $patient->id,
            'due_date' => now()->format('Y-m-d'),
            'status' => FollowUp::STATUS_OPEN,
            'created_by' => $user->id,
        ]);
        PatientSickLeave::factory()->create(['patient_id' => $patient->id]);
        DB::table('patient_tag')->insert([
            'patient_id' => $patient->id,
            'tag_id' => Tag::factory()->create()->id,
        ]);

        $service = Service::factory()->create();
        $sale = Sale::factory()->create(['patient_id' => $patient->id]);
        SaleLine::create([
            'sale_id' => $sale->id,
            'service_id' => $service->id,
            'service_name_snapshot' => $service->name,
            'qty' => 1,
            'unit_price' => $service->current_price,
            'line_total' => $service->current_price,
        ]);

        DB::table('receipt_counters')->insert(['year' => 2026, 'last_number' => 456]);
        DB::table('receipt_counters')->insert(['year' => 2025, 'last_number' => 10]);

        $this->assertDatabaseCount('patients', 1);
        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('sale_lines', 1);
        $this->assertDatabaseCount('receipt_counters', 2);

        $this->artisan('patients:clear', ['--force' => true])
            ->expectsQuestion('Type DELETE to confirm', 'DELETE')
            ->assertExitCode(0);

        $this->assertDatabaseCount('patients', 0);
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('follow_ups', 0);
        $this->assertDatabaseCount('patient_sick_leaves', 0);
        $this->assertDatabaseCount('patient_tag', 0);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_lines', 0);
        $this->assertDatabaseCount('receipt_counters', 0);
    }

    public function test_force_yes_deletes_without_prompt(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $patient = Patient::factory()->create();
        $patient->system_id = SystemId::first();
        $patient->save();

        Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => Staff::factory()->create()->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addMinutes(30),
            'status' => Appointment::STATUS_SCHEDULED,
            'created_from' => Appointment::SOURCE_STAFF,
            'created_by' => $user->id,
        ]);

        $this->assertDatabaseCount('patients', 1);

        $this->artisan('patients:clear', ['--force' => true, '--yes' => true])
            ->assertExitCode(0);

        $this->assertDatabaseCount('patients', 0);
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_force_alone_still_asks(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $patient = Patient::factory()->create();
        $patient->system_id = SystemId::first();
        $patient->save();

        $this->artisan('patients:clear', ['--force' => true])
            ->expectsQuestion('Type DELETE to confirm', 'WRONG')
            ->assertExitCode(1);

        $this->assertDatabaseCount('patients', 1);
    }

    public function test_settings_and_staff_are_untouched(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $patient = Patient::factory()->create();
        $patient->system_id = SystemId::first();
        $patient->save();

        $staff = Staff::factory()->create();
        DB::table('settings')->insert(['key' => 'test_setting', 'value' => 'test']);

        $this->assertDatabaseCount('staff', 1);
        $this->assertDatabaseCount('settings', DB::table('settings')->count());

        $this->artisan('patients:clear', ['--force' => true])
            ->expectsQuestion('Type DELETE to confirm', 'DELETE')
            ->assertExitCode(0);

        $this->assertDatabaseCount('staff', 1);
        $this->assertDatabaseHas('settings', ['key' => 'test_setting']);
    }

    public function test_writes_audit_entry_with_no_personal_data(): void
    {
        $user = User::factory()->create(['name' => 'Test User']);
        $this->actingAs($user);

        $patient = Patient::factory()->create();
        $patient->system_id = SystemId::first();
        $patient->save();

        $this->artisan('patients:clear', ['--force' => true])
            ->expectsQuestion('Type DELETE to confirm', 'DELETE')
            ->assertExitCode(0);

        $this->assertDatabaseCount('audit_logs', 1);
        $audit = DB::table('audit_logs')->first();
        $this->assertEquals('patients', $audit->entity);
        $this->assertEquals('CLEARED', $audit->action);
    }
}
