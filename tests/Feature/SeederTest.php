<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Production seed (database/seeders/DatabaseSeeder.php): the 17 settings, the
 * doctor staff row and the three env accounts — idempotent, and NEVER any demo
 * patients/sales/appointments. Invalid env values fail fast without echoing.
 */
class SeederTest extends TestCase
{
    use RefreshDatabase;

    /** The 17 settings keys the seed must create. */
    private const SETTINGS_KEYS = [
        'clinic_name', 'currency', 'timezone', 'working_hours',
        'reminder_send_time', 'reminder_send_by_offset_minutes',
        'followup_lead_days', 'recall_months', 'review_link', 'sms_mode',
        'reception_can_send_campaigns', 'campaign_max_recipients',
        'digest_enabled', 'digest_email', 'default_language',
        'group_sms_allow_for_filtered', 'group_sms_max_recipients',
    ];

    public function test_it_creates_exactly_the_expected_settings_keys(): void
    {
        $this->seed(DatabaseSeeder::class);

        $keys = Setting::pluck('key')->all();
        sort($keys);
        $expected = self::SETTINGS_KEYS;
        sort($expected);

        $this->assertSame($expected, $keys);
    }

    public function test_the_default_settings_carry_their_legacy_values(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame('Vision Eye Clinic', Setting::find('clinic_name')->value);
        $this->assertSame('ETB', Setting::find('currency')->value);
        $this->assertSame('Africa/Addis_Ababa', Setting::find('timezone')->value);
        $this->assertSame('MANUAL_LINK', Setting::find('sms_mode')->value);
        $this->assertFalse(Setting::find('reception_can_send_campaigns')->value);
        $this->assertSame(300, Setting::find('campaign_max_recipients')->value);
        $this->assertSame('en', Setting::find('default_language')->value);
        $this->assertFalse(Setting::find('group_sms_allow_for_filtered')->value);
        $this->assertSame(15, Setting::find('group_sms_max_recipients')->value);
    }

    public function test_the_working_hours_default_closes_sunday(): void
    {
        $this->seed(DatabaseSeeder::class);

        $hours = Setting::find('working_hours')->value;
        $this->assertTrue($hours['sun']['closed']);
        $this->assertFalse($hours['mon']['closed']);
        $this->assertSame('08:00', $hours['mon']['open']);
        $this->assertSame('17:00', $hours['mon']['close']);
        $this->assertSame('12:00', $hours['sat']['close']);
    }

    public function test_it_seeds_the_doctor_staff_row(): void
    {
        $this->seed(DatabaseSeeder::class);

        $doctor = Staff::find('staff-doctor-1');
        $this->assertNotNull($doctor);
        $this->assertSame('Dr. Abebe Kebede', $doctor->full_name);
        $this->assertTrue($doctor->is_doctor);
        $this->assertSame('Ophthalmology', $doctor->specialty);
        $this->assertTrue($doctor->show_on_website);
    }

    public function test_it_seeds_the_three_env_accounts_with_their_roles(): void
    {
        $this->seed(DatabaseSeeder::class);

        $owner = User::where('email', 'owner@example.test')->sole();
        $reception = User::where('email', 'reception@example.test')->sole();
        $staff = User::where('email', 'staff@example.test')->sole();

        $this->assertSame('OWNER', $owner->role);
        $this->assertSame('RECEPTION', $reception->role);
        $this->assertSame('STAFF', $staff->role);
    }

    public function test_the_seeded_accounts_can_verify_their_env_passwords(): void
    {
        $this->seed(DatabaseSeeder::class);

        $owner = User::where('email', 'owner@example.test')->sole();
        $reception = User::where('email', 'reception@example.test')->sole();
        $staff = User::where('email', 'staff@example.test')->sole();

        $this->assertTrue(Hash::check('OwnerSeed123!', $owner->password));
        $this->assertTrue(Hash::check('ReceptionSeed123!', $reception->password));
        $this->assertTrue(Hash::check('StaffSeed123!', $staff->password));
    }

    public function test_the_seeded_accounts_are_active_and_not_forced_to_change_password(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (User::all() as $user) {
            $this->assertTrue($user->active);
            $this->assertFalse($user->must_change_password);
            $this->assertSame(0, $user->failed_login_count);
            $this->assertNull($user->locked_until);
        }
    }

    public function test_the_staff_account_is_linked_to_the_doctor_row(): void
    {
        $this->seed(DatabaseSeeder::class);

        $staff = User::where('email', 'staff@example.test')->sole();
        $this->assertSame('staff-doctor-1', $staff->staff_id);
    }

    public function test_the_seed_creates_no_demo_patients_or_financial_data(): void
    {
        $this->seed(DatabaseSeeder::class);

        // Production seed must never fabricate clinical/financial records.
        foreach (['patients', 'sales', 'sale_lines', 'appointments', 'message_tasks'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} must stay empty after the production seed.");
        }
    }

    public function test_reseeding_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(count(self::SETTINGS_KEYS), Setting::count());
        $this->assertSame(3, User::count());
        $this->assertSame(1, Staff::count());
    }

    public function test_reseeding_does_not_overwrite_admin_edited_settings(): void
    {
        $this->seed(DatabaseSeeder::class);

        Setting::find('clinic_name')->forceFill(['value' => 'My Clinic'])->save();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame('My Clinic', Setting::find('clinic_name')->value);
    }

    public function test_an_invalid_owner_email_fails_fast(): void
    {
        $this->assertSeederRejectsInvalidEnv('OWNER_EMAIL', 'not-an-email', 'OWNER_EMAIL must be a valid email address.');
    }

    public function test_a_too_short_seed_password_fails_fast(): void
    {
        $this->assertSeederRejectsInvalidEnv('STAFF_PASSWORD', 'short', 'STAFF_PASSWORD must be at least 6 characters.');
    }

    /**
     * Run the seeder with one env value overridden and assert it throws without
     * leaking the value. env() honours $_SERVER/$_ENV (putenv alone does not),
     * so both are set and restored.
     */
    private function assertSeederRejectsInvalidEnv(string $key, string $value, string $expectedMessage): void
    {
        $hadServer = array_key_exists($key, $_SERVER);
        $hadEnv = array_key_exists($key, $_ENV);
        $prevServer = $_SERVER[$key] ?? null;
        $prevEnv = $_ENV[$key] ?? null;

        $_SERVER[$key] = $value;
        $_ENV[$key] = $value;

        $threw = false;
        $message = '';

        try {
            (new DatabaseSeeder)->run();
        } catch (\RuntimeException $e) {
            $threw = true;
            $message = $e->getMessage();
        } finally {
            if ($hadServer) {
                $_SERVER[$key] = $prevServer;
            } else {
                unset($_SERVER[$key]);
            }

            if ($hadEnv) {
                $_ENV[$key] = $prevEnv;
            } else {
                unset($_ENV[$key]);
            }
        }

        $this->assertTrue($threw, 'The seeder must reject the invalid env value.');
        $this->assertSame($expectedMessage, $message);
    }
}
