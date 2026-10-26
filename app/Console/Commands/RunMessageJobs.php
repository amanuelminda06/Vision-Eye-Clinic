<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Messaging\TaskGenerator;
use Illuminate\Console\Command;

/**
 * The message job: expires the overdue tasks and prepares the automatic
 * messages (reminders, same-day, thank-you, follow-up, recall).
 *
 * Run by the scheduler (`php artisan schedule:run`) every five minutes. It is
 * idempotent: the unique tuple (source_type, source_id, template_key,
 * patient_id) means a repeated run never duplicates a task.
 */
class RunMessageJobs extends Command
{
    protected $signature = 'messages:run-jobs';

    protected $description = 'Expire the overdue message tasks and prepare the automatic messages';

    public function handle(): int
    {
        $summary = TaskGenerator::runMessageJobs();

        $this->info(sprintf(
            'expired: %d · same-day: %d · thank-you: %d · follow-ups: %d · recalls: %d',
            $summary['expired'],
            $summary['sameDay'],
            $summary['thankYous'],
            $summary['followUps'],
            $summary['recalls'],
        ));

        return self::SUCCESS;
    }
}
