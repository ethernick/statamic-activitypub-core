<?php

namespace Ethernick\ActivityPubCore\Services;

use Statamic\Facades\Entry;

class ThreadService
{
    /**
     * Increment reply counts recursively up the thread chain.
     *
     * @param string|null $parentId The ID or ActivityPub URL of the parent note.
     * @param bool $quiet Whether to save without triggering events (e.g. for bulk updates).
     * @return void
     */
    public static function increment($parentId, $quiet = false)
    {
        if (!$parentId) {
            return;
        }

        self::propagate($parentId, 1, [], 0, $quiet);
    }

    /**
     * Decrement reply counts recursively up the thread chain.
     *
     * @param string|null $parentId The ID or ActivityPub URL of the parent note.
     * @param bool $quiet Whether to save without triggering events.
     * @return void
     */
    public static function decrement($parentId, $quiet = false)
    {
        if (!$parentId) {
            return;
        }

        self::propagate($parentId, -1, [], 0, $quiet);
    }

    protected static function propagate($noteId, $delta, $visited = [], $depth = 0, $quiet = false)
    {
        // Safety: Max depth to prevent stack overflow or extremely long chains
        if ($depth > 100) {
            return;
        }

        // Safety: Cycle detection
        if (in_array($noteId, $visited)) {
            return;
        }
        $visited[] = $noteId;

        // 1. Find the note
        $note = Entry::find($noteId);
        if (!$note) {
            // Try by AP ID
            $note = Entry::query()->whereIn('collection', ['notes', 'polls'])->where('activitypub_id', $noteId)->first();
        }

        // Try as Local URI if it starts with site URL
        if (!$note && is_string($noteId) && \Illuminate\Support\Str::startsWith($noteId, \Statamic\Facades\Site::default()->absoluteUrl())) {
            $uri = str_replace(\Statamic\Facades\Site::default()->absoluteUrl(), '', $noteId);
            $uri = '/' . ltrim($uri, '/');
            $note = Entry::findByUri($uri, \Statamic\Facades\Site::default()->handle());

            // If not found by URI, it might be a detailed route like /notes/id
            if (!$note && \Illuminate\Support\Str::contains($uri, '/notes/')) {
                $parts = explode('/', $uri);
                $possibleId = end($parts);
                $note = Entry::find($possibleId);
            }
        }

        if (!$note) {
            return;
        }

        // 2. Update Count
        $currentCount = (int) $note->get('reply_count', 0);
        $newCount = max(0, $currentCount + $delta);

        // Only save if changed
        if ($currentCount !== $newCount) {
            $note->set('reply_count', $newCount);
            if ($quiet) {
                $note->saveQuietly();
            } else {
                $note->save();
            }
        }

        // 3. Recurse to Parent
        $parentId = $note->get('in_reply_to');
        if ($parentId) {
            self::propagate($parentId, $delta, $visited, $depth + 1, $quiet);
        }
    }
}
