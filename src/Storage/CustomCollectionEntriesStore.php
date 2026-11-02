<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Storage;

use Statamic\Stache\Stores\CollectionEntriesStore;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Custom Collection Entries Store for Statamic Stache.
 *
 * WHY THIS CLASS EXISTS & WHY THE NAME MATTERS:
 * -------------------------------------------------------------------------------------------------
 * Statamic's content engine (the "Stache") parses flat YAML files into memory models.
 * In `ActivityPubServiceProvider`, we bind:
 *     `\Statamic\Stache\Stores\CollectionEntriesStore::class => CustomCollectionEntriesStore::class`
 *
 * This binding is critical to ActivityPubCore for two essential architectural reasons:
 *
 * 1. SIDE-CAR FILE ISOLATION (`getItemFilter`):
 *    ActivityPub uses companion `.json` sidecar files stored adjacent to `.md` / `.yaml` entries.
 *    Without this store's filter, Stache's Traverser attempts to parse sidecar JSON files as Statamic
 *    content entries, corrupting the collection index or throwing YAML parse exceptions.
 *
 * 2. SEARCH & QUERY ACCELERATION VIA INDEX INHERITANCE (`storeIndexes`):
 *    In Statamic flat-file architecture, querying fields with `where()` or `whereJsonOverlaps()`
 *    defaults to reading and parsing flat files unless the field is registered in the Stache's
 *    index list. Overriding `storeIndexes()` here ensures that every ActivityPub collection
 *    (`notes`, `activities`, `polls`, `articles`, etc.) automatically inherits pre-warmed,
 *    in-memory indexes for `type`, `actor`, `tags`, `mentioned_urls`, etc.
 *    This allows the Inbox search queries to execute in sub-millisecond O(1) hash lookups
 *    without requiring end users to manually configure `config/statamic/stache.php`.
 */
class CustomCollectionEntriesStore extends CollectionEntriesStore
{
    /**
     * Filter files inspected by the Stache traverser.
     *
     * Ensures companion ActivityPub `.json` files are ignored by Statamic's entry loader,
     * and guarantees that the parent collection exists before instantiating Entry models.
     */
    public function getItemFilter(SplFileInfo $file)
    {
        // Exclude sidecar JSON files from the Stache to prevent them from being parsed as entries
        if ($file->getExtension() === 'json') {
            return false;
        }

        // Verify the collection exists before letting Stache instantiate an Entry
        [$collection] = $this->extractAttributesFromPath($file->getPathname());
        if (! \Statamic\Facades\Collection::findByHandle($collection)) {
            return false;
        }

        return parent::getItemFilter($file);
    }

    /**
     * Define default indexes for collection entries managed by this store.
     *
     * SEARCH OPTIMIZATION MECHANISM:
     * When an index is defined here, Statamic computes and serializes a key-value lookup map
     * during `php artisan statamic:stache:warm` or upon first query.
     *
     * For example, indexing `actor` and `tags` allows `Entry::query()->whereJsonOverlaps(...)`
     * to resolve entry keys directly from the cached index array in memory without opening,
     * reading, or parsing a single flat markdown/YAML file from disk.
     *
     * Fields indexed:
     * - `type`: ActivityStreams type (e.g. Note, Announce, Question)
     * - `actor`: Actor relation UUIDs
     * - `user` & `author`: Associated user accounts
     * - `tags`: Hashtags / taxonomy array
     * - `activitypub_id`: Remote ActivityPub URI
     * - `in_reply_to`: Thread parent identifier
     * - `is_internal`: Local vs federated origin flag
     * - `mentioned_urls`: Array of mention URIs extracted at save time
     * - `search_tokens`: Pre-tokenized keywords for O(1) keyword index matching
     */
    protected function storeIndexes()
    {
        return array_merge(parent::storeIndexes(), [
            'type',
            'actor',
            'user',
            'author',
            'tags',
            'activitypub_id',
            'in_reply_to',
            'is_internal',
            'mentioned_urls',
            'search_tokens',
        ]);
    }
}
