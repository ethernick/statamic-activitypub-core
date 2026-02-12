<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Http\Controllers;

use Illuminate\Http\Request;
use Statamic\Facades\Entry;
use Statamic\Http\Controllers\Controller;

class WebFingerController extends Controller
{
    public function index(Request $request)
    {
        \Illuminate\Support\Facades\Log::info("WebFinger: Accessed from " . $request->ip() . " for " . $request->input('resource'));
        $resource = $request->input('resource');

        if (!$resource || !str_starts_with($resource, 'acct:')) {
            return response()->json([
                'error' => 'Bad Request',
                'message' => "Missing or invalid 'resource' parameter. Must start with 'acct:'.",
            ], 400);
        }

        // Extract handle from acct:handle@domain
        $parts = explode(':', $resource);
        $acct = $parts[1] ?? '';
        $acctParts = explode('@', $acct);
        $handle = $acctParts[0] ?? '';

        if (!$handle) {
            return $this->returnNotFound();
        }

        // Find Actor
        $actor = Entry::query()
            ->where('collection', 'actors')
            ->where('slug', $handle)
            ->where('is_internal', true)
            ->first();

        if (!$actor) {
            // Try finding by 'handle' field if slug doesn't match
            $actor = Entry::query()
                ->where('collection', 'actors')
                ->where('handle', $handle)
                ->where('is_internal', true)
                ->first();
        }

        if (!$actor) {
            return $this->returnNotFound();
        }

        // Construct Response
        $actorUrl = $this->sanitizeUrl(url('@' . $actor->slug()));
        $actorId = $this->sanitizeUrl(url($actor->slug())); // ActivityPub ID usually matches the profile URL or a specific ID URL

        return response()->json([
            'subject' => $resource,
            'aliases' => [
                $actorUrl,
            ],
            'links' => [
                [
                    'rel' => 'self',
                    'type' => 'application/activity+json',
                    'href' => $actorUrl,
                ],
                [
                    'rel' => 'http://webfinger.net/rel/profile-page',
                    'type' => 'text/html',
                    'href' => $actorUrl,
                ],
                // Add interaction links if needed later
            ],
        ])->header('Content-Type', 'application/jrd+json');
    }

    protected function returnNotFound()
    {
        return response()->json([
            'type' => 'about:blank',
            'title' => 'activitypub_user_not_found',
            'detail' => 'Actor not found',
            'status' => 404,
            'metadata' => [
                'code' => 'activitypub_user_not_found',
                'message' => 'Actor not found',
                'data' => ['status' => 404]
            ]
        ], 404);
    }
    protected function sanitizeUrl(string $url): string
    {
        return str_replace('://www.', '://', $url);
    }
}
