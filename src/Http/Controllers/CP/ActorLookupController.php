<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Http\Controllers\CP;

use Statamic\Http\Controllers\CP\CpController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Ethernick\ActivityPubCore\Services\ActorResolver;
use Illuminate\Http\JsonResponse;

class ActorLookupController extends CpController
{
    public function index()
    {
        return view('activitypub::cp.tools.actor_lookup', [
            'title' => 'Actor Lookup',
        ]);
    }

    public function lookup(Request $request, ActorResolver $resolver): JsonResponse
    {
        $handle = $request->input('handle');
        $type = $request->input('type', 'actor');

        if (!$handle) {
            return response()->json(['message' => 'Handle or URL is required.'], 422);
        }

        // 1. Determine if it's a URL or a handle
        $isUrl = filter_var($handle, FILTER_VALIDATE_URL) !== false;
        $actorUrl = $handle;

        if (!$isUrl) {
            // It's a handle (e.g. @user@domain or user@domain)
            $handle = ltrim($handle, '@');
            $parts = explode('@', $handle);
            if (count($parts) !== 2) {
                return response()->json(['message' => 'Invalid handle format. Use @user@domain.'], 422);
            }

            $domain = $parts[1];
            $webfingerUrl = "https://{$domain}/.well-known/webfinger?resource=acct:{$handle}";

            try {
                $response = Http::withHeaders(['Accept' => 'application/jrd+json, application/json'])
                    ->get($webfingerUrl);

                if (!$response->successful()) {
                    return response()->json(['message' => "Webfinger lookup failed: {$response->status()}"], 400);
                }

                $data = $response->json();

                if ($type === 'webfinger') {
                    return response()->json($data);
                }

                // Extract Actor URL from Webfinger
                $links = $data['links'] ?? [];
                foreach ($links as $link) {
                    if (($link['rel'] ?? '') === 'self' && ($link['type'] ?? '') === 'application/activity+json') {
                        $actorUrl = $link['href'];
                        break;
                    }
                }

                if ($actorUrl === $handle) {
                    return response()->json(['message' => 'Could not find ActivityPub Actor URL in Webfinger response.'], 400);
                }

            } catch (\Exception $e) {
                return response()->json(['message' => 'Error during Webfinger lookup: ' . $e->getMessage()], 500);
            }
        }

        // 2. Fetch Actor
        try {
            $actor = $resolver->resolve($actorUrl, false);
            if (!$actor) {
                return response()->json(['message' => 'Failed to resolve actor profile.'], 400);
            }

            return response()->json($actor->values()->all());
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error during Actor fetch: ' . $e->getMessage()], 500);
        }
    }
}
