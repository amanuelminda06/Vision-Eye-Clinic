<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\MessageTemplate;
use App\Models\Service;
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
        // Off by default: the clinic opts in from the settings screen.
        'patient_id_photos_enabled' => false,
        'patient_sick_leave_enabled' => false,
    ];

    /** The account credentials the seed needs (email key, password key). */
    private const ACCOUNT_ENV = [
        ['OWNER_EMAIL', 'OWNER_PASSWORD'],
        ['RECEPTION_EMAIL', 'RECEPTION_PASSWORD'],
        ['STAFF_EMAIL', 'STAFF_PASSWORD'],
    ];

    public function run(): void
    {
        // Validate the account credentials BEFORE anything is written: a
        // half-seeded database (settings and a doctor, but no services) is
        // worse than a clean failure.
        $this->assertAccountCredentials();

        $this->seedSettings();
        $doctor = $this->seedDoctor();
        $this->seedUsers($doctor);
        $this->seedServices();
        $this->seedMessageTemplates();

        $this->command?->info('Seeded settings, staff, services, message templates and accounts (no demo data).');
    }

    /**
     * Fails fast when the three account credentials are missing or invalid.
     *
     * Same checks and the same messages as the user creation itself — only
     * earlier, so a bad .env leaves NOTHING half-seeded. The values are never
     * echoed, only the NAME of the offending key.
     */
    private function assertAccountCredentials(): void
    {
        foreach (self::ACCOUNT_ENV as [$emailKey, $passwordKey]) {
            $email = mb_strtolower(trim((string) env($emailKey)));
            $password = (string) env($passwordKey);

            if (! preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email)) {
                throw new \RuntimeException("{$emailKey} must be a valid email address.");
            }

            if (strlen($password) < 6) {
                throw new \RuntimeException("{$passwordKey} must be at least 6 characters.");
            }
        }
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
     * The 13 message templates (en + am).
     *
     * The Amharic bodies are first-pass drafts marked "needs native review" in the
     * template editor — the clinic should refine them before going live.
     *
     * @var array<string, array{en: string, am: string}>
     */
    private const TEMPLATES = [
        'APPT_REMINDER' => [
            'en' => 'Hello {name}, this is a reminder of your appointment at {clinic} on {date} at {time} with {doctor}. Reply or call if you need to change it.',
            'am' => 'ሰላም {name}፣ በ{clinic} ቀን {date} ሰዓት {time} ከ{doctor} ጋር ያለዎትን ቀጠሮ እናስታውስዎታለን። መቀየር ከፈለጉ ይደውሉ።',
        ],
        'APPT_CONFIRMATION' => [
            'en' => 'Hello {name}, your appointment at {clinic} on {date} at {time} with {doctor} is confirmed. See you then.',
            'am' => 'ሰላም {name}፣ በ{clinic} ቀን {date} ሰዓት {time} ከ{doctor} ጋር ያለዎት ቀጠሮ ተረጋግጧል። እንገናኝ።',
        ],
        'APPT_SAME_DAY' => [
            'en' => 'Good morning {name}, you have an appointment today at {clinic} at {time} with {doctor}. Please arrive 10 minutes early.',
            'am' => 'እንደምን አደሩ {name}፣ ዛሬ በ{clinic} ሰዓት {time} ከ{doctor} ጋር ቀጠሮ አለዎት። 10 ደቂቃ ቀድመው ይምጡ።',
        ],
        'NO_SHOW' => [
            'en' => 'Hello {name}, we missed you at {clinic} today. Please call us to book a new appointment.',
            'am' => 'ሰላም {name}፣ ዛሬ በ{clinic} አልገኙም። አዲስ ቀጠሮ ለመያዝ ይደውሉልን።',
        ],
        'SCHEDULE_CHANGE' => [
            'en' => 'Hello {name}, your appointment at {clinic} has changed. The new time is {date} at {time} with {doctor}. Sorry for the inconvenience.',
            'am' => 'ሰላም {name}፣ በ{clinic} ያለዎት ቀጠሮ ተቀይሯል። አዲሱ ጊዜ {date} ሰዓት {time} ከ{doctor} ጋር ነው። ይቅርታ።',
        ],
        'FOLLOWUP_DUE' => [
            'en' => 'Hello {name}, it is time for your follow-up visit at {clinic}. Please call us to schedule it.',
            'am' => 'ሰላም {name}፣ በ{clinic} የክትትል ጉብኝትዎ ወቅት ደርሷል። ለመያዝ ይደውሉልን።',
        ],
        'RECALL' => [
            'en' => 'Hello {name}, it has been a while since your last visit to {clinic}. We would be glad to see you again — call us to book.',
            'am' => 'ሰላም {name}፣ ከመጨረሻ ጉብኝትዎ በ{clinic} ጊዜ ተቀይሯል። እንደገና ለማግኘት ደስ ይለናል — ለመያዝ ይደውሉ።',
        ],
        'GLASSES_READY' => [
            'en' => 'Hello {name}, your glasses are ready at {clinic}. You can collect them during working hours.',
            'am' => 'ሰላም {name}፣ መነጽርዎ በ{clinic} ተዘጋጅቷል። በስራ ሰዓት መውሰድ ይችላሉ።',
        ],
        'THANK_YOU' => [
            'en' => 'Thank you for visiting {clinic}, {name}. We hope you feel better.\\nPlease leave us a review: {review_link}',
            'am' => 'ስለ ጉብኝትዎ እናመሰግናለን {name}። እንዲሻሻልልዎ ተስፋ እናደርጋለን።\\nአስተያየትዎን ይስጡን: {review_link}',
        ],
        'BIRTHDAY' => [
            'en' => 'Happy birthday, {name}! From all of us at {clinic}.',
            'am' => 'መልካም ልደት {name}! ከ{clinic} ሰራተኞች።',
        ],
        'WELCOME' => [
            'en' => 'Welcome to {clinic}, {name}. We will send appointment reminders to this number. Reply STOP to stop messages.',
            'am' => 'ወደ {clinic} እንኳን በደህና መጡ {name}። የቀጠሮ ማስታወሻዎችን ወደዚህ ቁጥር እንልካለን። ለማቆም STOP ብለው ይላኩ።',
        ],
        'CLOSURE_NOTICE' => [
            'en' => 'Hello {name}, {clinic} will be closed and will reopen on {reopen_date}. We apologize for any inconvenience.',
            'am' => 'ሰላም {name}፣ {clinic} ይዘጋል እና በ{reopen_date} ይከፈታል። ይቅርታ።',
        ],
        'CUSTOM' => [
            'en' => 'Hello {name}, this is a message from {clinic}.',
            'am' => 'ሰላም {name}፣ ከ{clinic} የተላከ መልእክት ነው።',
        ],
    ];

    /**
     * The clinic's real six services.
     *
     * Idempotent and keyed on `name_en` (never a unique constraint): a
     * service that already exists keeps its clinic-set price and flags — only
     * the missing description is filled in. The legacy demo services are
     * deactivated, never deleted.
     *
     * @var list<array{nameEn: string, nameAm: string, category: string, price: string, displayOrder: int, imageUrl: string, receiptLabelEn?: string, receiptLabelAm?: string}>
     */
    private const SERVICES = [
        [
            'nameEn' => 'General Eye Care',
            'nameAm' => 'ጠቅላላ የዓይን ህክምና',
            'category' => 'consultation',
            'price' => '300.00',
            'displayOrder' => 1,
            'imageUrl' => '/images/services/general-eye-care.png',
        ],
        [
            'nameEn' => 'Eye Examination for Glasses (Modern Equipment)',
            'nameAm' => 'በዘመናዊ መሳሪያዎች የመነጽር ምርመራ',
            'category' => 'diagnostics',
            'price' => '500.00',
            'displayOrder' => 2,
            'imageUrl' => '/images/services/eye-examination-glasses.png',
        ],
        [
            'nameEn' => 'Glasses Making and Sales',
            'nameAm' => 'የመነጽር ስራ እና ሽያጭ',
            'category' => 'optical',
            'price' => '1500.00',
            'displayOrder' => 3,
            'imageUrl' => '/images/services/glasses-making-sales.png',
        ],
        [
            'nameEn' => 'Special Eye Exam and Follow-up for Diabetes and Blood Pressure Patients',
            'nameAm' => 'ለስኳርና ደም ግፊት ህመምተኞች ልዩ የዓይን ምርመራና ክትትል',
            'category' => 'specialist',
            'price' => '400.00',
            'displayOrder' => 4,
            'imageUrl' => '/images/services/specialist-diabetes-bp.png',
            'receiptLabelEn' => 'Specialist eye check-up',
            'receiptLabelAm' => 'ልዩ የዓይን ክትትል',
        ],
        [
            'nameEn' => "Children's Eye Exam and Treatment",
            'nameAm' => 'የህጻናት የዓይን ምርመራና ህክምና',
            'category' => 'specialist',
            'price' => '400.00',
            'displayOrder' => 5,
            'imageUrl' => '/images/services/children-eye-exam.png',
        ],
        [
            'nameEn' => 'Minor Eye Surgery',
            'nameAm' => 'መለስተኛ የዓይን ቀዶ ህክምና',
            'category' => 'surgery',
            'price' => '5000.00',
            'displayOrder' => 6,
            'imageUrl' => '/images/services/minor-eye-surgery.png',
        ],
    ];

    /**
     * The six real services (idempotent, clinic-set prices are preserved).
     */
    private function seedServices(): void
    {
        foreach (self::SERVICES as $service) {
            $existing = Service::query()
                ->where('name_en', $service['nameEn'])
                ->orWhere('name', $service['nameEn'])
                ->first();

            if ($existing !== null) {
                // The clinic owns the price and the flags — only fill in what
                // is missing.
                $existing->forceFill(array_filter([
                    'name_am' => $existing->name_am ?? $service['nameAm'],
                    'category' => $existing->category ?: $service['category'],
                    'image_url' => $existing->image_url ?? $service['imageUrl'],
                    'receipt_label_en' => $existing->receipt_label_en ?? ($service['receiptLabelEn'] ?? null),
                    'receipt_label_am' => $existing->receipt_label_am ?? ($service['receiptLabelAm'] ?? null),
                ], static fn ($value): bool => $value !== null))->save();

                continue;
            }

            Service::create([
                'name' => $service['nameEn'],
                'name_en' => $service['nameEn'],
                'name_am' => $service['nameAm'],
                'category' => $service['category'],
                'current_price' => $service['price'],
                'active' => true,
                'is_public' => true,
                'public_description_en' => null,
                'public_description_am' => null,
                'receipt_label_en' => $service['receiptLabelEn'] ?? null,
                'receipt_label_am' => $service['receiptLabelAm'] ?? null,
                'display_order' => $service['displayOrder'],
                'image_url' => $service['imageUrl'],
            ]);
        }
    }

    /**
     * The 13 message templates (en + am). The Amharic bodies are first-pass
     * drafts the clinic should refine before going live.
     */
    private function seedMessageTemplates(): void
    {
        foreach (self::TEMPLATES as $key => $bodies) {
            foreach (['en', 'am'] as $language) {
                $existing = MessageTemplate::query()
                    ->where('key', $key)
                    ->where('language', $language)
                    ->first();

                if ($existing !== null) {
                    continue;
                }

                MessageTemplate::create([
                    'key' => $key,
                    'language' => $language,
                    'body' => $bodies[$language],
                    'active' => true,
                ]);
            }
        }
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
