<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\Response;

/**
 * Friendly forbidden page (legacy /403): no gate, reached by redirect from
 * role checks; title/message come from the `forbidden` i18n namespace.
 */
class ErrorController extends Controller
{
    public function forbidden(): Response
    {
        return response()->view('errors.403', [], 403);
    }
}
