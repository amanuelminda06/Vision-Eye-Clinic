<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * PRODUCTION seed — exactly what a fresh clinic install needs:
 * the 17 settings defaults, the doctor staff row, and the three accounts
 * from env (OWNER/RECEPTION/STAFF). Idempotent, matching prisma/seed.ts.
 *
 * NO demo patients, sales, or appointments — those live in DemoSeeder
 * (php artisan db:seed --class=DemoSeeder, dev/test only).
 *
 * Later slices extend this class with: tags + services (slices 2/3),
 * message templates + library + audiences + site content (slices 6/7).
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /** The 17 form settings keys with their legacy seed defaults. */
    private const SETTINGS = [
        'clinic_name' => 'Vision Eye Clinic',
        'currency' => 'ETB',
        'timezone' => 'Africa/Addis_Ababa',
        'working_hours' => [
            'mon' => ['open' => '08:00', 'close' => '17:00', 'closed' => false],
            'tue' => ['open' => '08:00', 'close' => '17:00', 'closed' => false],
            'wed' => ['open' => '08:00', 'close' => '17:00', 'closed' => false],
            'thu' => ['open' => '08:00', 'close' => '17:00', 'closed' => false],
            'fri' => ['open' => '08:00', 'close' => '17:00', 'closed' => false],
            'sat' => ['open' => '08:00', 'close' => '12:00', 'closed' => false],
            'sun' => ['open' => '08:00', 'close' => '12:00', 'closed' => true],
        ],
        'reminder_send_time' => '14:00',
        'reminder_send_by_offset_minutes' => 60,
        'followup_lead_days' => 3,
        'recall_months' => 12,
        'review_link' => '',
        'sms_mode' => 'MANUAL_LINK',
        'reception_can_send_campaigns' => false,
        'campaign_max_recipients' => 300,
        'digest_enabled' => false,
        'digest_email' => '',
        'default_language' => 'en',
        'group_sms_allow_for_filtered' => false,
        'group_sms_max_recipients' => 15,
    ];

    public function run(): void
    {
        $this->seedSettings();
        $doctor = $this->seedDoctor();
        $this->seedUsers($doctor);

        $this->command?->info('Seeded settings, staff and accounts (no demo data).');
    }

    /** Settings only ever create missing keys — admin edits are never overwritten. */
    private function seedSettings(): void
    {
        foreach (self::SETTINGS as $key => $value) {
            if (! Setting::where('key', $key)->exists()) {
                Setting::create(['key' => $key, 'value' => $value]);
            }
        }
    }

    private function seedDoctor(): Staff
    {
        $doctor = Staff::firstOrNew(['id' => 'staff-doctor-1']);
        $doctor->forceFill([
            'full_name' => 'Dr. Abebe Kebede',
            'job_title' => 'Ophthalmologist',
            'is_doctor' => true,
            'specialty' => 'Ophthalmology',
            'show_on_website' => true,
        ])->save();

        return $doctor;
    }

    /**
     * The three accounts, upserted by email (legacy behavior re-applies data,
     * including the password hash — reseeding resets the seed passwords).
     *
     * @return list<User>
     */
    private function seedUsers(Staff $doctor): array
    {
        $definitions = [
            ['env' => ['OWNER_EMAIL', 'OWNER_PASSWORD'], 'name' => 'Clinic Owner', 'role' => 'OWNER', 'staffId' => null],
            ['env' => ['RECEPTION_EMAIL', 'RECEPTION_PASSWORD'], 'name' => 'Reception Desk', 'role' => 'RECEPTION', 'staffId' => null],
            ['env' => ['STAFF_EMAIL', 'STAFF_PASSWORD'], 'name' => 'Dr. Abebe Kebede', 'role' => 'STAFF', 'staffId' => $doctor->id],
        ];

        $users = [];

        foreach ($definitions as $definition) {
            [$emailKey, $passwordKey] = $definition['env'];

            $email = mb_strtolower(trim((string) env($emailKey)));
            $password = (string) env($passwordKey);

            // Fail fast without ever echoing the values (legacy src/env.ts).
            if (! preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email)) {
                throw new \RuntimeException("{$emailKey} must be a valid email address.");
            }

            if (strlen($password) < 6) {
                throw new \RuntimeException("{$passwordKey} must be at least 6 characters.");
            }

            $user = User::firstOrNew(['email' => $email]);
            $user->forceFill([
                'name' => $definition['name'],
                'password' => Hash::make($password),
                'role' => $definition['role'],
                'staff_id' => $definition['staffId'],
                'active' => true,
                // Seed users skip the first-login force (legacy seed behavior).
                'must_change_password' => false,
                'failed_login_count' => 0,
                'locked_until' => null,
            ])->save();

            $users[] = $user;
        }

        return $users;
    }
}
