<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Http\Controllers;

use Illuminate\Support\Str;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

class AnnounceController extends BaseActivityController
{
    protected function getCollectionSlug(): string
    {
        return 'shares'; // Or 'announces'? Standard says 'shares' usually in UI, but AP collection might be 'activities'?
        // Actually, 'shares' is often usually a collection OF announces on an object.
        // But if this is a collection of Announces performed BY the actor, it's the Outbox usually.
        // If we want a separate 'announces' collection for the actor, we define it here.
        // Let's use 'announces' for now.
    }

    protected function returnIndexView(mixed $actor): mixed
    {
        abort(404); // Usually accessed via JSON or Outbox
    }

    protected function returnShowView(mixed $actor, mixed $item): mixed
    {
        return (new \Statamic\View\View)
            ->template('activitypub::activity')
            ->layout('layout')
            ->with([
                'actor' => $actor,
                'activity' => $item,
                'title' => 'Announce'
            ]);
    }

    public function store(): mixed
    {
        $user = User::current();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $actorId = $user->get('actors')[0] ?? null;
        if (!$actorId) {
            return response()->json(['error' => 'No actor found'], 400);
        }

        $objectUrl = request('object_url');
        if (!$objectUrl) {
            return response()->json(['error' => 'No object URL'], 400);
        }

        // Check for duplicate Announce
        $existing = Entry::query()
            ->where('collection', 'activities')
            ->get()
            ->first(function ($entry) use ($actorId, $objectUrl) {
                if ($entry->get('type') !== 'Announce') {
                    return false;
                }
                $actors = $entry->get('actor');
                if (!is_array($actors)) {
                    return false;
                }
                return in_array($actorId, $actors) && $entry->get('object') === $objectUrl;
            });

        if ($existing) {
            return response()->json(['status' => 'ignored']);
        }

        $uuid = Str::uuid();
        $slug = 'announce-' . $uuid;

        // Construct JSON
        $actorEntry = Entry::find($actorId);
        $actorUrl = $actorEntry ? url("/@{$actorEntry->slug()}") : $actorId;

        $json = [
            'type' => 'Announce',
            'actor' => $actorUrl,
            'object' => $objectUrl,
            'published' => now()->toIso8601String(),
            'to' => ['https://www.w3.org/ns/activitystreams#Public'],
        ];

        $activity = Entry::make()
            ->collection('activities')
            ->slug((string) $slug)
            ->data([
                'title' => 'Announce ' . $objectUrl,
                'type' => 'Announce',
                'actor' => [$actorId],
                'object' => $objectUrl,
                'object_url' => $objectUrl,
                'published' => true,
                'activitypub_collections' => ['outbox'],
                'activitypub_json' => json_encode($json),
            ]);

        $activity->save();

        return response()->json(['status' => 'success']);
    }

    public function destroy(): mixed
    {
        $user = User::current();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $actorId = $user->get('actors')[0] ?? null;
        $objectUrl = request('object_url');

        // Find the Announce activity to Undo
        $announceActivity = Entry::query()
            ->where('collection', 'activities')
            ->get()
            ->first(function ($entry) use ($actorId, $objectUrl) {
                if ($entry->get('type') !== 'Announce') {
                    return false;
                }
                $actors = $entry->get('actor');
                if (!is_array($actors)) {
                    return false;
                }
                return in_array($actorId, $actors) && $entry->get('object') === $objectUrl;
            });

        if ($announceActivity) {
            $uuid = Str::uuid();

            // JSON for Undo
            $actorEntry = Entry::find($actorId);
            $actorUrl = $actorEntry ? url("/@{$actorEntry->slug()}") : $actorId;

            $json = [
                'type' => 'Undo',
                'actor' => $actorUrl,
                'object' => [
                    'type' => 'Announce',
                    'object' => $objectUrl,
                    'actor' => $actorUrl,
                ],
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            ];

            $undo = Entry::make()
                ->collection('activities')
                ->slug('undo-announce-' . $uuid)
                ->data([
                    'title' => 'Undo Announce ' . $objectUrl,
                    'type' => 'Undo',
                    'content' => 'Undid Announce of ' . $objectUrl,
                    'actor' => [$actorId],
                    'object' => [$announceActivity->id()],
                    'published' => true,
                    'activitypub_collections' => ['outbox'],
                    'activitypub_json' => json_encode($json),
                ]);
            $undo->save();
        }

        return response()->json(['status' => 'success']);
    }
}
