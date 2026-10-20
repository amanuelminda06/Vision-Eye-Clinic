<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MessageTask;
use App\Support\ClinicTime;
use Illuminate\Console\Command;

/**
 * The message job: expires the tasks whose window has passed.
 *
 * Run by the scheduler (`php artisan schedule:run`) every five minutes. The
 * HTTP endpoint `POST /api/jobs/messages` calls the same command so the
 * behaviour is identical whichever trigger the host uses.
 */
class RunMessageJobs extends Command
{
    protected $signature = 'messages:run-jobs';

    protected $description = 'Expire the message tasks whose send window has passed';

    public function handle(): int
    {
        $expired = MessageTask::query()
            ->whereIn('status', [MessageTask::STATUS_PENDING, MessageTask::STATUS_OPENED])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', ClinicTime::nowUtc())
            ->update(['status' => MessageTask::STATUS_EXPIRED]);

        $this->info("Expired {$expired} message task(s).");

        return self::SUCCESS;
    }
}
