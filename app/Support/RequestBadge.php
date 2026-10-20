<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AppointmentRequest;

/**
 * Sidebar badge for the /requests nav item (legacy `countNewRequests`): the
 * unhandled website requests. Computed in the layout for OWNER/RECEPTION
 * only; any failure degrades to 0 (legacy `catch → 0`).
 */
final class RequestBadge
{
    public static function count(): int
    {
        try {
            return AppointmentRequest::query()
                ->where('status', AppointmentRequest::STATUS_NEW)
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }
}
