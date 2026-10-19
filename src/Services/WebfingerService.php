<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebfingerService
{
    /**
     * Resolve a handle (@user@domain) to a full set of URLs and aliases.
     *
     * @param string $handle
     * @return array{actor_url: string|null, aliases: array}
     */
    public function resolve(string $handle): array
    {
        $handle = ltrim($handle, '@');
        $parts = explode('@', $handle);
        if (count($parts) !== 2) {
            return ['actor_url' => null, 'aliases' => []];
        }

        $domain = $parts[1];
        $webfingerUrl = "https://{$domain}/.well-known/webfinger?resource=acct:{$handle}";

        $cacheKey = "ap_webfinger_" . md5(strtolower($handle));

        return \Illuminate\Support\Facades\Cache::remember($cacheKey, 86400, function () use ($handle, $webfingerUrl) {
            try {
                $response = Http::timeout(3)
                    ->withHeaders(['Accept' => 'application/jrd+json, application/json'])
                    ->get($webfingerUrl);

                if (!$response->successful()) {
                    Log::warning("Webfinger lookup failed for {$handle} at {$webfingerUrl} with status: " . $response->status());
                    return ['actor_url' => null, 'aliases' => []];
                }

                $data = $response->json();
                $actorUrl = null;
                $aliases = $data['aliases'] ?? [];

                // Extract Actor URL (rel="self" type="application/activity+json")
                $links = $data['links'] ?? [];
                foreach ($links as $link) {
                    if (($link['rel'] ?? '') === 'self' && ($link['type'] ?? '') === 'application/activity+json') {
                        $actorUrl = $link['href'] ?? null;
                        if ($actorUrl && !in_array($actorUrl, $aliases)) {
                            $aliases[] = $actorUrl;
                        }
                    }
                }

                return [
                    'actor_url' => $actorUrl,
                    'aliases' => $aliases,
                    'data' => $data
                ];

            } catch (\Exception $e) {
                Log::error('Error during Webfinger lookup for ' . $handle . ': ' . $e->getMessage());
                return ['actor_url' => null, 'aliases' => []];
            }
        });
    }
}
