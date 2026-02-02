<?php

namespace Ethernick\ActivityPubCore\Http\Controllers;

use Illuminate\Http\Request;
use Statamic\Facades\Entry;
use Statamic\Http\Controllers\Controller;

class InteractionController extends Controller
{
    public function likes($handle, $uuid)
    {
        return $this->getInteractionCollection($handle, $uuid, 'Like');
    }

    public function shares($handle, $uuid)
    {
        return $this->getInteractionCollection($handle, $uuid, 'Announce');
    }

    protected function getInteractionCollection($handle, $uuid, $type)
    {
        // 1. Find Parent Note/Article
        $parent = Entry::query()
            ->whereIn('collection', ['notes', 'articles']) // Support both
            ->where('slug', $uuid)
            ->first();

        if (!$parent) {
            abort(404, 'Object not found');
        }

        $actor = Entry::query()
            ->where('collection', 'actors')
            ->where('slug', $handle)
            ->first();

        if (!$actor) {
            abort(404, 'Actor not found');
        }

        // 2. Identify Object URI
        // We need to match what was stored in the 'object' field of the activities.
        // This relies on how we stored the object ID when we received the Like/Announce.
        // Usually it's the full URL or the activitypub_id.
        // Our 'activities' collection stores the raw object URI in 'object'.

        $objectUri = url('@' . $actor->slug() . '/notes/' . $parent->slug()); // Default guess

        // If the parent has a specific activitypub_id (e.g. from import), use that?
        // But local items usually use the generated URL as their ID.
        // Let's assume URL for now. We might need to check multiple variations if we are unsure.
        // Note: For 'articles', the URL structure might be different.
        if ($parent->collection()->handle() === 'articles') {
            $objectUri = url('@' . $actor->slug() . '/articles/' . $parent->slug());
            // OR just the public URL: $parent->absoluteUrl()
            // ActivityPubListener generates $this->sanitizeUrl($url) -> with '://' instead of '://www.' sometimes.
        }

        // 3. Query Activities
        $query = Entry::query()
            ->where('collection', 'activities')
            ->where('type', $type);

        // We have to filter by the 'object' field in the data.
        // Statamic flat file driver might be slow with 'where' on data fields if not indexed,
        // but for now it's fine.
        // We need to match precise string.

        // $query->where('object', $objectUri); 
        // Problem: The stored object might vary (http vs https, www vs non-www). 
        // Ideally we canonicalize on save.

        // Let's fetch all of type and filter manually for flexibility if volume is low, 
        // or rely on exact match if we trust our consistency.
        // Let's try exact match on absolute URL first.
        $absoluteUrl = $parent->absoluteUrl();
        $sanitizedUrl = str_replace('://www.', '://', $absoluteUrl);

        $items = $query->get()->filter(function ($activity) use ($absoluteUrl, $sanitizedUrl) {
            $obj = $activity->get('object');
            if (is_array($obj))
                $obj = $obj['id'] ?? null;

            // Match against potential IDs
            return $obj === $absoluteUrl || $obj === $sanitizedUrl;
        });

        // 4. Construct Items List
        // For Likes, we list the Actor ID.
        // For Shares (Announce), we list the Activity ID (the Announce itself) or the Actor?
        // Standard (Mastodon) 'likes' collection contains objects (Like activities) or just actors?
        // Mastodon 'likes' collection items are the *Like Activity* IDs usually, OR the Actors.
        // Checking specific example: "items": ["https://.../users/wolfnowl/statuses/..."] -> These look like Status IDs or Activity IDs?
        // Actually, for 'likes', normally it returns the Like *activities*.

        $collectionItems = $items->map(function ($activity) {
            $json = $activity->get('activitypub_json');
            if ($json) {
                return json_decode($json);
            }
            // Fallback
            return $activity->get('activitypub_id'); // Return the external ID of the Like/Announce
        })->values()->toArray();

        return response()->json([
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'type' => 'OrderedCollection',
            'id' => url()->current(),
            'totalItems' => count($collectionItems),
            'orderedItems' => $collectionItems,
        ])->header('Content-Type', 'application/activity+json');
    }
}
