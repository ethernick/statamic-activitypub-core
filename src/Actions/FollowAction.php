<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Actions;

use Statamic\Actions\Action;
use Statamic\Facades\Entry;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Statamic\Facades\User;

class FollowAction extends Action
{
    public static function title(): string
    {
        return 'Follow';
    }

    public function visibleTo(mixed $item): bool
    {
        if (!$item instanceof \Statamic\Contracts\Entries\Entry) {
            return false;
        }

        // Only visible for external actors
        if ($item->get('is_internal')) {
            return false;
        }

        // Only visible if NOT already following (or pending)
        // We need to check if ANY of the current user's actors are following this item.
        // But Actions context is tricky. Usually "current user".

        $user = User::current();
        if (!$user)
            return false;

        $userActors = $user->get('actors', []) ?: [];
        if (empty($userActors))
            return false;

        // Check if item is in any user actor's following list (or pending list via collections?)
        // Ideally we check if we are ALREADY following.

        // Simpler check: If I am logged in, and I have actors, is this actor in my following list?
        // But "following list" is stored on the actor entry, not the user.
        // "following_actors" logic from FollowController:

        $isFollowing = false;
        foreach ($userActors as $actorId) {
            $actor = Entry::find($actorId);
            if ($actor) {
                $following = $actor->get('following_actors', []) ?: [];
                if (in_array($item->id(), $following)) {
                    $isFollowing = true;
                    break;
                }
            }
        }

        // Also check pending status on the target actor?
        // If target has 'pending' in activitypub_collections, we might want to show "Pending" status or disable Action?
        // Actions can't easily change title dynamically per item based on complex logic in list view efficiently?
        // Actually visibleTo is run per item.

        $collections = $item->get('activitypub_collections', []) ?: [];
        if (in_array('pending', $collections)) {
            return false; // Already pending
        }

        return !$isFollowing;
    }

    public function authorize(mixed $user, mixed $item): bool
    {
        return true;
    }

    public function run(mixed $items, mixed $values): mixed
    {
        $user = User::current();
        $sender = null;

        // Pick the first internal actor of the user to perform the follow
        // TODO: Support selecting WHICH actor to follow as?
        // Actions don't support complex UI for selection easily without "fields".
        // We could add a field to select "Follow As...". 

        $userActors = $user->get('actors', []) ?: [];
        if (empty($userActors)) {
            return 'No local actor found for current user.';
        }

        // Just pick the first one for now
        $sender = Entry::find($userActors[0]);
        if (!$sender)
            return 'Sender actor not found.';

        foreach ($items as $actor) {
            // Reuse FollowController logic via internal request or extraction?
            // Let's duplicate calls for now to be self-contained or call a Service.
            // We don't have a dedicated Service for "Send Follow", it's in Controller.
            // I'll reimplement the simple logic here using a helper if possible or raw code.

            // Actually, calling the Controller method is messy.
            // Let's copy the logic. It's safe.

            $this->followActor($sender, $actor);
        }

        return 'Follow request(s) sent!';
    }

    protected function followActor(mixed $sender, mixed $target): void
    {
        $senderId = $sender->get('activitypub_id') ?: url('/@' . $sender->slug());
        $inbox = $target->get('inbox_url');
        if (!$inbox)
            return;

        $activityId = $senderId . '#follow-' . Str::uuid();
        $activity = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $activityId,
            'type' => 'Follow',
            'actor' => $senderId,
            'object' => $target->get('activitypub_id'),
        ];

        $privateKey = $sender->get('private_key');
        if (!$privateKey)
            return;

        $jsonBody = json_encode($activity);
        $headers = \Ethernick\ActivityPubCore\Services\HttpSignature::sign(
            $inbox,
            $senderId,
            $privateKey,
            $jsonBody
        );

        if (empty($headers))
            return;

        try {
            $response = Http::withHeaders($headers)
                ->withBody($jsonBody, 'application/activity+json')
                ->post($inbox);

            if ($response->successful()) {
                // Mark as pending
                $collections = $target->get('activitypub_collections', []) ?: [];
                if (!in_array('pending', $collections)) {
                    $collections[] = 'pending';
                    $target->set('activitypub_collections', array_values($collections));
                    $target->save();
                }
            }
        } catch (\Exception $e) {
            // Log error
        }
    }
}
