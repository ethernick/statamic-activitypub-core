<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Statamic\Facades\Entry;

class ActorResolver
{
    /**
     * Resolve an external actor by URL.
     *
     * @param string $actorUrl
     * @param bool $save Whether to save the actor to the database immediately.
     * @return \Statamic\Contracts\Entries\Entry|null
     */
    public function resolve(string $actorUrl, bool $save = true): ?\Statamic\Contracts\Entries\Entry
    {
        // Check if exists locally
        $existing = Entry::query()
            ->where('collection', 'actors')
            ->where('activitypub_id', $actorUrl)
            ->first();

        if ($existing) {
            return $existing;
        }

        // Fetch Remote Actor (with Memoization to prevent burst thrashing)
        $cacheKey = "ap_actor_resolve_" . md5($actorUrl);
        $data = \Illuminate\Support\Facades\Cache::remember($cacheKey, 86400, function () use ($actorUrl) {
            try {
                $response = null;
                try {
                    $response = Http::withHeaders([
                        'Accept' => 'application/activity+json, application/ld+json'
                    ])->get($actorUrl);
                } catch (\Exception $e) {
                    // Fallback for localhost dev envs
                    if (app()->environment('local', 'dev', 'testing') && str_contains($actorUrl, 'localhost') && str_contains($e->getMessage(), 'wrong version number')) {
                        $fallbackUrl = str_replace('https://', 'http://', $actorUrl);
                        Log::info("ActivityPub: Retrying actor resolution with fallback URL: $fallbackUrl");

                        $response = Http::withOptions(['verify' => false])
                            ->withHeaders(['Accept' => 'application/activity+json, application/ld+json'])
                            ->get($fallbackUrl);
                    } else {
                        throw $e;
                    }
                }

                if ($response && $response->status() === 410) {
                    return 'GONE'; // Return a specific string to trigger blocking outside the closure
                }

                if (!$response || !$response->successful()) {
                    return null;
                }

                return $response->json();
            } catch (\Exception $e) {
                Log::error('Failed to fetch remote actor: ' . $e->getMessage());
                return null;
            }
        });

        if ($data === 'GONE') {
            Log::info("ActivityPub: Actor $actorUrl is GONE (410). Automatically blocking.");
            \Ethernick\ActivityPubCore\Services\BlockList::add($actorUrl, 'HTTP 410 Gone');
            return null;
        }

        if (!$data) {
            return null;
        }

        try {
            Log::info("ActivityPub: Resolved external actor data", ['data' => $data]);

            // Check for suspension flags (Self-Healing / Auto-Block)
            if (($data['suspended'] ?? false) === true || ($data['toot:suspended'] ?? false) === true) {
                Log::warning("ActivityPub: Actor $actorUrl is suspended. Automatically blocking.");
                \Ethernick\ActivityPubCore\Services\BlockList::add($actorUrl, 'Suspended in JSON');
                return null;
            }


            // Match against canonical ID from JSON (if redirected or different)
            $canonicalId = $data['id'] ?? $actorUrl;
            if ($canonicalId !== $actorUrl) {
                $existingCanonical = Entry::query()
                    ->where('collection', 'actors')
                    ->where('activitypub_id', $canonicalId)
                    ->first();
                if ($existingCanonical) {
                    return $existingCanonical;
                }
            }

            // Create Actor Entry (Ephemeral or Persisted)
            $username = $data['preferredUsername'] ?? $data['name'] ?? 'unknown';
            $host = parse_url($actorUrl, PHP_URL_HOST);
            $safeHost = str_replace('.', '-dot-', $host);
            $slug = Str::slug($username) . '-at-' . $safeHost;

            $entry = Entry::make()
                ->collection('actors')
                ->slug($slug)
                ->data([
                    'title' => $data['name'] ?? $username,
                    'username' => $username,
                    'content' => $data['summary'] ?? '',
                    'activitypub_id' => $data['id'] ?? $actorUrl,
                    'inbox_url' => $data['inbox'] ?? null,
                    'outbox_url' => $data['outbox'] ?? null,
                    'shared_inbox_url' => $data['endpoints']['sharedInbox'] ?? null,
                    'public_key' => $data['publicKey']['publicKeyPem'] ?? null,
                    'is_internal' => false,
                    'avatar' => $this->downloadAvatar($data['icon'] ?? null),
                ]);

            if ($save) {
                $entry->save();
                // Clear the resolution cache once persisted to DB
                \Illuminate\Support\Facades\Cache::forget($cacheKey);
            }

            return $entry;

        } catch (\Exception $e) {
            Log::error('Failed to process resolved actor: ' . $e->getMessage());
            return null;
        }

    }

    protected function downloadAvatar(mixed $iconData): ?string
    {
        if (!$iconData)
            return null;

        $url = null;
        if (is_array($iconData) && isset($iconData['url'])) {
            $url = $iconData['url'];
        } elseif (is_string($iconData)) {
            $url = $iconData;
        }

        if (!$url)
            return null;

        try {
            // Simplified fetch without redundant checks for now (or copy strictly if needed)
            // Copying strict checks from Controller for robustness
            $response = Http::timeout(10)->get($url);
            if (!$response->successful())
                return null;

            $contentType = $response->header('Content-Type');
            if (!str_starts_with($contentType, 'image/'))
                return null;

            $contents = $response->body();
            if (strlen($contents) < 50)
                return null;

            $name = md5($url);
            $extension = 'jpg';
            $mimeMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
            if (isset($mimeMap[$contentType])) {
                $extension = $mimeMap[$contentType];
            } else {
                $ext = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
                if ($ext)
                    $extension = $ext;
            }
            $extension = substr(preg_replace('/[^a-z0-9]/', '', strtolower($extension)), 0, 4) ?: 'jpg';

            $filename = "avatars/{$name}.{$extension}";
            Storage::disk('assets')->put($filename, $contents);
            return $filename;

        } catch (\Exception $e) {
            return null;
        }
    }
}
