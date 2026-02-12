<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Http\Controllers\CP;

use Statamic\Http\Controllers\CP\CpController;
use Statamic\Facades\Entry;
use Illuminate\Http\Request;
use Ethernick\ActivityPubCore\Services\LinkPreview;

class LinkPreviewController extends CpController
{
    public function show(Request $request)
    {
        $noteId = $request->input('note_id');
        if (!$noteId) {
            return response()->json(['error' => 'Note ID required'], 400);
        }

        $note = Entry::find($noteId);
        if (!$note) {
            return response()->json(['error' => 'Note not found'], 404);
        }

        // Check for existing preview
        $existing = $note->get('link_preview');
        if ($existing && !empty($existing)) {
            return response()->json(['data' => $existing]);
        }

        // Extract URL
        $content = $note->get('content');
        // Parse Markdown to handle internal notes
        $htmlContent = \Statamic\Facades\Markdown::parse((string) $content);

        if ($url = LinkPreview::extractUrl($htmlContent)) {

            // Fetch Data
            $data = LinkPreview::fetch($url);

            if ($data) {
                // Persist
                $note->set('link_preview', $data);
                $note->save();

                return response()->json(['data' => $data]);
            }
        }

        return response()->json(['data' => null]);
    }
}
