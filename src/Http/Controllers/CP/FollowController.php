<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Http\Controllers\CP;

use Statamic\Http\Controllers\CP\CpController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Statamic\Facades\Entry;

class FollowController extends CpController
{
    private function getUserActors(): \Illuminate\Support\Collection
    {
        $user = \Statamic\Facades\User::current();
        if (!$user)
            return collect();

        $actorIds = $user->get('actors', []);

        // If no specifically linked actors, fallback to all internal actors (admin view?)
        // Or strictly empty. Let's return all internal to be helpful if not explicitly linked yet.
        if (empty($actorIds)) {
            return Entry::query()->where('collection', 'actors')->where('is_internal', true)->get();
        }

        return Entry::query()->where('collection', 'actors')->whereIn('id', $actorIds)->get();
    }

    public function following()
    {
        $myActors = $this->getUserActors();
        $followingIds = $myActors->flatMap(function ($actor) {
            $ids = $actor->get('following_actors', []) ?: [];
            return is_array($ids) ? $ids : [$ids];
        })->unique()->filter()->all();

        $actors = Entry::query()
            ->where('collection', 'actors')
            ->whereIn('id', $followingIds)
            ->paginate(50);

        return view('activitypub::following', [
            'title' => 'Following',
            'actors' => $actors,
            'myActors' => $myActors,
        ]);
    }

    public function followers()
    {
        $myActors = $this->getUserActors();
        $followerIds = $myActors->flatMap(function ($actor) {
            $ids = $actor->get('followed_by_actors', []) ?: [];
            return is_array($ids) ? $ids : [$ids];
        })->unique()->filter()->all();

        $actors = Entry::query()
            ->where('collection', 'actors')
            ->whereIn('id', $followerIds)
            ->paginate(50);

        return view('activitypub::followers', [
            'title' => 'Followers',
            'actors' => $actors,
            'myActors' => $myActors,
        ]);
    }

    public function unfollow(Request $request)
    {
        $targetId = $request->input('id');
        $senderId = $request->input('sender');

        $target = Entry::find($targetId);
        $sender = Entry::find($senderId);

        if (!$target || !$sender)
            return response()->json(['error' => 'Actor not found'], 404);

        // Remove from local following
        $following = $sender->get('following_actors', []) ?: [];
        $following = array_diff($following, [$target->id()]);
        $sender->set('following_actors', array_values($following));
        $sender->save();

        // Remove from remote followed_by (local consistency)
        $followedBy = $target->get('followed_by_actors', []) ?: [];
        $followedBy = array_diff($followedBy, [$sender->id()]);
        $target->set('followed_by_actors', array_values($followedBy));
        $target->save();

        // Send Undo Follow Activity
        $this->sendUndoFollow($sender, $target);

        return response()->json(['success' => true]);
    }

    public function block(Request $request)
    {
        $targetId = $request->input('id');
        $senderId = $request->input('sender');

        $target = Entry::find($targetId);
        $sender = Entry::find($senderId);

        if (!$target || !$sender)
            return response()->json(['error' => 'Actor not found'], 404);

        // Add to blocks
        $blocks = $sender->get('blocks', []) ?: [];
        if (!in_array($target->id(), $blocks)) {
            $blocks[] = $target->id();
            $sender->set('blocks', $blocks);
            $sender->save();
        }

        // Also Unfollow if blocking? Generally yes.
        // Let's keep it separate for now or explicit. 
        // User asked for "Block/Unblock", didn't specify auto-unfollow, but it's standard.
        // I'll leave it as just Block for now to be safe, or maybe client-side chain.

        return response()->json(['success' => true]);
    }

    public function unblock(Request $request)
    {
        $targetId = $request->input('id');
        $senderId = $request->input('sender');

        $target = Entry::find($targetId);
        $sender = Entry::find($senderId);

        if (!$target || !$sender)
            return response()->json(['error' => 'Actor not found'], 404);

        // Remove from blocks
        $blocks = $sender->get('blocks', []) ?: [];
        $blocks = array_diff($blocks, [$target->id()]);
        $sender->set('blocks', array_values($blocks));
        $sender->save();

        return response()->json(['success' => true]);
    }

    protected function sendUndoFollow(mixed $sender, mixed $target)
    {
        // Construct Activity
        $senderId = $sender->get('activitypub_id') ?: url('/@' . $sender->slug());
        // We need the original Follow ID to Undo it correctly? 
        // Usually Undo { object: Follow }.
        // We don't store the original Follow ID easily. 
        // We can generate a new ID or just wrap a reconstructed Follow object.
        // "Undo of Follow" usually wraps the Follow activity itself.

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

        // Send to Outbox (File Queue)
        // Reusing SendActivityPubPost job logic or just pushing to queue directly?
        // Since we are in Controller, let's use the FileQueue helper directly if valid, 
        // or re-use the sign-and-send logic if we want immediate feedback.
        // The user verified `follow` does immediate post. `unfollow` should too.

        // ... (reuse send logic) ...
        // For brevity/consistency I will call a helper or duplicate simple logic.
        // Ideally should refactor `sendActivity` to a trait or service.

        $inbox = $target->get('inbox_url');
        if (!$inbox)
            return;

        $privateKey = $sender->get('private_key');
        if (!$privateKey)
            return;

        $jsonBody = json_encode($activity);
        $headers = \Ethernick\ActivityPubCore\Services\HttpSignature::sign($inbox, $senderId, $privateKey, $jsonBody);

        try {
            Http::withHeaders($headers)->withBody($jsonBody, 'application/activity+json')->post($inbox);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Failed to send Undo Follow: " . $e->getMessage());
        }
    }

    public function search(Request $request)
    {
        $handle = $request->input('handle');
        if (!$handle) {
            return response()->json(['error' => 'Handle is required'], 400);
        }

        // Validate handle format (needs @domain)
        if (!str_contains($handle, '@')) {
            return response()->json(['error' => 'Invalid handle format. Use name@domain.com or @name@domain.com'], 400);
        }

        // Remove leading @
        $handle = ltrim($handle, '@');
        [$username, $domain] = explode('@', $handle, 2);

        // 1. Check if actor exists locally by checking if we have an actor with this ID?
        // But we don't know the ID yet. We might check by title/handle if we stored it?
        // Actually, let's do the WebFinger lookup first to get the ID.

        $resource = "acct:{$handle}";
        $webfingerUrl = "https://{$domain}/.well-known/webfinger?resource={$resource}";

        try {
            $response = Http::get($webfingerUrl);
            if (!$response->successful()) {
                return response()->json(['error' => "WebFinger lookup failed: {$response->status()}"], 400);
            }

            $data = $response->json();
            $links = collect($data['links'] ?? []);
            $selfLink = $links->firstWhere('rel', 'self');

            if (!$selfLink || !isset($selfLink['href']) || ($selfLink['type'] ?? '') !== 'application/activity+json') {
                return response()->json(['error' => "No ActivityPub profile found via WebFinger"], 404);
            }

            $activityPubId = $selfLink['href'];

            // Now check if we have this actor locally
            $existingActor = Entry::query()
                ->where('collection', 'actors')
                ->where('activitypub_id', $activityPubId)
                ->first();

            if ($existingActor) {
                $userActors = $this->getUserActors();
                $isFollowing = $userActors->contains(function ($actor) use ($existingActor) {
                    $following = $actor->get('following_actors', []) ?: [];
                    return in_array($existingActor->id(), is_array($following) ? $following : []);
                });

                return response()->json([
                    'id' => $existingActor->id(), // This is the Statamic ID
                    'activitypub_id' => $existingActor->get('activitypub_id'),
                    'name' => $existingActor->get('title'),
                    'username' => $existingActor->get('handle'), // Local handle is preferredUsername
                    'avatar' => $existingActor->augmentedValue('avatar')->value()?->url(),
                    'is_following' => $isFollowing,
                    'is_pending' => in_array('pending', $existingActor->get('activitypub_collections', []) ?: []),
                ]);
            }

            // Fetch remote actor details
            // We need to fetch with Acceptance header
            $actorResponse = Http::withHeaders(['Accept' => 'application/activity+json'])->get($activityPubId);
            if (!$actorResponse->successful()) {
                return response()->json(['error' => "Failed to fetch actor profile: {$actorResponse->status()}"], 400);
            }

            $actorData = $actorResponse->json();

            // We return the data but don't save yet?
            // "if the follow button is clicked, save the actor as an external actor"
            // But to return a Statamic-like structure we might need to conform.
            // Let's just return the raw data needed for display

            $name = $actorData['name'] ?? $actorData['preferredUsername'] ?? $username;
            $preferredUsername = $actorData['preferredUsername'] ?? $username;
            $avatar = null;
            if (isset($actorData['icon']['url'])) {
                $avatar = $actorData['icon']['url'];
            }
            // Fallback for avatar mapping if not simple URL
            if (!$avatar && isset($actorData['icon']) && is_string($actorData['icon'])) {
                $avatar = $actorData['icon'];
            }

            return response()->json([
                'id' => $activityPubId, // Use AP ID as temp ID for follow action
                'activitypub_id' => $activityPubId,
                'name' => $name,
                'username' => $preferredUsername,
                'avatar' => $avatar,
                'is_following' => false,
                'is_pending' => false,
                'data' => $actorData, // Pass full data to be saved on follow? No, too big. 
                // We'll re-fetch or use logic in follow endpoint.
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function follow(Request $request)
    {
        $id = $request->input('id');
        $actor = null;

        // 1. Resolve Target Actor
        if (\Illuminate\Support\Str::isUuid($id)) {
            $actor = Entry::find($id);
        } else {
            // Create external actor if not exists
            // (Reuse logic logic or call helper - for now inline simplified version suited for Follow action)
            $actorUrl = $id;
            $existing = Entry::query()->where('collection', 'actors')->where('activitypub_id', $actorUrl)->first();
            if ($existing) {
                $actor = $existing;
            } else {
                // Fetch and create
                $response = Http::withHeaders(['Accept' => 'application/activity+json'])->get($actorUrl);
                if (!$response->successful())
                    return response()->json(['error' => "Failed to fetch actor."], 400);
                $data = $response->json();

                //$username = $data['preferredUsername'] ?? 'unknown';
                $username = $data['preferredUsername'] ?? 'unknown';
                $domain = parse_url($actorUrl, PHP_URL_HOST);

                // Tongue-in-cheek slug generation: @ -> -at-, . -> -dot-
                $rawHandle = "{$username}-at-{$domain}";
                $slug = str_replace('.', '-dot-', $rawHandle);
                $slug = Str::slug($slug); // Ensure it's safe

                // Create Entry
                $actor = Entry::make()
                    ->collection('actors')
                    ->slug($slug)
                    ->data([
                        'title' => $data['name'] ?? $username,
                        'activitypub_id' => $actorUrl,
                        'is_internal' => false,
                        'inbox_url' => $data['inbox'] ?? null,
                        'outbox_url' => $data['outbox'] ?? null,
                        'shared_inbox_url' => $data['endpoints']['sharedInbox'] ?? null,
                        'public_key' => $data['publicKey']['publicKeyPem'] ?? null,
                        'avatar' => $this->downloadAvatar($data['icon'] ?? null),
                        'content' => $data['summary'] ?? null, // Map summary to description
                    ]);
                $actor->save();
            }
        }

        if (!$actor)
            return response()->json(['error' => 'Actor not found or could not be created'], 500);

        // 2. Identify Local Sender
        // logic: pick the first internal actor
        $sender = Entry::query()
            ->where('collection', 'actors')
            ->where('is_internal', true)
            ->first();

        if (!$sender)
            return response()->json(['error' => 'No local actor configured to send follow request'], 500);

        // Resolve Sender ID (Internal actors might not have activitypub_id stored)
        $senderId = $sender->get('activitypub_id');
        if (!$senderId) {
            $senderId = url('/@' . $sender->slug());
        }

        // 3. Send Follow Activity
        $inbox = $actor->get('inbox_url');
        if (!$inbox)
            return response()->json(['error' => 'Target actor has no inbox'], 400);

        $activityId = $senderId . '#follow-' . \Illuminate\Support\Str::uuid();
        $activity = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $activityId,
            'type' => 'Follow',
            'actor' => $senderId,
            'object' => $actor->get('activitypub_id'),
        ];

        // Sign Request
        // We need the private key. In Statamic we probably stored it in the entry or a file.
        // Assuming it's in a field 'private_key' on the sender actor entry.
        // Note: The blueprint might not store it explicitly or hidden. 
        // Based on previous conversations, we generated keys. Let's assume 'private_key' field exists.
        $privateKey = $sender->get('private_key');
        if (!$privateKey) {
            // Check if it's in a file based on slug? 
            // Often keys are too large for simple text fields if not configured right, but let's assume field.
            return response()->json(['error' => 'Local actor has no private key'], 500);
        }

        $jsonBody = json_encode($activity);

        $headers = \Ethernick\ActivityPubCore\Services\HttpSignature::sign(
            $inbox,
            $senderId,
            $privateKey,
            $jsonBody
        );

        if (empty($headers))
            return response()->json(['error' => 'Failed to sign request'], 500);

        try {
            $response = Http::withHeaders($headers)
                ->withBody($jsonBody, 'application/activity+json')
                ->post($inbox);

            if ($response->successful()) {
                // Success! Tag as Pending.
                // Success! Tag as Pending.
                // We do NOT add to following_actors yet. We wait for Accept activity.
                // InboxHandler will process 'Accept', remove 'pending', add 'following', and link actors.

                $collections = $actor->get('activitypub_collections', []) ?: [];
                if (!in_array('pending', $collections)) {
                    $collections[] = 'pending';
                    $actor->set('activitypub_collections', array_values($collections));
                    $actor->save();
                }

                return response()->json(['success' => true]);
            } else {
                return response()->json(['error' => "Remote server rejected follow request: {$response->status()} " . $response->body()], 400);
            }
        } catch (\Exception $e) {
            return response()->json(['error' => "Communication error: " . $e->getMessage()], 500);
        }
    }
    protected function downloadAvatar(mixed $iconData): ?string
    {
        if (!$iconData)
            return null;

        $url = null;
        if (is_array($iconData) && isset($iconData['url'])) {
            $url = $iconData['url'];
        } elseif (is_string($iconData)) {
            $url = $iconData;
        }

        if (!$url)
            return null;

        try {
            $contents = Http::get($url)->body();
            if (!$contents)
                return null;

            $name = md5($url);
            $extension = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
            if (!$extension)
                $extension = 'jpg';

            // Limit extension length and characters for safety
            $extension = substr(preg_replace('/[^a-z0-9]/', '', strtolower($extension)), 0, 4);
            if (empty($extension))
                $extension = 'jpg';

            $filename = "avatars/{$name}.{$extension}";

            \Illuminate\Support\Facades\Storage::disk('assets')->put($filename, $contents);

            return $filename;
        } catch (\Exception $e) {
            // Log error but continue without avatar
            \Illuminate\Support\Facades\Log::error("Failed to download avatar: " . $e->getMessage());
            return null;
        }
    }
}
