<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Http\Controllers;

use Statamic\Http\Controllers\Controller;
use Statamic\Facades\Entry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

abstract class BaseController extends Controller
{
    /**
     * Find the actor by handle.
     *
     * @param string $handle
     * @return \Statamic\Contracts\Entries\Entry|null
     */
    protected function findActor(string $handle): ?\Statamic\Contracts\Entries\Entry
    {
        // Try to find the actor in the 'actors' collection
        $actor = Entry::query()
            ->where('collection', 'actors')
            ->where('slug', $handle)
            ->where('is_internal', true)
            ->first();

        if (!$actor) {
            // Fallback: Check Statamic Users
            // Match against activitypub_handle field or username
            $actor = \Statamic\Facades\User::query()
                ->where('activitypub_handle', $handle)
                ->first();

            if (!$actor) {
                // Try username
                $actor = \Statamic\Facades\User::query()
                    ->where('username', $handle)
                    ->first();
            }
        }



        // If not found, check if we need to search differently or retry
        if (!$actor) {
            // Redundant check in original code, maybe stale logic, simplified here.
        }

        /** @var \Statamic\Contracts\Entries\Entry|null $actor */
        return $actor;
    }

    /**
     * Standard Index Action (List/Collection)
     */
    public function index(string $handle)
    {
        $actor = $this->findActor($handle);
        if (!$actor) {
            abort(404, 'Actor not found');
        }

        // Handle JSON Negotiation
        if ($this->wantsJson()) {
            return $this->returnCollectionJson($actor);
        }

        // Return View
        return $this->returnIndexView($actor);
    }

    /**
     * Standard Show Action (Single Item)
     */
    public function show(string $handle, string $uuid)
    {
        $actor = $this->findActor($handle);
        if (!$actor) {
            abort(404, 'Actor not found');
        }

        $item = $this->findItem($uuid);
        if (!$item) {
            abort(404, 'Item not found');
        }

        if ($this->wantsJson()) {
            return $this->returnItemJson($item);
        }

        return $this->returnShowView($actor, $item);
    }

    /**
     * Helper to check for JSON request.
     */
    protected function wantsJson(): bool
    {
        return request()->wantsJson()
            || str_contains(request()->header('Accept') ?? '', 'application/ld+json')
            || str_contains(request()->header('Accept') ?? '', 'application/activity+json');
    }

    /**
     * Find the specific item by UUID.
     * Subclasses can override if query logic differs.
     */
    protected function findItem(string $uuid): ?\Statamic\Contracts\Entries\Entry
    {
        return Entry::find($uuid);
    }

    /**
     * Return JSON for the collection.
     * Subclasses MUST implement logic to fetch items or override entirely.
     */
    protected function returnCollectionJson(\Statamic\Contracts\Entries\Entry $actor)
    {
        // Default implementation: Generic ordered collection of this type?
        // We need the collection slug/handle associated with this controller.
        // It's hard to guess generic logic without config. 
        // For now, abstract or empty?
        // Let's implement a basic query based on the 'slug' passed in routes or defined in class.

        $slug = $this->getCollectionSlug();
        if (!$slug) {
            return response()->json(['error' => 'Collection not defined'], 500);
        }

        $query = Entry::query()->where('collection', $slug);

        // Filter by actor?
        // Usually, yes.
        $items = $query->get()->filter(function ($entry) use ($actor) {
            $entryActor = $entry->get('actor');
            if (is_array($entryActor))
                return in_array($actor->id(), $entryActor);
            return $entryActor === $actor->id();
        });

        // Pagination
        $page = (int) request()->get('page', 1);
        $perPage = 20;

        $paginated = new \Illuminate\Pagination\LengthAwarePaginator(
            $items->forPage($page, $perPage),
            $items->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        $jsonItems = [];
        foreach ($paginated as $entry) {
            $json = $entry->get('activitypub_json');
            if ($json)
                $jsonItems[] = json_decode($json);
        }

        return response()->json([
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'type' => 'OrderedCollection',
            'id' => request()->url(),
            'totalItems' => $paginated->total(),
            'orderedItems' => $jsonItems,
        ])->header('Content-Type', 'application/ld+json');
    }

    protected function returnItemJson(\Statamic\Contracts\Entries\Entry $item)
    {
        $json = $item->get('activitypub_json');
        if (is_string($json)) {
            $json = json_decode($json, true);
        }
        if (!$json)
            abort(404, 'JSON not found');
        return response()->json($json)->header('Content-Type', 'application/activity+json');
    }

    /**
     * Return HTML View for Index.
     */
    protected function returnIndexView(\Statamic\Contracts\Entries\Entry $actor)
    {
        // Default fallback or error
        abort(404, 'View not implemented');
    }

    /**
     * Return HTML View for Show.
     */
    protected function returnShowView(\Statamic\Contracts\Entries\Entry $actor, \Statamic\Contracts\Entries\Entry $item)
    {
        // Default fallback or error
        abort(404, 'View not implemented');
    }

    /**
     * Get the collection handle (e.g. 'notes', 'articles').
     * Subclasses should override.
     */
    protected function getCollectionSlug(): ?string
    {
        return null;
    }
}
