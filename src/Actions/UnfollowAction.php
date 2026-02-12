<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Actions;

use Statamic\Actions\Action;
use Statamic\Facades\Entry;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Statamic\Facades\User;

class UnfollowAction extends Action
{
    public static function title(): string
    {
        return 'Unfollow';
    }

    public function visibleTo(mixed $item): bool
    {
        if (!$item instanceof \Statamic\Contracts\Entries\Entry) {
            return false;
        }

        if ($item->get('is_internal')) {
            return false;
        }

        $user = User::current();
        if (!$user)
            return false;

        $userActors = $user->get('actors', []) ?: [];
        if (empty($userActors))
            return false;

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

        // Also show Unfollow if pending?
        // Pending usually means we sent a follow request. Can we "undo" a pending request? Yes.
        // So validation: Followed OR Pending.

        $collections = $item->get('activitypub_collections', []) ?: [];
        if (in_array('pending', $collections)) {
            return true;
        }

        return $isFollowing;
    }

    public function authorize(mixed $user, mixed $item): bool
    {
        return true;
    }

    public function run(mixed $items, mixed $values): mixed
    {
        $user = User::current();
        $userActors = $user->get('actors', []) ?: [];
        if (empty($userActors)) {
            return 'No local actor found.';
        }

        $sender = Entry::find($userActors[0]);
        if (!$sender) {
            return 'Sender actor not found.';
        }

        foreach ($items as $actor) {
            $this->unfollowActor($sender, $actor);
        }

        return 'Unfollowed.';
    }

    protected function unfollowActor(mixed $sender, mixed $target): void
    {
        // 1. Remove from local following
        $following = $sender->get('following_actors', []) ?: [];
        if (in_array($target->id(), $following)) {
            $following = array_values(array_diff($following, [$target->id()]));
            $sender->set('following_actors', $following);
            $sender->save();
        }

        // 2. Remove from remote followed_by (local consistency)
        $followedBy = $target->get('followed_by_actors', []) ?: [];
        if (in_array($sender->id(), $followedBy)) {
            $followedBy = array_values(array_diff($followedBy, [$sender->id()]));
            $target->set('followed_by_actors', $followedBy);
            $target->save();
        }

        // 3. Remove 'pending' if it exists
        $collections = $target->get('activitypub_collections', []) ?: [];
        if (in_array('pending', $collections)) {
            $collections = array_values(array_diff($collections, ['pending']));
            $target->set('activitypub_collections', $collections);
            $target->save();
        }

        // 4. Send Undo Follow Activity
        $this->sendUndoFollow($sender, $target);
    }

    protected function sendUndoFollow(mixed $sender, mixed $target): void
    {
        $senderId = $sender->get('activitypub_id') ?: url('/@' . $sender->slug());
        $inbox = $target->get('inbox_url');
        if (!$inbox)
            return;

        $followActivity = [
            'type' => 'Follow',
            'actor' => $senderId,
            'object' => $target->get('activitypub_id'),
        ];

        $activity = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $senderId . '#undo-follow-' . Str::uuid(),
            'type' => 'Undo',
            'actor' => $senderId,
            'object' => $followActivity
        ];

        $privateKey = $sender->get('private_key');
        if (!$privateKey)
            return;

        $jsonBody = json_encode($activity);
        $headers = \Ethernick\ActivityPubCore\Services\HttpSignature::sign($inbox, $senderId, $privateKey, $jsonBody);

        try {
            Http::withHeaders($headers)->withBody($jsonBody, 'application/activity+json')->post($inbox);
        } catch (\Exception $e) {
            // Log error
        }
    }
}
