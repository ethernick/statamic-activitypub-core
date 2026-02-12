<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Fieldtypes;

use Statamic\Fields\Fieldtype;
use Statamic\Facades\User;

class ActorSelector extends Fieldtype
{
    protected static $handle = 'actor_selector';

    // protected $categories = ['relationship'];
    protected $listable = false;

    public function preload(): array
    {
        $user = User::current();

        if (!$user) {
            return ['actors' => []];
        }

        $actorIds = $user->get('actors') ?? [];
        \Illuminate\Support\Facades\Log::debug('ActorSelector: Preloading actors.', ['ids' => $actorIds]);

        $options = collect($actorIds)->map(function ($id) {
            $entry = \Statamic\Facades\Entry::find($id);
            if ($entry) {
                return ['label' => $entry->get('title'), 'value' => $id];
            }
            return ['label' => $id, 'value' => $id];
        })->values()->all();

        return ['actors' => $options];
    }

    public function defaultValue(): mixed
    {
        $user = User::current();

        if (!$user) {
            \Illuminate\Support\Facades\Log::debug('ActorSelector: No current user found in defaultValue.');
            return null;
        }

        $actors = $user->get('actors');
        \Illuminate\Support\Facades\Log::debug('ActorSelector: User found.', ['id' => $user->id(), 'actors' => $actors]);

        if ($actors && count($actors) > 0) {
            // Return the ID of the first actor
            return $actors[0];
        }

        return null;
    }

    public function process(mixed $data): mixed
    {
        // Ensure we only save a single string, not an array
        if (is_array($data)) {
            return $data[0] ?? null;
        }

        return $data;
    }

    protected function toItemArray(mixed $id): mixed
    {
        // Recursively unwrap arrays/collections to find the first scalar value
        while (is_array($id) || $id instanceof \Illuminate\Support\Collection) {
            if ($id instanceof \Illuminate\Support\Collection) {
                $id = $id->first();
            } else {
                if (empty($id)) {
                    $id = null;
                    break;
                }
                $id = reset($id);
            }
        }

        // If we still don't have a string/int, we can't look it up
        if (!is_string($id) && !is_int($id)) {
            return null;
        }

        return $id;
    }
}
