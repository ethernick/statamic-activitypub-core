<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Services;

use Statamic\Facades\Entry;
use Statamic\Facades\File;
use Statamic\Facades\YAML;
use Statamic\Facades\Site;
use Illuminate\Support\Str;

class ActivityPubUtils
{
    /**
     * Get the path to the ActivityPub settings file.
     */
    public static function settingsPath(): string
    {
        return config('activitypub.settings_path', resource_path('settings/activitypub.yaml'));
    }

    public static function blueprintPath(string $path): string
    {
        return config('activitypub.blueprints_path', resource_path('blueprints')) . '/' . ltrim($path, '/');
    }

    /**
     * Get the path to a collection file, allowing for sandbox overrides.
     */
    public static function collectionPath(string $handle): string
    {
        return config('activitypub.collections_path', base_path('content/collections')) . '/' . ltrim($handle, '/') . '.yaml';
    }

    public static function isFederated(string $handle): bool
    {
        $path = self::settingsPath();
        if (!File::exists($path)) {
            return false;
        }
        $settings = YAML::parse(File::get($path));
        $config = $settings[$handle] ?? [];

        if (is_array($config)) {
            return $config['federated'] ?? false;
        }

        return false;
    }

    public static function findLocalEntryByUrl(string $url): mixed
    {
        $entry = Entry::find($url);
        if (!$entry) {
            $entry = Entry::query()->whereIn('collection', ['notes', 'polls', 'articles'])->where('activitypub_id', $url)->first();
        }
        if (!$entry) {
            // Check absolute URL match
            $baseUrl = Site::selected()->absoluteUrl();
            if (Str::startsWith($url, $baseUrl)) {
                $uri = str_replace($baseUrl, '', $url);
                $uri = '/' . ltrim($uri, '/');
                $entry = Entry::findByUri($uri, Site::selected()->handle());
            }
        }
        return $entry;
    }

    /**
     * Get or build the cached actor fast-lookup map.
     * @deprecated Use \Ethernick\ActivityPubCore\Search\ActorSearchIndex::getLookupMap()
     */
    public static function getCachedActorMap(): array
    {
        return \Ethernick\ActivityPubCore\Search\ActorSearchIndex::getLookupMap();
    }

    /**
     * Resolve matching actor IDs for a search string using the fast lookup map.
     * @deprecated Use \Ethernick\ActivityPubCore\Search\ActorSearchIndex::findActorIds()
     */
    public static function findActorIdsByQuery(string $search): array
    {
        return \Ethernick\ActivityPubCore\Search\ActorSearchIndex::findActorIds($search);
    }

    /**
     * Invalidate the actor lookup map cache.
     * @deprecated Use \Ethernick\ActivityPubCore\Search\ActorSearchIndex::clear()
     */
    public static function clearActorMapCache(): void
    {
        \Ethernick\ActivityPubCore\Search\ActorSearchIndex::clear();
    }

    /**
     * Transform an actor entry for use in CP UI stacks (Inbox, Check-in, etc.)
     */
    public static function transformActorForCp(mixed $actorEntry): array
    {
        $name = $actorEntry->get('title');
        $slug = $actorEntry->slug();
        $handle = $slug;

        if ($actorEntry->get('is_internal')) {
            $handle = $slug . '@' . request()->getHost();
        } else {
            $handle = str_replace(['-at-', '-dot-'], ['@', '.'], $slug);
        }

        $avatarId = $actorEntry->get('avatar');
        $avatar = null;
        if ($avatarId) {
            if (is_string($avatarId) && str_starts_with($avatarId, 'http')) {
                $avatar = $avatarId;
            } else {
                $asset = \Statamic\Facades\Asset::find($avatarId);
                if (!$asset && is_string($avatarId) && !str_contains($avatarId, '::')) {
                    $asset = \Statamic\Facades\Asset::find('assets::' . $avatarId);
                }
                if ($asset) {
                    $avatar = $asset->url();
                }
            }
        }

        return [
            'id' => $actorEntry->id(),
            'name' => $name,
            'handle' => str_starts_with($handle, '@') ? $handle : '@' . $handle,
            'actor_type' => (string) ($actorEntry->get('actor_type') ?: 'Person'),
            'avatar' => $avatar ?? 'https://www.gravatar.com/avatar/' . md5($handle) . '?d=mp',
            'url' => $actorEntry->get('url') ?? $actorEntry->absoluteUrl(),
        ];
    }

    /**
     * Transform any entry (Note, Event, Activity, Article, Question) for display in ActivityPub feeds.
     */
    public static function transformEntryForFeed(mixed $entry): array
    {
        $collection = $entry->collection() ? $entry->collection()->handle() : 'notes';

        // 1. Resolve Actor
        $actorId = $entry->get('actor');
        if (is_array($actorId)) {
            $actorId = $actorId[0] ?? null;
        }
        $actorEntry = $actorId ? Entry::find($actorId) : null;
        $actor = $actorEntry ? self::transformActorForCp($actorEntry) : null;

        // 2. Resolve Content & Markdown
        $rawContent = (string)($entry->get('content') ?? '');
        $sidecarPayload = null;
        if (class_exists(\Ethernick\ActivityPubCore\Storage\ActivityPubPayload::class)) {
            $rawJson = \Ethernick\ActivityPubCore\Storage\ActivityPubPayload::for($entry)->raw();
            if ($rawJson) {
                $sidecarPayload = json_decode($rawJson, true);
            }
        }

        if (!$rawContent && is_array($sidecarPayload) && isset($sidecarPayload['object']) && is_array($sidecarPayload['object']) && isset($sidecarPayload['object']['content'])) {
            $rawContent = (string)$sidecarPayload['object']['content'];
        }

        $parsedContent = $rawContent ? (string)\Statamic\Facades\Markdown::parse($rawContent) : '';

        // 3. Date & Human Time
        $rawDate = (is_array($sidecarPayload) && !empty($sidecarPayload['updated'])) ? $sidecarPayload['updated'] : null;
        if (!$rawDate) {
            $rawDate = $entry->get('date') ?? $entry->get('start_time');
        }
        if (!$rawDate && is_array($sidecarPayload) && !empty($sidecarPayload['published'])) {
            $rawDate = $sidecarPayload['published'];
        }
        $date = $rawDate ?? ($entry->date() ? $entry->date()->toIso8601String() : null);
        $dateCarbon = $date ? \Carbon\Carbon::parse($date) : null;

        // 4. Determine Type, Title, and Major status
        $isActivity = ($collection === 'activities');
        $activityType = $isActivity ? ($entry->get('type') ?? ($entry->get('activitypub_type') ?? 'Activity')) : null;
        $isMajor = true;
        if ($isActivity) {
            $isMajor = in_array($activityType, ['Arrive', 'Travel', 'Offer', 'Listen', 'Question', 'Create', 'Event']);
        }

        // Title: Suppressed for notes (micro-posts)
        $title = ($collection === 'notes') ? null : $entry->get('title');

        // 5. Resolve Object Summary & Type for Activities
        $objectType = null;
        $objectSummary = null;
        if ($isActivity) {
            $object = $entry->get('object');
            $objectType = is_array($object) ? ($object['type'] ?? 'Object') : 'Object';
            $objectSummary = is_array($object) ? ($object['name'] ?? $object['summary'] ?? null) : null;

            if (is_array($object) && !isset($object['type']) && isset($object[0])) {
                $object = $object[0];
            }

            if (is_string($object)) {
                $localEntry = Entry::find($object);
                if ($localEntry) {
                    $handle = $localEntry->collection()->handle();
                    $objectType = ($handle === 'notes') ? 'Note' : (($handle === 'places') ? 'Place' : ucfirst(Str::singular($handle)));
                    $objectSummary = $localEntry->get('title') ?? $localEntry->get('name');

                    if (!$objectSummary || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string)$objectSummary)) {
                        $entryContent = $localEntry->get('content');
                        if ($entryContent) {
                            $objectSummary = Str::limit(strip_tags($entryContent), 100);
                        }
                    }
                }
            }

            $isUuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string)$objectSummary);
            if ($sidecarPayload && ($objectType === 'Object' || $isUuid)) {
                $nestedObj = is_array($sidecarPayload) ? ($sidecarPayload['object'] ?? null) : null;
                if (is_array($nestedObj) && isset($nestedObj['type'])) {
                    $objectType = $nestedObj['type'];
                    if (!$objectSummary || $isUuid) {
                        $newSummary = $nestedObj['name'] ?? $nestedObj['summary'] ?? null;
                        if (!$newSummary && in_array($nestedObj['type'], ['Question', 'Note', 'Place', 'Event'])) {
                            $summaryText = strip_tags($nestedObj['content'] ?? '');
                            $newSummary = Str::limit($summaryText, 100);
                        }
                        if ($newSummary) {
                            $objectSummary = $newSummary;
                        }
                    }
                }
            }
        }

        $item = [
            'id' => $entry->id(),
            'collection' => $collection,
            'type' => $isActivity ? 'activity' : ($collection === 'notes' ? 'note' : Str::singular($collection)),
            'activity_type' => $activityType,
            'is_major' => $isMajor,
            'title' => $title,
            'content' => $parsedContent,
            'content_raw' => strip_tags($rawContent),
            'date' => $date,
            'date_human' => $dateCarbon ? $dateCarbon->diffForHumans() : null,
            'actor' => $actor,
            'actor_name' => $actor['name'] ?? ($entry->get('actor_name') ?? 'ActivityPub User'),
            'attachments' => $entry->get('attachments', []),
            'tags' => $entry->get('tags', []),
            'object_type' => $objectType,
            'object_summary' => $objectSummary,
        ];

        // Execute any addon transform hooks (e.g. Events adding start_time/attendees)
        ActivityPubTypes::executeTransformHooks($entry, $item);

        return $item;
    }
}
