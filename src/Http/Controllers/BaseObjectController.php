<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Http\Controllers;

use Statamic\Http\Controllers\Controller;
use Statamic\Facades\Entry;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

abstract class BaseObjectController extends BaseController
{
    // Configuration properties (optional, allows manual override)
    protected string $indexTemplate = '';
    protected string $showTemplate = '';
    protected string $collectionSlug = '';

    // Must be defined in child if not implementing getHandledActivityTypes manually
    protected static array $handledActivityTypes = [];

    // --- Smart Inference Methods ---

    public static function getHandledActivityTypes(): array
    {
        return static::$handledActivityTypes;
    }

    protected function getCollectionSlug(): ?string
    {
        if ($this->collectionSlug) {
            return $this->collectionSlug;
        }

        // Infer slug from first handled activity type
        // e.g. 'Create:Object' -> 'Object' -> 'objects'
        $types = static::getHandledActivityTypes();
        $firstType = $types[0] ?? null;

        if ($firstType) {
            $parts = explode(':', $firstType);
            $objectType = end($parts); // 'Object'
            return Str::plural(Str::lower($objectType)); // 'objects'
        }

        return 'objects'; // Fallback
    }

    protected function getIndexTemplate(): string
    {
        if ($this->indexTemplate) {
            return $this->indexTemplate;
        }

        // Infer from slug: activitypub::notes
        $slug = $this->getCollectionSlug();
        return "activitypub::{$slug}";
    }

    protected function getShowTemplate(): string
    {
        if ($this->showTemplate) {
            return $this->showTemplate;
        }

        // Infer from singular slug: activitypub::note
        $slug = $this->getCollectionSlug();
        $singular = Str::singular($slug ?? 'objects');
        return "activitypub::{$singular}";
    }

    // --- Logic ---

    protected function findLocalEntryByUrl(string $url): ?\Statamic\Contracts\Entries\Entry
    {
        $entry = Entry::find($url);
        if (!$entry) {
            $entry = Entry::query()->whereIn('collection', ['notes', 'polls'])->where('activitypub_id', $url)->first();
        }
        if (!$entry) {
            $baseUrl = \Statamic\Facades\Site::selected()->absoluteUrl();
            if (Str::startsWith($url, $baseUrl)) {
                $uri = str_replace($baseUrl, '', $url);
                $uri = '/' . ltrim($uri, '/');
                $entry = Entry::findByUri($uri, \Statamic\Facades\Site::selected()->handle());
            }
        }
        return $entry;
    }

    // --- Views ---

    protected function returnIndexView(\Statamic\Contracts\Entries\Entry $actor)
    {
        return (new \Statamic\View\View)
            ->template($this->getIndexTemplate())
            ->layout('layout')
            ->with([
                'actor' => $actor,
                'title' => $actor->get('title') . ' - Activities'
            ]);
    }

    protected function returnShowView(\Statamic\Contracts\Entries\Entry $actor, \Statamic\Contracts\Entries\Entry $item)
    {
        return (new \Statamic\View\View)
            ->template($this->getShowTemplate())
            ->layout('layout')
            ->with([
                'actor' => $actor,
                'note' => $item,
                'title' => $item->get('title') ?? 'Object'
            ]);
    }

    // --- Default Handlers (Generic) ---

    public function handleCreate(array $payload, mixed $localActor, mixed $externalActor): bool
    {
        Log::info(basename(static::class) . ": Handling Create via BaseObjectController");
        $object = $payload['object'] ?? null;
        if (!$object)
            return false;

        return $this->processObject($object, $externalActor);
    }

    public function handleUpdate(array $payload, mixed $localActor, mixed $externalActor): bool
    {
        Log::info(basename(static::class) . ": Handling Update via BaseObjectController");
        $object = $payload['object'] ?? null;
        if (!$object)
            return false;

        $id = $object['id'] ?? null;
        if (!$id)
            return false;

        $existing = Entry::query()->where('collection', $this->getCollectionSlug())->where('activitypub_id', $id)->first();
        if ($existing) {
            return $this->processObject($object, $externalActor, $existing);
        }

        Log::info(basename(static::class) . ": Object to update not found: $id");
        return false;
    }

    public function handleDelete(array $payload, mixed $localActor, mixed $externalActor): bool
    {
        Log::info(basename(static::class) . ": Handling Delete via BaseObjectController");
        $object = $payload['object'] ?? null;
        $objectId = is_string($object) ? $object : ($object['id'] ?? null);

        if (!$objectId)
            return false;

        $existing = Entry::query()->where('collection', $this->getCollectionSlug())->where('activitypub_id', $objectId)->first();
        if ($existing) {
            $existing->delete();
            return true;
        }
        return false;
    }

    protected function processObject(mixed $object, mixed $authorActor, mixed $existingEntry = null): bool
    {
        $id = $object['id'] ?? null;
        if (!$id && !$existingEntry)
            return false;

        if (!$existingEntry) {
            $existingEntry = Entry::query()->where('collection', $this->getCollectionSlug())->where('activitypub_id', $id)->first();
        }

        $name = $object['name'] ?? null;
        $summary = $object['summary'] ?? null;
        $content = $object['content'] ?? null;

        $title = $id;
        if ($name) {
            $title = $name;
        } elseif ($summary) {
            $title = $summary;
        }

        $finalContent = '';

        if ($content && $summary && $name) {
            $finalContent = "<p>{$summary}</p>{$content}";
        } elseif ($content) {
            $finalContent = $content;
        } elseif ($summary && $name) {
            $finalContent = $summary;
        }

        if ($existingEntry) {
            $entry = $existingEntry;
        } else {
            $uuid = (string) Str::uuid();
            $entry = Entry::make()
                ->collection($this->getCollectionSlug())
                ->id($uuid)
                ->slug($uuid);
        }

        $dateStr = $object['published'] ?? $object['updated'] ?? null;
        $date = $dateStr ? \Illuminate\Support\Carbon::parse($dateStr) : now();

        if ($entry->collection()->dated()) {
            $entry->date($date);
        }
        $entry->set('title', $title);
        $entry->set('content', $finalContent);
        $entry->set('actor', $authorActor->id());
        $entry->set('activitypub_id', $id);
        $entry->set('activitypub_json', json_encode($object));

        // Default is_internal to false for incoming objects
        $entry->set('is_internal', false);

        $entry->save();

        return true;
    }
}
