<?php

namespace Ethernick\ActivityPubCore\Http\Controllers;

use Illuminate\Http\Request;
use Statamic\Facades\Entry;

class ReplyController extends BaseObjectController
{
    // We override index but with different signature? 
    // Laravel allows this if route parameters match.
    // BaseObjectController::index($handle)
    // ReplyController::index($handle, $uuid)

    public function index($handle, $uuid = null)
    {
        // 1. Find Actor
        $actor = $this->findActor($handle);

        if (!$actor) {
            abort(404, 'Actor not found');
        }

        // 2. Find Parent Note
        // We assume the UUID matches the slug or ID of the note
        // Logic duplicated from original controller but cleaner
        $parent = Entry::query()
            ->where('collection', 'notes')
            ->where('slug', $uuid)
            ->first();

        if (!$parent) {
            // Try ID search?
            $parent = Entry::find($uuid);
        }

        if (!$parent) {
            abort(404, 'Parent note not found');
        }

        // 3. Find Replies
        // We look for notes that have this parent's ID (or fully qualified URL) in 'in_reply_to'

        $parentUri = url('@' . $actor->slug() . '/notes/' . $parent->slug()); // Adjust based on actual note URL structure
        // Alternatively, use the ActivityPub ID if we have it stored reliably
        $parentId = $parent->get('activitypub_id');
        if (!$parentId) {
            $parentId = $parentUri;
        }

        $replies = Entry::query()
            ->where('collection', 'notes')
            ->get()
            ->filter(function ($entry) use ($parentId) {
                $inReplyTo = $entry->get('in_reply_to');

                if (is_array($inReplyTo)) {
                    return in_array($parentId, $inReplyTo);
                }

                return $inReplyTo === $parentId;
            });

        // 4. Construct Collection
        $items = $replies->map(function ($reply) {
            $json = $reply->get('activitypub_json');
            if ($json)
                return json_decode($json);
            // Fallback generated ID/Link
            // Ideally we should use a Transformer or safe getter
            $actorSlug = \Statamic\Facades\Entry::find($reply->get('actor'))->slug() ?? 'unknown';
            return url('@' . $actorSlug . '/notes/' . $reply->slug());
        })->toArray();

        return response()->json([
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'type' => 'Collection',
            'id' => url()->current(),
            'totalItems' => count($items),
            'items' => $items,
        ])->header('Content-Type', 'application/activity+json');
    }
}
