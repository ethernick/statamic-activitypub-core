<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Search;

use Statamic\Facades\Entry;
use Illuminate\Support\Facades\Cache;
use Ethernick\ActivityPubCore\Services\ActivityPubUtils;

class ActorSearchIndex
{
    protected const CACHE_KEY = 'ap:actors:lookup_map';
    protected const TTL_SECONDS = 3600;

    /**
     * Get or build the cached actor fast-lookup map.
     * Maps handle, slug, username, title, and apId to actor Entry UUIDs.
     * Cached in memory/cache store, invalidated when actor entries change.
     */
    public static function getLookupMap(): array
    {
        return Cache::remember(self::CACHE_KEY, self::TTL_SECONDS, function () {
            $actors = Entry::query()->where('collection', 'actors')->get();

            $siteHost = null;
            try {
                $siteUrl = \Statamic\Facades\Site::selected()->absoluteUrl();
                $siteHost = strtolower((string) parse_url($siteUrl, PHP_URL_HOST));
            } catch (\Throwable $e) {
                $siteHost = request()->getHost();
            }

            $map = [];

            foreach ($actors as $actor) {
                $id = $actor->id();
                $slug = strtolower($actor->slug());
                $isInternal = (bool) $actor->get('is_internal');
                $apId = strtolower((string) ($actor->get('activitypub_id') ?? ''));
                $rawHandle = (string) ($actor->get('handle') ?? '');
                $handle = strtolower(ltrim($rawHandle, '@'));

                $handles = [
                    $id,
                    $slug,
                ];

                // Extract username and name/title
                $username = strtolower((string) ($actor->get('username') ?? ''));
                $title = strtolower((string) ($actor->get('title') ?? ''));
                if ($username !== '') {
                    $handles[] = $username;
                }
                if ($title !== '') {
                    $handles[] = $title;
                }

                // 1. Explicit handle stored on actor (e.g. username@domain.com)
                if ($handle) {
                    $handles[] = $handle;
                    $handles[] = explode('@', $handle)[0]; // Just username part
                }

                // 2. Synthesize ActivityPub handle based on internal or external origin
                if ($isInternal) {
                    if ($siteHost) {
                        $handles[] = "{$slug}@{$siteHost}";
                        if ($username !== '') {
                            $handles[] = "{$username}@{$siteHost}";
                        }
                    }
                } else {
                    // External actor slug is often username-at-domain-dot-com
                    $derived = str_replace(['-at-', '-dot-'], ['@', '.'], $slug);
                    $handles[] = $derived;
                    $handles[] = explode('@', $derived)[0];

                    // Also synthesize from username + apId host if available
                    if ($username !== '' && $apId !== '') {
                        $apHost = parse_url($apId, PHP_URL_HOST);
                        if ($apHost) {
                            $handles[] = "{$username}@{$apHost}";
                        }
                    }
                }

                // 3. From ActivityPub URI (username from path, host from URI)
                if ($apId) {
                    $uriHost = parse_url($apId, PHP_URL_HOST);
                    $uriPath = ltrim((string) parse_url($apId, PHP_URL_PATH), '/');
                    $uriUsername = basename($uriPath);
                    if ($uriHost && $uriUsername) {
                        $handles[] = "{$uriUsername}@{$uriHost}";
                        $handles[] = $uriUsername;
                    }
                }

                $handles = array_filter(array_unique($handles));

                foreach ($handles as $key) {
                    $strKey = (string) $key;
                    if ($strKey === '') {
                        continue;
                    }
                    if (!isset($map[$strKey])) {
                        $map[$strKey] = [];
                    }
                    $map[$strKey][] = $id;
                }
            }

            return $map;
        });
    }

    /**
     * Resolve matching actor IDs for a search string using the fast lookup map.
     */
    public static function findActorIds(string $search): array
    {
        $raw = strtolower(trim(ltrim($search, '@')));
        if ($raw === '') {
            return [];
        }

        $map = self::getLookupMap();
        $matched = [];

        // Exact match (instant array hash lookup)
        if (isset($map[$raw])) {
            $matched = array_merge($matched, $map[$raw]);
        }

        // Substring match across the cached dictionary keys (in-memory, microseconds)
        // Guard against single-character noise
        if (mb_strlen($raw) >= 2) {
            foreach ($map as $key => $ids) {
                if (str_contains((string) $key, $raw)) {
                    $matched = array_merge($matched, $ids);
                }
            }
        }

        return array_values(array_unique($matched));
    }

    /**
     * Invalidate the actor lookup map cache.
     */
    public static function clear(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
