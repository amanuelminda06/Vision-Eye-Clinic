<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MessageTemplate;
use App\Support\Audit;
use App\Support\Messaging\Constants;
use App\Support\Messaging\MsgQueries;
use App\Support\Messaging\Templates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The message-template editor (OWNER only).
 *
 * A template body may only use the known placeholders; unknown ones are
 * reported live and block the save. The safety note is always rendered above
 * the editor.
 */
class MessageTemplateController extends Controller
{
    /** GET /messages/templates */
    public function index(): Response
    {
        $rows = MsgQueries::listTemplates();

        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row['key']][$row['language']] = $row;
        }

        return response()->view('messages.templates', [
            'templates' => $byKey,
            'keys' => Constants::TEMPLATE_KEYS,
            'languages' => Constants::MESSAGE_LANGUAGES,
            'safetyNote' => __('messages.'.Constants::TEMPLATE_SAFETY_NOTE_KEY),
        ]);
    }

    /** POST /messages/templates — save one template body. */
    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();

        $key = (string) $request->input('key', '');
        $language = (string) $request->input('language', '');
        $body = trim((string) $request->input('body', ''));
        $active = (string) $request->input('active', 'false') === 'true';

        if (! Constants::isTemplateKey($key)) {
            return back()->withErrors(['key' => 'invalidTemplate'])->withInput();
        }

        if (! Constants::isMessageLanguage($language)) {
            return back()->withErrors(['language' => 'invalidLanguage'])->withInput();
        }

        if ($body === '') {
            return back()->withErrors(['body' => 'required'])->withInput();
        }

        if (mb_strlen($body) > Constants::MAX_TEMPLATE_BODY) {
            return back()->withErrors(['body' => 'tooLong'])->withInput();
        }

        $check = Templates::validateTemplateBody($body);
        if (! $check['ok']) {
            return back()->withErrors(['body' => 'unknownVariable'])->withInput();
        }

        $template = MessageTemplate::query()->updateOrCreate(
            ['key' => $key, 'language' => $language],
            ['body' => $body, 'active' => $active, 'updated_by' => $actor->id],
        );

        Audit::write('MessageTemplate', $template->id, 'MESSAGE_TEMPLATE_SAVED', null, [
            'key' => $key,
            'language' => $language,
            'active' => $active,
        ], ['id' => $actor->id, 'name' => $actor->name]);

        return back();
    }
}
