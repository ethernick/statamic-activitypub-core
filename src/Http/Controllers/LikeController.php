<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Http\Controllers;

class LikeController extends BaseActivityController
{
    protected function getCollectionSlug(): string
    {
        return 'likes';
        // This would be the actor's "Liked" collection (things they liked).
    }

    protected function returnIndexView(mixed $actor): mixed
    {
        abort(404); // Usually JSON only
    }

    protected function returnShowView(mixed $actor, mixed $item): mixed
    {
        return (new \Statamic\View\View)
            ->template('activitypub::activity')
            ->layout('layout')
            ->with([
                'actor' => $actor,
                'activity' => $item,
                'title' => 'Like'
            ]);
    }

    public function store(): mixed
    {
        $user = \Statamic\Facades\User::current();
        if (!$user)
            return response()->json(['error' => 'Unauthorized'], 401);

        $actorId = $user->get('actors')[0] ?? null;
        if (!$actorId)
            return response()->json(['error' => 'No actor found'], 400);

        $objectUrl = request('object_url');
        if (!$objectUrl)
            return response()->json(['error' => 'No object URL'], 400);

        $uuid = \Illuminate\Support\Str::uuid();
        $slug = 'like-' . $uuid;

        // Construct JSON
        $actorEntry = \Statamic\Facades\Entry::find($actorId);
        $actorUrl = $actorEntry ? url("/@{$actorEntry->slug()}") : $actorId;

        $json = [
            'type' => 'Like',
            'actor' => $actorUrl,
            'object' => $objectUrl,
            'published' => now()->toIso8601String(),
            'to' => ['https://www.w3.org/ns/activitystreams#Public'],
        ];

        $activity = \Statamic\Facades\Entry::make()
            ->collection('activities')
            ->slug((string) $slug)
            ->data([
                'title' => 'Like ' . $objectUrl,
                'type' => 'Like',
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
        $user = \Statamic\Facades\User::current();
        if (!$user)
            return response()->json(['error' => 'Unauthorized'], 401);

        $actorId = $user->get('actors')[0] ?? null;

        $objectUrl = request('object_url');

        // Find the Like activity to Undo
        // This is a naive lookup for the test
        $likeActivity = \Statamic\Facades\Entry::query()
            ->where('collection', 'activities')
            ->where('type', '=', 'Like')
            ->where('object_url', $objectUrl) // Assuming we store it
            // ->where('actor', $actorId) // Difficult to query array in flat file properly without specific driver logic, but let's try assuming standard Stache behavior or test environment.
            // For now, simpler:
            ->get()
            ->first(function ($e) use ($actorId, $objectUrl) {
                // Check actor (array)
                $actors = $e->get('actor');
                if (!is_array($actors))
                    return false;
                return in_array($actorId, $actors) && $e->get('object_url') === $objectUrl;
            });

        if ($likeActivity) {
            $uuid = \Illuminate\Support\Str::uuid();

            // JSON for Undo
            $actorEntry = \Statamic\Facades\Entry::find($actorId);
            $actorUrl = $actorEntry ? url("/@{$actorEntry->slug()}") : $actorId;

            $json = [
                'type' => 'Undo',
                'actor' => $actorUrl,
                'object' => [
                    'type' => 'Like',
                    'object' => $objectUrl,
                    'actor' => $actorUrl
                ],
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            ];

            $undo = \Statamic\Facades\Entry::make()
                ->collection('activities')
                ->slug('undo-like-' . $uuid)
                ->data([
                    'title' => 'Undo Like ' . $objectUrl,
                    'type' => 'Undo',
                    'actor' => [$actorId],
                    'object' => [$likeActivity->id()], // Array of IDs expected by test
                    'published' => true,
                    'activitypub_collections' => ['outbox'],
                    'activitypub_json' => json_encode($json),
                ]);
            $undo->save();
        }

        return response()->json(['status' => 'success']);
    }
}
