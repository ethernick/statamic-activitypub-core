<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Middleware;

use Closure;
use Statamic\Facades\Entry;
use Statamic\Facades\URL;

class NegotiateActivityPubResponse
{
    public function handle($request, Closure $next)
    {
        $response = $next($request);

        \Illuminate\Support\Facades\Log::info("NegotiateActivityPubResponse: Incoming request", [
            'url' => $request->url(),
            'accept' => $request->header('Accept'),
            'wantsJson' => $request->wantsJson(),
            'original_type' => is_object($response->original) ? get_class($response->original) : gettype($response->original),
        ]);

        $acceptHeader = $request->header('Accept') ?? '';
        if (!$request->wantsJson() && !str_contains($acceptHeader, 'application/ld+json') && !str_contains($acceptHeader, 'application/activity+json')) {
            return $response;
        }

        $content = $response->original;

        if ($content instanceof \Illuminate\View\View || (is_object($content) && method_exists($content, 'getData'))) {
            $data = $content->getData();
            \Illuminate\Support\Facades\Log::info("NegotiateActivityPubResponse: View Data Keys", array_keys($data));
            if (isset($data['activitypub_json'])) {
                \Illuminate\Support\Facades\Log::info("NegotiateActivityPubResponse: activitypub_json found");
            } else {
                \Illuminate\Support\Facades\Log::info("NegotiateActivityPubResponse: activitypub_json NOT found");
            }

            // Handle Actor Profile
            if ($content->name() === 'activitypub::actor' && isset($data['actor'])) {
                $transformer = new \Ethernick\ActivityPubCore\Transformers\ActorTransformer();
                $json = $transformer->transform($data['actor']);

                return response()->json($json)
                    ->header('Content-Type', 'application/activity+json');
            }

            // Handle Generic Entries (Notes, Articles, Activities)
            // Statamic's default view often passes 'entry' or matches the collection
            if (isset($data['id'])) { // Generic check if there's data
                // Typically Statamic injects the entry data.
                // Let's check for 'activitypub_json' directly in the data
                if (isset($data['activitypub_json']) && !empty($data['activitypub_json'])) {
                    $json = $data['activitypub_json'];
                    if (is_string($json)) {
                        $json = json_decode($json, true);
                    }
                    return response()->json($json)
                        ->header('Content-Type', 'application/activity+json');
                }
            }
        }

        // Fallback: If content is a string (HTML) or Entry object doesn't have data attached yet
        // Try to find the entry by the request URI
        if ($content instanceof \Statamic\Entries\Entry) {
            $entry = $content;
        } else {
            // Dynamic Lookup by URL
            $url = $request->url(); // Full URL
            // Statamic expects relative URI usually or full URL? findByUri handles URI
            $uri = $request->getRequestUri();
            $entry = \Statamic\Facades\Entry::findByUri($uri);
        }

        if ($entry && $entry instanceof \Statamic\Entries\Entry) {
            $json = $entry->get('activitypub_json');
            if ($json) {
                if (is_string($json)) {
                    $json = json_decode($json, true);
                }
                return response()->json($json)
                    ->header('Content-Type', 'application/activity+json');
            }
        }

        // Original Entry check (redundant but kept for safety if above block didn't cover generic Entry return)
        if ($content instanceof \Statamic\Entries\Entry) {
            // Sometimes response might be the Entry object directly if returned by a controller?
            $json = $content->get('activitypub_json');
            if ($json) {
                if (is_string($json)) {
                    $json = json_decode($json, true);
                }
                return response()->json($json)
                    ->header('Content-Type', 'application/activity+json');
            }
        }

        return $response;
    }
}
