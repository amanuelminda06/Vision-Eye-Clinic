<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\MessageTask;

/**
 * Sidebar badge for the /messages nav item (legacy countDueTasks):
 * status in [PENDING, OPENED] AND dueAt <= now. Computed in the layout for
 * OWNER/RECEPTION only; any failure degrades to 0 (legacy `catch → 0`).
 */
final class MessageBadge
{
    public static function count(): int
    {
        try {
            return MessageTask::query()
                ->whereIn('status', ['PENDING', 'OPENED'])
                ->where('due_at', '<=', ClinicTime::utcNowSql())
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }
}
