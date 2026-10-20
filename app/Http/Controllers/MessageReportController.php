<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Messaging\MsgQueries;
use App\Support\Settings;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The expired-but-not-sent report (OWNER only).
 */
class MessageReportController extends Controller
{
    /** GET /messages/reports */
    public function index(Request $request): Response
    {
        return response()->view('messages.reports', [
            'groups' => MsgQueries::expiredNotSentReport(Settings::timezone()),
        ]);
    }
}
