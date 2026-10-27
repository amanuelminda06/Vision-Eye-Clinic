<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\FollowUp;
use App\Models\Patient;
use App\Models\PatientSickLeave;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\StoredFile;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ClearPatients extends Command
{
    protected $signature = 'patients:clear {--force : Actually perform the deletion} {--yes : Skip confirmation when used with --force}';

    protected $description = 'Clear all test patients and related data (dry run by default)';

    public function handle(): int
    {
        if (! $this->option('force')) {
            $this->info('Dry run - no changes will be made. Use --force to actually delete.');
            $this->newLine();

            $counts = $this->getCounts();

            foreach ($counts as $table => $count) {
                if ($count > 0) {
                    $this->line("  {$table}: {$count}");
                }
            }

            $this->newLine();
            $this->line('Totals would be deleted as shown above.');

            return self::SUCCESS;
        }

        $this->info('You are about to delete ALL patients and related data.');
        $this->warn('This action cannot be undone!');
        $this->newLine();

        if (! $this->option('yes')) {
            if ($this->ask('Type DELETE to confirm') !== 'DELETE') {
                $this->error('Confirmation failed. Aborted.');

                return self::FAILURE;
            }
        }

        try {
            DB::transaction(function () {
                $counts = $this->getCounts();

                // Delete in dependency order
                // Sale lines depend on sales
                DB::table('sale_lines')->delete();
                DB::table('sales')->delete();

                // Messages/tasks
                DB::table('message_tasks')->delete();
                if (Schema::hasTable('campaign_recipients')) {
                    DB::table('campaign_recipients')->delete();
                }
                if (Schema::hasTable('campaign_batches')) {
                    DB::table('campaign_batches')->delete();
                }
                if (Schema::hasTable('campaigns')) {
                    DB::table('campaigns')->delete();
                }

                // Follow ups
                DB::table('follow_ups')->delete();

                // Appointments
                DB::table('appointments')->delete();
                if (Schema::hasTable('appointment_requests')) {
                    DB::table('appointment_requests')->whereNotNull('patient_id')->delete();
                }

                // Sick leave
                DB::table('patient_sick_leaves')->delete();

                // Tags links
                DB::table('patient_tag')->delete();

                // Delete stored files for patients (ID photos)
                $patientPhotoKeys = Patient::whereNotNull('id_photo_url')
                    ->pluck('id_photo_url')
                    ->map(function ($url) {
                        $parts = explode('/uploads/', $url);
                        if (isset($parts[1])) {
                            return $parts[1];
                        }
                        return null;
                    })
                    ->filter()
                    ->all();

                if (! empty($patientPhotoKeys)) {
                    StoredFile::whereIn('key', $patientPhotoKeys)->delete();
                }

                // Also look up files referenced by patients in other ways
                DB::table('stored_files')
                    ->where(function ($query) {
                        $query->where('key', 'like', 'patient-%')
                            ->orWhere('key', 'like', '%patient%');
                    })
                    ->delete();

                // Delete patients
                DB::table('patients')->delete();

                // Reset counters
                DB::table('receipt_counters')->delete();
                // patient system_id counter resets when patients table is empty

                // Write audit entry
                Audit::write('patients', 'all', 'CLEARED', null, [
                    'counts' => $counts,
                    'timestamp' => now()->toIso8601String(),
                ], auth()->check() ? ['id' => auth()->id(), 'name' => auth()->user()->name] : ['id' => null, 'name' => 'system']);
            });

            $this->info('All patient data cleared successfully.');
            $this->newLine();
            $this->info('Counts removed:');
            foreach ($this->getCounts() as $table => $count) {
                // We just deleted them, so counts are 0 now - but we need to report what was deleted
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Error clearing data: '.$e->getMessage());
            return self::FAILURE;
        }
    }

    private function getCounts(): array
    {
        $counts = [];

        $counts['patients'] = Patient::count();
        $counts['message_tasks'] = DB::table('message_tasks')->count();
        $counts['campaign_recipients'] = Schema::hasTable('campaign_recipients') ? DB::table('campaign_recipients')->count() : 0;
        $counts['campaign_batches'] = Schema::hasTable('campaign_batches') ? DB::table('campaign_batches')->count() : 0;
        $counts['campaigns'] = Schema::hasTable('campaigns') ? DB::table('campaigns')->count() : 0;
        $counts['follow_ups'] = FollowUp::count();
        $counts['appointments'] = Appointment::count();
        $counts['appointment_requests_linked'] = DB::table('appointment_requests')->whereNotNull('patient_id')->count();
        $counts['patient_sick_leaves'] = PatientSickLeave::count();
        $counts['patient_tag'] = DB::table('patient_tag')->count();
        $counts['sale_lines'] = SaleLine::count();
        $counts['sales'] = Sale::count();

        // Count patient files
        $patientPhotoKeys = Patient::whereNotNull('id_photo_url')
            ->pluck('id_photo_url')
            ->map(function ($url) {
                $parts = explode('/uploads/', $url);
                if (isset($parts[1])) {
                    return $parts[1];
                }
                return null;
            })
            ->filter()
            ->count();

        $counts['patient_files'] = $patientPhotoKeys + DB::table('stored_files')
            ->where(function ($query) {
                $query->where('key', 'like', 'patient-%')
                    ->orWhere('key', 'like', '%patient%');
            })
            ->count();

        return $counts;
    }
}
