<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MessageDraft;
use App\Models\MessageLibraryItem;
use App\Support\Audit;
use App\Support\Messaging\Constants;
use App\Support\Messaging\MessageHash;
use App\Support\Messaging\MsgQueries;
use App\Support\Messaging\MsgRoles;
use App\Support\Messaging\Templates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The message library, the drafts and the campaigns.
 */
class MessageLibraryController extends Controller
{
    /** GET /messages/library */
    public function index(Request $request): Response
    {
        $actor = $request->user();

        return response()->view('messages.library', [
            'rows' => MsgQueries::listLibraryItems(),
            'categories' => Constants::LIBRARY_CATEGORIES,
            'canManage' => MsgRoles::canManageLibrary($actor->role),
        ]);
    }

    /** POST /messages/library — save a library item. */
    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();

        $body = trim((string) $request->input('body', ''));
        $language = (string) $request->input('language', 'en');
        $category = (string) $request->input('category', 'OTHER');
        $title = trim((string) $request->input('title', ''));

        $check = Templates::validateMessageBody($body);

        if (! $check['ok']) {
            return back()->withErrors(['body' => $check['error']])->withInput();
        }

        if (! Constants::isMessageLanguage($language)) {
            return back()->withErrors(['language' => 'invalidLanguage'])->withInput();
        }

        if (! in_array($category, Constants::LIBRARY_CATEGORIES, true)) {
            return back()->withErrors(['category' => 'invalid'])->withInput();
        }

        // The duplicate guard is per language.
        $hash = MessageHash::messageBodyHash($body);
        $existing = MessageLibraryItem::query()
            ->where('normalized_hash', $hash)
            ->where('language', $language)
            ->first();

        if ($existing !== null) {
            return back()->with('actionError', 'libraryDuplicate');
        }

        $item = MessageLibraryItem::create([
            'title' => $title !== '' ? mb_substr($title, 0, 120) : MessageHash::titleFromBody($body),
            'body' => $body,
            'language' => $language,
            'category' => $category,
            'normalized_hash' => $hash,
            'created_by' => $actor->id,
            'active' => true,
        ]);

        Audit::write('MessageLibraryItem', $item->id, 'MESSAGE_LIBRARY_SAVED', null, [
            'title' => $item->title,
            'language' => $language,
            'category' => $category,
        ], ['id' => $actor->id, 'name' => $actor->name]);

        return back();
    }

    /** POST /messages/library/{id}/toggle — favourite / archive. */
    public function toggle(Request $request, string $id): RedirectResponse
    {
        $actor = $request->user();

        $item = MessageLibraryItem::find($id);

        if ($item === null) {
            return back()->with('actionError', 'notFound');
        }

        $field = (string) $request->input('field', 'isFavorite');
        $value = (string) $request->input('value', 'false') === 'true';

        if ($field === 'isFavorite') {
            $item->forceFill(['is_favorite' => $value])->save();
        } elseif ($field === 'active') {
            // Hide = deactivate, never delete.
            $item->forceFill(['active' => $value])->save();
        }

        Audit::write('MessageLibraryItem', $id, 'MESSAGE_LIBRARY_UPDATED', null, [
            $field => $value,
        ], ['id' => $actor->id, 'name' => $actor->name]);

        return back();
    }

    /** GET /messages/drafts */
    public function drafts(Request $request): Response
    {
        return response()->view('messages.drafts', [
            'rows' => MsgQueries::listDrafts($request->user()->id),
        ]);
    }

    /** POST /messages/drafts — save a draft. */
    public function storeDraft(Request $request): RedirectResponse
    {
        $actor = $request->user();

        $body = trim((string) $request->input('body', ''));
        $language = (string) $request->input('language', 'en');
        $title = trim((string) $request->input('title', ''));
        $draftId = (string) $request->input('id', '');

        if ($body === '' || mb_strlen($body) > Constants::MAX_CAMPAIGN_MESSAGE) {
            return back()->withErrors(['body' => $body === '' ? 'required' : 'tooLong'])->withInput();
        }

        if (! Constants::isMessageLanguage($language)) {
            return back()->withErrors(['language' => 'invalidLanguage'])->withInput();
        }

        $draft = $draftId === ''
            ? MessageDraft::create([
                'created_by' => $actor->id,
                'title' => $title !== '' ? mb_substr($title, 0, 120) : null,
                'body' => $body,
                'language' => $language,
            ])
            : MessageDraft::query()
                ->where('id', $draftId)
                ->where('created_by', $actor->id)
                ->firstOrFail();

        $draft->forceFill([
            'title' => $title !== '' ? mb_substr($title, 0, 120) : null,
            'body' => $body,
            'language' => $language,
        ])->save();

        Audit::write('MessageDraft', $draft->id, 'MESSAGE_DRAFT_SAVED', null, [
            'title' => $draft->title,
            'language' => $language,
        ], ['id' => $actor->id, 'name' => $actor->name]);

        return back();
    }

    /** POST /messages/drafts/{id}/delete — drafts are private and removable. */
    public function destroyDraft(Request $request, string $id): RedirectResponse
    {
        $actor = $request->user();

        $draft = MessageDraft::query()
            ->where('id', $id)
            ->where('created_by', $actor->id)
            ->first();

        if ($draft === null) {
            return back()->with('actionError', 'notFound');
        }

        $draft->delete();

        return back();
    }
}
