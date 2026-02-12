<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Jobs;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\Entry;
use Illuminate\Support\Str;
use Statamic\Facades\File;
use Statamic\Facades\YAML;
use Ethernick\ActivityPubCore\Services\HttpSignature;
use Ethernick\ActivityPubCore\Services\ThreadService;

class InboxHandler
{
    public function handle(array $payload, $localActor, $externalActor = null)
    {
        $type = $payload['type'] ?? 'Unknown';
        $actorId = $payload['actor'] ?? 'Unknown';

        // Block Check: Is the sender blocked by the local actor?
        $blocks = $localActor->get('blocks', []);
        if (is_array($blocks)) {
            if ($externalActor && in_array($externalActor->id(), $blocks)) {
                Log::info("InboxHandler: Blocked activity from $actorId ($type) to " . $localActor->get('title'));
                return; // Dropped silently
            }
        }

        Log::info("InboxHandler: Processing $type from $actorId");

        $shouldSave = true;

        try {
            // 0. Pre-flight Federated Check
            // We need to check if the target collection is federated BEFORE checking Dispatcher.
            // Dispatcher might handle it, but we want to policy-block it regardless of who handles it.

            $object = $payload['object'] ?? null;
            $objectType = is_array($object) ? ($object['type'] ?? 'Unknown') : 'Unknown';
            // If object is string (ID), we can't easily know type without fetching/parsing.
            // But usually Create/Update sends the object.
            // Delete typically sends ID or object.

            if ($objectType !== 'Unknown') {
                // Map Object Type to Collection
                // Use ActivityPubTypes if available
                $targetCollection = null;
                if (class_exists(\Ethernick\ActivityPubCore\Services\ActivityPubTypes::class)) {
                    $types = \Ethernick\ActivityPubCore\Services\ActivityPubTypes::getCollections($objectType);
                    if (!empty($types)) {
                        $targetCollection = $types[0]; // Take first associated collection
                    }
                }

                // Fallback for core types if not resolved via service (e.g. if service not reg yet or whatever)
                if (!$targetCollection) {
                    if ($objectType === 'Note')
                        $targetCollection = 'notes';
                    elseif ($objectType === 'Article')
                        $targetCollection = 'articles';
                    elseif ($objectType === 'Question')
                        $targetCollection = 'polls';
                }

                if ($targetCollection && !$this->isFederated($targetCollection)) {
                    Log::info("InboxHandler: Dropping $type:$objectType because collection $targetCollection is not federated.");
                    return;
                }
            }

            // 1. Try Dispatcher
            $result = \Ethernick\ActivityPubCore\Services\ActivityDispatcher::dispatch($payload, $localActor, $externalActor);

            if ($result !== null) {
                // Dispatched successfully (or attempt made).
                // If it returned explicit false (e.g. Stray check failed), implies we might not want to save activity?
                // But typically we save legal activities even if ignored content, unless specifically "Stray".
                // The NoteController::handleCreate returns false for stray.
                // So:
                if ($result === false) {
                    $shouldSave = false;
                }
            } else {
                // 2. Fallback to specific handler logic
                switch ($type) {
                    case 'Follow':
                        $this->processFollowActivity($payload, $localActor, $externalActor);
                        break;
                    case 'Accept':
                        $this->processAcceptActivity($payload, $localActor, $externalActor);
                        break;
                    case 'Reject':
                        $this->processRejectActivity($payload, $localActor, $externalActor);
                        break;
                    case 'Undo':
                        $this->processUndoActivity($payload, $localActor, $externalActor);
                        break;
                    // Create/Update/Delete delegated to Dispatcher for Notes, but might fall here for others if not registered.
                    case 'Create':
                        $shouldSave = $this->processCreateActivity($payload, $localActor, $externalActor);
                        break;
                    case 'Update':
                        $shouldSave = $this->processUpdateActivity($payload, $localActor, $externalActor);
                        break;
                    case 'Like':
                        $this->handleLikeActivity($payload, $actorId);
                        break;
                    case 'Announce':
                        $this->handleAnnounceActivity($payload, $localActor, $externalActor);
                        break;
                    case 'Delete':
                        $shouldSave = $this->processDeleteActivity($payload, $localActor, $externalActor);
                        break;
                    default:
                        Log::info("InboxHandler: Unhandled activity type $type from $actorId");
                        break;
                }
            }

            // Save Activity Log
            if ($shouldSave) {
                // Ensure actor is persisted before linking
                if ($externalActor && !$externalActor->id()) {
                    // exists property might not be set on new Entry make? 
                    // Statamic ID is usually set.
                    // Let's rely on finding it or saving it.
                    // If it has NO ID, it definitely needs saving.
                    // If it HAS ID, we check if it's in DB? 
                    // `save()` is idempotent-ish (updates if exists).
                    $externalActor->save();
                }
                $this->saveActivity($payload, $externalActor);
            }

        } catch (\Exception $e) {
            Log::error("InboxHandler: Exception processing $type: " . $e->getMessage());
            throw $e; // Re-throw to stop processing queue
        }
    }

    protected function processFollowActivity(array $payload, mixed $actor, mixed $externalActor): void
    {
        $actorId = $payload['actor'] ?? 'Unknown';
        Log::info("InboxHandler: Processing Follow from $actorId");

        $targetId = $payload['object'] ?? null;
        if (!$targetId) {
            Log::error('InboxHandler: Missing object in Follow activity');
            return;
        }

        // Link External Actor to Local Actor (External is FOLLOWING Internal)
        $following = $externalActor->get('following_actors', []);
        if (!is_array($following))
            $following = $following ? [$following] : [];

        if (!in_array($actor->id(), $following)) {
            $following[] = $actor->id();
            $externalActor->set('following_actors', $following);
        }

        // Sync Local Actor 'followed_by_actors' (Me <- Them)
        $myFollowers = $actor->get('followed_by_actors', []);
        if (!is_array($myFollowers))
            $myFollowers = $myFollowers ? [$myFollowers] : [];

        if (!in_array($externalActor->id(), $myFollowers)) {
            $myFollowers[] = $externalActor->id();
            $actor->set('followed_by_actors', $myFollowers);
            $actor->save();
        }

        // Add to 'followers' taxonomy
        $collections = $externalActor->get('activitypub_collections', []);
        if (!in_array('followers', $collections)) {
            $collections[] = 'followers';
            $externalActor->set('activitypub_collections', array_values($collections));
        }
        if (!in_array('followers', $collections)) {
            $collections[] = 'followers';
            $externalActor->set('activitypub_collections', array_values($collections));
        }
        $externalActor->save(); // This saves it if it was ephemeral


        // Send Accept Activity
        $this->sendAcceptActivity($actor, $payload, $externalActor);

        Log::info("InboxHandler: Accepted Follow from $actorId");
    }

    protected function processAcceptActivity(array $payload, mixed $actor, mixed $externalActor): void
    {
        $actorId = $payload['actor'] ?? 'Unknown';
        Log::info("InboxHandler: Processing Accept from $actorId");

        $collections = $externalActor->get('activitypub_collections', []);

        if (in_array('pending', $collections)) {
            $collections = array_diff($collections, ['pending']);
            if (!in_array('following', $collections)) {
                $collections[] = 'following';
            }
            $externalActor->set('activitypub_collections', array_values($collections));

            $followedBy = $externalActor->get('followed_by_actors', []);
            if (!is_array($followedBy))
                $followedBy = $followedBy ? [$followedBy] : [];

            if (!in_array($actor->id(), $followedBy)) {
                $followedBy[] = $actor->id();
                $externalActor->set('followed_by_actors', $followedBy);
            }

            // Sync Local Actor 'following_actors' (Me -> Them)
            $myFollowing = $actor->get('following_actors', []);
            if (!is_array($myFollowing))
                $myFollowing = $myFollowing ? [$myFollowing] : [];

            if (!in_array($externalActor->id(), $myFollowing)) {
                $myFollowing[] = $externalActor->id();
                $actor->set('following_actors', $myFollowing);
                $actor->save();
            }

            $externalActor->save();
            Log::info("InboxHandler: Promoted $actorId to following");

            // Backfill Outbox
            \Ethernick\ActivityPubCore\Jobs\BackfillActorOutbox::dispatch($actor->id(), $externalActor->id());
        }
    }

    protected function processRejectActivity(array $payload, mixed $actor, mixed $externalActor): void
    {
        $actorId = $payload['actor'] ?? 'Unknown';
        Log::info("InboxHandler: Processing Reject from $actorId");

        $collections = $externalActor->get('activitypub_collections', []);
        if (in_array('pending', $collections)) {
            $collections = array_diff($collections, ['pending']);
            $externalActor->set('activitypub_collections', array_values($collections));
            $externalActor->save();
            Log::info("InboxHandler: Removed pending status from $actorId");
        }
    }

    protected function processUndoActivity(array $payload, mixed $actor, mixed $externalActor): void
    {
        $actorId = $payload['actor'] ?? 'Unknown';
        $object = $payload['object'] ?? [];
        $objectType = is_array($object) ? ($object['type'] ?? '') : '';

        if ($objectType === 'Follow' || (is_string($object) && str_contains($object, 'Follow'))) {
            Log::info("InboxHandler: Processing Undo Follow from $actorId");
            $collections = $externalActor->get('activitypub_collections', []);
            if (in_array('followers', $collections)) {
                $collections = array_diff($collections, ['followers']);
                $externalActor->set('activitypub_collections', array_values($collections));
                $externalActor->save();
                Log::info("InboxHandler: Removed follower $actorId");
            }
        } elseif ($objectType === 'Like' || (is_array($object) && ($object['type'] ?? '') === 'Like')) {
            Log::info("InboxHandler: Processing Undo Like from $actorId");
            $this->handleUndoLikeActivity($object, $actorId);
        }
    }

    protected function processCreateActivity(array $payload, mixed $actor, mixed $externalActor): bool
    {
        $actorId = $payload['actor'] ?? 'Unknown';
        Log::info("InboxHandler: Processing Create from $actorId");
        $object = $payload['object'] ?? null;

        if (is_array($object) && in_array($object['type'] ?? '', ['Note', 'Article', 'Question'])) {

            // Check if local actor follows this external actor
            $following = $actor->get('following_actors', []) ?: [];

            // Check if Mentioned (Directly in 'to' or 'cc')
            $to = $object['to'] ?? [];
            $cc = $object['cc'] ?? [];
            if (!is_array($to))
                $to = [$to];
            if (!is_array($cc))
                $cc = [$cc];

            $addressed = array_merge($to, $cc);
            $myApId = $actor->get('activitypub_id') ?: $actor->absoluteUrl();
            $isMentioned = in_array($myApId, $addressed);

            // Check if Reply to Local/Known content
            $inReplyTo = $object['inReplyTo'] ?? null;
            $isReplyToKnown = false;
            if ($inReplyTo) {
                // Check if it's a reply to a local note or a known external note
                $isReplyToKnown = Entry::query()->where('collection', 'notes')->where('activitypub_id', $inReplyTo)->exists()
                    || Entry::find($inReplyTo);
            }

            if (in_array($externalActor->id(), $following) || $isMentioned || $isReplyToKnown) {
                $targetCollection = 'notes';
                if (($object['type'] ?? '') === 'Question') {
                    $targetCollection = 'polls';
                } elseif (($object['type'] ?? '') === 'Article') {
                    $targetCollection = 'articles';
                }

                if (!$this->isFederated($targetCollection)) {
                    Log::info("InboxHandler: Dropping {$object['type']} because $targetCollection is not federated.");
                    return false;
                }

                if (($object['type'] ?? '') === 'Question') {
                    $this->createPollEntry($object, $externalActor);
                } else {
                    $this->createNoteEntry($object, $externalActor);
                }
                return true;
            } else {
                Log::info("InboxHandler: Ignoring Create Note from non-followed/irrelevant actor $actorId");
                return false;
            }
        }
        return true; // Default to saving for other types or odd cases? Ideally true to log "activity" even if object creation failed/ignored? 
        // Wait, if we ignore the note, we probably want to ignore the activity log too?
        // If we return false, neither actor nor activity is saved.
        // If we want to Log the activity as "processed but ignored", we should return true.
        // User request: "resolves with a cc to someone I follow... sometimes it resolved the actos... no note... shouldn't have needed to event resolve".
        // User wants to AVOID persisting the stray actor. So we must return FALSE.
    }

    protected function processUpdateActivity(array $payload, mixed $actor, mixed $externalActor): bool
    {
        $actorId = $payload['actor'] ?? 'Unknown';
        Log::info("InboxHandler: Processing Update from $actorId");
        $object = $payload['object'] ?? null;
        $following = $actor->get('following_actors', []) ?: [];
        $followedBy = $actor->get('followed_by_actors', []) ?: [];

        $isConnected = in_array($externalActor->id(), $following) || in_array($externalActor->id(), $followedBy);

        $objectId = $object['id'] ?? null;
        if (!$objectId) {
            return false; // Cannot update something without ID.
        }

        // Check if we have the object locally
        $existingNote = Entry::query()->whereIn('collection', ['notes', 'polls'])->where('activitypub_id', $objectId)->first();

        // Federated Check for existing object
        if ($existingNote && !$this->isFederated($existingNote->collection()->handle())) {
            Log::info("InboxHandler: Dropping Update for {$objectId} because collection is not federated.");
            return false;
        }

        // Allow if Connected OR Object exists OR it is a Profile Update for a Known Actor
        $isProfileUpdate = ($objectId === $actorId);
        $actorSaved = $externalActor && $externalActor->id() && Entry::find($externalActor->id());

        if ($isConnected || $existingNote || ($isProfileUpdate && $actorSaved)) {
            // Ensure actor is saved (persisted) if we are proceeding
            if ($externalActor && !$externalActor->id()) {
                try {
                    $externalActor->save();
                } catch (\Exception $e) {
                    // If save fails (e.g., slug conflict), try to find existing actor by activitypub_id
                    Log::warning("InboxHandler: Failed to save external actor, attempting to find existing: " . $e->getMessage());
                    $activityPubId = $externalActor->get('activitypub_id');
                    if ($activityPubId) {
                        $existingActor = Entry::query()
                            ->where('collection', 'actors')
                            ->where('activitypub_id', $activityPubId)
                            ->first();
                        if ($existingActor) {
                            $externalActor = $existingActor;
                            Log::info("InboxHandler: Found and using existing actor: " . $existingActor->id());
                        }
                    }
                }
            }

            // Verify actor is now persisted before proceeding
            if (!$externalActor || !$externalActor->id()) {
                Log::error("InboxHandler: Cannot process Update - external actor could not be persisted");
                return false;
            }

            // Proceed with update
            $this->handleUpdateActivity($object, $externalActor);
            return true;
        }

        Log::info("InboxHandler: Dropping stray Update from $actorId");
        return false;
    }

    protected function processDeleteActivity(array $payload, mixed $actor, mixed $externalActor): bool
    {
        $actorId = $payload['actor'] ?? 'Unknown';
        Log::info("InboxHandler: Processing Delete from $actorId");
        $object = $payload['object'] ?? null;
        $objectId = is_string($object) ? $object : ($object['id'] ?? null);

        if (!$objectId)
            return false;

        $following = $actor->get('following_actors', []) ?: [];
        $followedBy = $actor->get('followed_by_actors', []) ?: [];
        $isConnected = in_array($externalActor->id(), $following) || in_array($externalActor->id(), $followedBy);

        // Check if we have the object locally
        $existingEntry = $this->findLocalEntryByUrl($objectId);

        if ($existingEntry && !$this->isFederated($existingEntry->collection()->handle())) {
            Log::info("InboxHandler: Dropping Delete for {$objectId} because collection is not federated.");
            return false;
        }

        if ($existingEntry && !$this->isFederated($existingEntry->collection()->handle())) {
            Log::info("InboxHandler: Dropping Delete for {$objectId} because collection is not federated.");
            return false;
        }

        // STRAY RULE: If we are not connected AND the object is not in our system, DISCARD.
        if (!$isConnected && !$existingEntry) {
            Log::info("InboxHandler: Dropping stray Delete from $actorId");
            return false; // Do NOT save activity
        }

        if ($existingEntry) {
            // Verify ownership? Usually only author can delete.
            // But if it's external, we trust the activity signature? (already verified by middleware/controller before job?)
            // Assuming signatures are verified. 
            // Also check that the deleter is the author of the entry? 
            // $existingEntry->get('actor') is a ref to externalActor usually.

            $existingEntryActorId = $existingEntry->get('actor');
            if (is_array($existingEntryActorId))
                $existingEntryActorId = $existingEntryActorId[0] ?? null;

            if ($existingEntryActorId === $externalActor->id()) {
                $replyTo = $existingEntry->get('in_reply_to');
                $existingEntry->delete();

                if ($replyTo) {
                    ThreadService::decrement($replyTo);
                }

                Log::info("InboxHandler: Deleted local entry $objectId");

                // Also delete the Create activity?
                $createActivity = Entry::query()
                    ->where('collection', 'activities')
                    ->where('type', 'Create')
                    ->get()
                    ->first(function ($act) use ($objectId) {
                        $obj = $act->get('object');
                        if (is_array($obj))
                            return ($obj['id'] ?? null) === $objectId;
                        return $obj === $objectId;
                    });

                if ($createActivity) {
                    $createActivity->delete();
                }
            } else {
                Log::warning("InboxHandler: Delete requested by $actorId for object owned by someone else? Ignoring deletion but saving activity.");
                // Maybe we should allow it if it's a moderator action? 
                // For now, let's just stick to author deletion.
            }

            return true;
        }

        // If connected but object not found, usually we just log the delete activity for history?
        return true;
    }

    protected function handleLikeActivity(array $payload, string $actorId): void
    {
        $objectUrl = $payload['object'] ?? null;
        if (!$objectUrl)
            return;

        $entry = $this->findLocalEntryByUrl($objectUrl);

        if ($entry) {
            $likes = $entry->get('liked_by', []);
            if (!is_array($likes))
                $likes = [];

            if (!in_array($actorId, $likes)) {
                $likes[] = $actorId;
                $entry->set('liked_by', $likes);
                $entry->set('like_count', count($likes));
                $entry->save();
                Log::info("InboxHandler: Added like for $objectUrl by $actorId");
            } else {
                Log::info("InboxHandler: Already liked $objectUrl by $actorId");
            }
        } else {
            Log::warning("InboxHandler: Could not find local entry for Like: $objectUrl");
        }
    }

    protected function handleUndoLikeActivity(array $object, string $actorId): void
    {
        $targetUrl = $object['object'] ?? null;
        if (!$targetUrl)
            return;

        $entry = $this->findLocalEntryByUrl($targetUrl);

        if ($entry) {
            $likes = $entry->get('liked_by', []);
            if (!is_array($likes))
                $likes = [];

            if (in_array($actorId, $likes)) {
                $likes = array_values(array_diff($likes, [$actorId]));
                $entry->set('liked_by', $likes);
                $entry->set('like_count', count($likes));
                $entry->save();
                Log::info("InboxHandler: Removed like for $targetUrl by $actorId");
            }
        }
    }

    protected function handleAnnounceActivity(array $payload, mixed $localActor, mixed $boosterActor): void
    {
        $objectUrl = $payload['object'] ?? null;
        if (is_array($objectUrl))
            $objectUrl = $objectUrl['id'] ?? null;

        if (!$objectUrl || !is_string($objectUrl)) {
            Log::warning("InboxHandler: Invalid Announce object");
            return;
        }

        $announceId = $payload['id'] ?? null;

        // Deduplication: Check if we've already processed this Announce activity
        if ($announceId) {
            $exists = Entry::query()
                ->where('collection', 'activities')
                ->where('activitypub_id', $announceId)
                ->first();
            // Note: We might want allow reprocessing if we missed the update, but standard dedup usually blocks.
            // However, `saveActivity` at the end of handle() saves the activity.
            // If we rely on that for dedup, we might be fine. But let's check broadly.
            // Actually, the saving of the activity happens AFTER this method in `handle`.
            // So we should strictly check if the boost was *already applied* to the note?
            // Or just check if the activity entry exists (which means we processed it).
            if (Entry::query()->where('collection', 'activities')->where('activitypub_id', $announceId)->first()) {
                return;
            }
        }

        // Resolve Original Note
        $originalNote = Entry::query()
            ->whereIn('collection', ['notes', 'polls'])
            ->where('activitypub_id', $objectUrl)
            ->first();

        if (!$originalNote) {
            $baseUrl = \Statamic\Facades\Site::selected()->absoluteUrl();
            if (Str::startsWith($objectUrl, $baseUrl)) {
                $uri = str_replace($baseUrl, '', $objectUrl);
                $uri = '/' . ltrim($uri, '/');
                $localEntry = Entry::findByUri($uri, \Statamic\Facades\Site::selected()->handle());
                if ($localEntry)
                    $originalNote = $localEntry;
            }

            if (!$originalNote) {
                Log::info("InboxHandler: Fetching original note for boost: $objectUrl");
                $originalNote = $this->fetchAndCreateNote($objectUrl);
            }
        }

        if (!$originalNote) {
            Log::error("InboxHandler: Failed to resolve original note $objectUrl");
            return;
        }

        // UPDATE Logic
        $publishedStr = $payload['published'] ?? null;
        $publishedDate = $publishedStr ? \Illuminate\Support\Carbon::parse($publishedStr) : now();

        // Update updated_at/date to bump in timeline
        // Use Carbon to compare - only bump if newer? Usually boost is newer.
        $originalNote->date($publishedDate);
        $originalNote->set('date', $publishedDate->toIso8601String());

        // Add to boosted_by
        $boostedBy = $originalNote->get('boosted_by', []);
        if (!is_array($boostedBy))
            $boostedBy = [];

        $boosterId = $boosterActor->id();
        if (!in_array($boosterId, $boostedBy)) {
            $boostedBy[] = $boosterId;
            $originalNote->set('boosted_by', $boostedBy);
        }

        // dump("Saving Boosted Note: " . $originalNote->id() . " Boosters: " . json_encode($boostedBy));

        $res = $originalNote->save();

        // Update Boost Count
        $boostCount = count($boostedBy);
        $originalNote->set('boost_count', $boostCount);
        // Also update related activity count
        $this->updateRelatedActivityCount($originalNote);
        $originalNote->saveQuietly();
        // dump("Save Result: " . ($res ? 'OK' : 'FAIL'));

        Log::info("InboxHandler: Processed Boost for Note {$originalNote->id()} by {$boosterActor->get('title')}");
    }

    protected function handleCreateNote(array $object, mixed $authorActor): mixed
    {
        // Ensure author is saved
        if ($authorActor && !$authorActor->id()) {
            $authorActor->save();
        }
        return $this->createNoteEntry($object, $authorActor);
    }

    protected function createNoteEntry(array $object, mixed $authorActor): mixed
    {
        $id = $object['id'] ?? null;
        if ($id) {
            $existing = Entry::query()->where('collection', 'notes')->where('activitypub_id', $id)->first();
            if ($existing)
                return $existing;
        }

        $uuid = (string) Str::uuid();
        $content = $object['content'] ?? '';

        $dateStr = $object['published'] ?? $object['updated'] ?? null;
        $date = $dateStr ? \Illuminate\Support\Carbon::parse($dateStr) : now();
        $published = $date->toIso8601String(); // Keep string for data field if needed, but use object for Entry date()

        $inReplyTo = $object['inReplyTo'] ?? null;
        if (is_array($inReplyTo)) {
            $inReplyTo = $inReplyTo['id'] ?? $inReplyTo['url'] ?? $inReplyTo[0] ?? null;
        }

        // Ensure parent note exists if this is a reply
        if ($inReplyTo && is_string($inReplyTo)) {
            $parentNote = Entry::find($inReplyTo); // Try ID
            if (!$parentNote) {
                $parentNote = Entry::query()->where('collection', 'notes')->where('activitypub_id', $inReplyTo)->first(); // Try AP ID
            }

            // If still not found, try to resolve local URI
            if (!$parentNote && \Illuminate\Support\Str::startsWith($inReplyTo, \Statamic\Facades\Site::selected()->absoluteUrl())) {
                $uri = str_replace(\Statamic\Facades\Site::selected()->absoluteUrl(), '', $inReplyTo);
                $uri = '/' . ltrim($uri, '/');
                $parentNote = Entry::findByUri($uri, \Statamic\Facades\Site::selected()->handle());
            }

            // If absolutely not found, fetch it!
            if (!$parentNote) {
                // Prevent infinite recursion loops if the parent points back to this (unlikely but safe)
                if ($inReplyTo !== $object['id']) {
                    $this->fetchAndCreateNote($inReplyTo);
                }
            }
        }

        // processing quote
        $quoteOfId = null;
        $quoteUrl = $object['quoteUrl'] ?? $object['quote'] ?? $object['_misskey_quote'] ?? $object['quoteUri'] ?? null;
        if ($quoteUrl && is_string($quoteUrl)) {
            $origQuoteNote = null;
            $baseUrl = \Statamic\Facades\Site::selected()->absoluteUrl();
            if (Str::startsWith($quoteUrl, $baseUrl)) {
                $uri = str_replace($baseUrl, '', $quoteUrl);
                $uri = '/' . ltrim($uri, '/');
                $localEntry = Entry::findByUri($uri, \Statamic\Facades\Site::selected()->handle());
                if ($localEntry)
                    $origQuoteNote = $localEntry;
            }
            if (!$origQuoteNote) {
                $origQuoteNote = $this->fetchAndCreateNote($quoteUrl);
            }
            if ($origQuoteNote) {
                $quoteOfId = $origQuoteNote->id();
            }
        }

        $title = $object['name'] ?? strip_tags($content);

        $note = Entry::make()
            ->collection('notes')
            ->id($uuid)
            ->slug($uuid)
            ->date($date)
            ->data([
                'title' => $title,
                'content' => $content,
                'actor' => $authorActor->id(),
                'date' => $published,
                'activitypub_id' => $id,
                'activitypub_json' => json_encode($object),
                'is_internal' => false,
                'in_reply_to' => $inReplyTo,
                'quote_of' => null,
                'sensitive' => $object['sensitive'] ?? false,
                'summary' => (!empty($object['summary'])) ? $object['summary'] : (
                    ($object['sensitive'] ?? false) ? 'Sensitive Content' : null
                ),
            ]);

        // Parse Mentions
        $mentioned = [];
        if (isset($object['tag']) && is_array($object['tag'])) {
            foreach ($object['tag'] as $tag) {
                if (($tag['type'] ?? '') === 'Mention' && isset($tag['href'])) {
                    $mentioned[] = $tag['href'];
                }
            }
        }
        if (!empty($mentioned)) {
            $note->set('mentioned_urls', array_values(array_unique($mentioned)));
        }

        $note->set('title', $title);
        if ($quoteOfId) {
            $note->set('quote_of', [$quoteOfId]);
        }
        $note->save();

        if ($inReplyTo) {
            ThreadService::increment($inReplyTo);

            // Handle Poll Voting
            $this->handlePollVote($inReplyTo, $note, $authorActor, $title);
        }

        return $note;
    }

    protected function createPollEntry(array $object, mixed $authorActor): mixed
    {
        $id = $object['id'] ?? null;
        if ($id) {
            $existing = Entry::query()->where('collection', 'polls')->where('activitypub_id', $id)->first();
            if ($existing)
                return $existing;
        }

        $uuid = (string) Str::uuid();
        $content = $object['content'] ?? '';

        $dateStr = $object['published'] ?? $object['updated'] ?? null;
        $date = $dateStr ? \Illuminate\Support\Carbon::parse($dateStr) : now();
        $published = $date->toIso8601String();

        $endTimeStr = $object['endTime'] ?? null;
        $endTime = $endTimeStr ? \Illuminate\Support\Carbon::parse($endTimeStr) : null;

        $closed = $object['closed'] ?? null; // Usually a timestamp or boolean
        // If closed is a string formatted as date, verify if it's past? 
        // Or if endTime is past.
        $isClosed = false;
        if ($endTime && $endTime->isPast()) {
            $isClosed = true;
        }
        if ($closed) {
            $isClosed = true;
        }


        // Options parsing
        $options = [];
        $isMultipleChoice = false;

        if (isset($object['anyOf'])) {
            $isMultipleChoice = true;
            $oneOf = $object['anyOf'];
        } else {
            $oneOf = $object['oneOf'] ?? [];
        }

        foreach ($oneOf as $opt) {
            $name = $opt['name'] ?? 'Option';
            $replies = $opt['replies'] ?? [];
            $count = 0;
            if (is_array($replies)) {
                $count = $replies['totalItems'] ?? 0;
            }
            $options[] = [
                'name' => $name,
                'count' => $count
            ];
        }

        $poll = Entry::make()
            ->collection('polls')
            ->id($uuid)
            ->slug($uuid)
            ->date($date)
            ->data([
                'title' => strip_tags($content),
                'content' => $content,
                'actor' => $authorActor->id(),
                'date' => $published,
                'activitypub_id' => $id,
                'activitypub_json' => json_encode($object),
                'is_internal' => false,
                'sensitive' => $object['sensitive'] ?? false,
                'summary' => (!empty($object['summary'])) ? $object['summary'] : (
                    ($object['sensitive'] ?? false) ? 'Sensitive Content' : null
                ),
                'options' => $options,
                'multiple_choice' => $isMultipleChoice,
                'voters_count' => $object['votersCount'] ?? 0,
                'end_time' => $endTime ? $endTime->toIso8601String() : null,
                'closed' => $isClosed
            ]);

        // Parse Mentions (Polls)
        $mentioned = [];
        if (isset($object['tag']) && is_array($object['tag'])) {
            foreach ($object['tag'] as $tag) {
                if (($tag['type'] ?? '') === 'Mention' && isset($tag['href'])) {
                    $mentioned[] = $tag['href'];
                }
            }
        }
        if (!empty($mentioned)) {
            $poll->set('mentioned_urls', array_values(array_unique($mentioned)));
        }

        $poll->save();

        return $poll;
    }

    protected function handleUpdateActivity(array $object, mixed $externalActor): void
    {
        $id = $object['id'] ?? null;
        $type = $object['type'] ?? 'Note';

        if ($type === 'Person' || $type === 'Service') {
            if ($id !== $externalActor->get('activitypub_id')) {
                return;
            }
            $name = $object['name'] ?? $object['preferredUsername'] ?? '';
            $summary = $object['summary'] ?? '';

            if ($name)
                $externalActor->set('title', $name);
            if ($summary)
                $externalActor->set('content', $summary);
            $externalActor->save();
            return;
        }

        if (!$id)
            return;

        $note = Entry::query()->whereIn('collection', ['notes', 'polls'])->where('activitypub_id', $id)->first();

        if ($note) {
            $content = $object['content'] ?? $note->get('content');
            $note->set('content', $content);
            $note->set('activitypub_json', json_encode($object));
            $note->set('is_internal', false);
            if (isset($object['published'])) {
                try {
                    $date = \Illuminate\Support\Carbon::parse($object['published']);
                    $note->set('date', $date->toIso8601String());
                    $note->date($date); // Also update the Entry's date attribute for sorting
                } catch (\Exception $e) {
                    // Invalid date, ignore or keep existing
                }
            }

            if (isset($object['sensitive']))
                $note->set('sensitive', $object['sensitive']);

            $summary = $object['summary'] ?? $note->get('summary');

            // Check sensitive status (could have been updated just above)
            $isSensitive = $note->get('sensitive');

            if (empty($summary) && $isSensitive) {
                $summary = 'Sensitive Content';
            }
            $note->set('summary', $summary);

            // Re-link actor if needed (e.g. if note was orphaned or actor re-resolved)
            if ($externalActor && $externalActor->id()) {
                $currentActorId = $note->get('actor');
                if (is_array($currentActorId)) {
                    $currentActorId = $currentActorId[0] ?? null;
                }

                // Update actor if IDs don't match OR if current actor no longer exists
                $shouldUpdateActor = ($currentActorId !== $externalActor->id());
                if (!$shouldUpdateActor && $currentActorId) {
                    // Check if current actor still exists
                    $currentActor = Entry::find($currentActorId);
                    if (!$currentActor) {
                        $shouldUpdateActor = true;
                        Log::info("InboxHandler: Re-linking note to actor because previous actor no longer exists");
                    }
                }

                if ($shouldUpdateActor) {
                    $note->set('actor', $externalActor->id());
                }
            }

            // Poll specific updates
            if ($note->collection()->handle() === 'polls') {
                if (isset($object['votersCount'])) {
                    $note->set('voters_count', $object['votersCount']);
                }
                if (isset($object['endTime'])) {
                    $note->set('end_time', $object['endTime']);
                }
                // Update Options Counts
                $oneOf = $object['oneOf'] ?? $object['anyOf'] ?? [];
                if (!empty($oneOf)) {
                    $newOptions = [];
                    foreach ($oneOf as $opt) {
                        $name = $opt['name'] ?? 'Option';
                        $replies = $opt['replies'] ?? [];
                        $count = 0;
                        if (is_array($replies)) {
                            $count = $replies['totalItems'] ?? 0;
                        }
                        $newOptions[] = [
                            'name' => $name,
                            'count' => $count
                        ];
                    }
                    $note->set('options', $newOptions);
                }
            }

            // Update mentions if tags present
            if (isset($object['tag'])) {
                $mentioned = [];
                if (is_array($object['tag'])) {
                    foreach ($object['tag'] as $tag) {
                        if (($tag['type'] ?? '') === 'Mention' && isset($tag['href'])) {
                            $mentioned[] = $tag['href'];
                        }
                    }
                }
                if (!empty($mentioned)) {
                    $note->set('mentioned_urls', array_values(array_unique($mentioned)));
                } else {
                    $note->remove('mentioned_urls');
                }
            }

            $note->save();
            Log::info("InboxHandler: Updated Note $id");
            return;
        }

        // What if note not found?
        // We might need to fetch it?
        // But handle() only calls us if we found it or are connected.
    }


    protected function fetchAndCreateNote(string $url): mixed
    {
        try {
            Log::info("InboxHandler: Fetching node from $url");
            $response = Http::withHeaders(['Accept' => 'application/activity+json, application/ld+json'])->get($url);

            if (!$response->successful()) {
                Log::error("InboxHandler: Failed to fetch note from $url. Status: " . $response->status());
                return null;
            }

            $object = $response->json();

            $type = $object['type'] ?? '';
            if ($type !== 'Note' && $type !== 'Article')
                return null;

            $attributedTo = $object['attributedTo'] ?? null;
            if (is_array($attributedTo))
                $attributedTo = $attributedTo[0] ?? null;
            if (!$attributedTo)
                return null;

            $authorActor = $this->resolveExternalActor($attributedTo);
            if (!$authorActor)
                return null;

            return $this->createNoteEntry($object, $authorActor);

        } catch (\Exception $e) {
            Log::error("InboxHandler: Exception fetching note $url: " . $e->getMessage());
            return null;
        }
    }

    protected function resolveExternalActor(string $actorUrl): mixed
    {
        $existing = Entry::query()->where('collection', 'actors')->where('activitypub_id', $actorUrl)->first();
        if ($existing)
            return $existing;

        try {
            $response = null;
            try {
                $response = Http::withHeaders(['Accept' => 'application/activity+json, application/ld+json'])->get($actorUrl);
            } catch (\Exception $e) {
                // Fallback for localhost etc if needed (simplified from controller)
                if (app()->environment('local', 'dev', 'testing') && str_contains($actorUrl, 'localhost')) {
                    $fallbackUrl = str_replace('https://', 'http://', $actorUrl);
                    $response = Http::withOptions(['verify' => false])->withHeaders(['Accept' => 'application/activity+json'])->get($fallbackUrl);
                } else {
                    throw $e;
                }
            }

            if (!$response || !$response->successful())
                return null;

            $data = $response->json();
            $username = $data['preferredUsername'] ?? $data['name'] ?? 'unknown';

            // Match against canonical ID from JSON
            $canonicalId = $data['id'] ?? $actorUrl;
            if ($canonicalId !== $actorUrl) {
                $existingCanonical = Entry::query()
                    ->where('collection', 'actors')
                    ->where('activitypub_id', $canonicalId)
                    ->first();
                if ($existingCanonical) {
                    return $existingCanonical;
                }
            }

            $host = parse_url($actorUrl, PHP_URL_HOST);
            $safeHost = str_replace('.', '-dot-', $host);
            $slug = Str::slug($username) . '-at-' . $safeHost;

            $entry = Entry::make()
                ->collection('actors')
                ->slug($slug)
                ->data([
                    'title' => $data['name'] ?? $username,
                    'username' => $username,
                    'content' => $data['summary'] ?? '',
                    'activitypub_id' => $data['id'] ?? $actorUrl,
                    'inbox_url' => $data['inbox'] ?? null,
                    'outbox_url' => $data['outbox'] ?? null,
                    'shared_inbox_url' => $data['endpoints']['sharedInbox'] ?? null,
                    'public_key' => $data['publicKey']['publicKeyPem'] ?? null,
                    'is_internal' => false,
                    'avatar' => $this->downloadAvatar($data['icon'] ?? null),
                ]);
            $entry->save();
            return $entry;

        } catch (\Exception $e) {
            Log::error('InboxHandler: Failed to resolve actor: ' . $e->getMessage());
            return null;
        }
    }

    protected function downloadAvatar(mixed $iconData): ?string
    {
        if (!$iconData)
            return null;
        $url = is_array($iconData) ? ($iconData['url'] ?? null) : $iconData;
        if (!$url)
            return null;

        try {
            $response = Http::timeout(10)->get($url);
            if (!$response->successful())
                return null;

            $contentType = $response->header('Content-Type');
            if (!str_starts_with($contentType, 'image/'))
                return null;

            $contents = $response->body();
            if (strlen($contents) < 50)
                return null;

            $name = md5($url);
            $extension = 'jpg'; // simplified extension logic

            $filename = "avatars/{$name}.{$extension}";
            Storage::disk('assets')->put($filename, $contents);
            return $filename;
        } catch (\Exception $e) {
            return null;
        }
    }



    protected function sendAcceptActivity(mixed $localActor, mixed $followActivity, mixed $remoteActor): void
    {
        $inbox = $remoteActor->get('inbox_url');
        if (!$inbox)
            return;

        $localActorId = $this->sanitizeUrl(url('@' . $localActor->slug()));
        $guid = Str::uuid();

        $activity = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $localActorId . '#accept-' . $guid,
            'type' => 'Accept',
            'actor' => $localActorId,
            'object' => $followActivity,
        ];

        $jsonBody = json_encode($activity);
        $privateKey = $localActor->get('private_key');
        if (!$privateKey)
            return;

        $headers = HttpSignature::sign($inbox, $localActorId, $privateKey, $jsonBody);
        if (empty($headers))
            return;

        try {
            Http::withHeaders($headers)
                ->withBody($jsonBody, 'application/activity+json')
                ->post($inbox);
            Log::info("InboxHandler: Sent Accept activity to $inbox");
        } catch (\Exception $e) {
            Log::error("InboxHandler: Failed to send Accept activity: " . $e->getMessage());
        }
    }

    protected function sanitizeUrl(string $url): string
    {
        return str_replace('://www.', '://', $url);
    }

    protected function saveActivity(array $payload, mixed $actorEntry): void
    {
        $id = $payload['id'] ?? null;
        if (!$id)
            return;

        $type = $payload['type'];
        $slug = md5($id);

        if (Entry::query()->where('collection', 'activities')->where('slug', $slug)->first())
            return;

        $dateStr = $payload['published'] ?? $payload['updated'] ?? $payload['object']['published'] ?? null;
        $date = $dateStr ? \Illuminate\Support\Carbon::parse($dateStr) : now();

        $entry = Entry::make()
            ->collection('activities')
            ->slug($slug)
            ->date($date)
            ->data([
                'title' => $type . ' ' . $id,
                'activitypub_id' => $id,
                'type' => $type,
                'actor' => $actorEntry->id(),
                'object' => $payload['object'] ?? null,
                'content' => $payload['content'] ?? null,
                'activitypub_json' => json_encode($payload),
                'is_internal' => false,
                'date' => $date,
            ]);

        $entry->save();

        if ($type !== 'Create' && $type !== 'Update' && $type !== 'Delete') {
            // For interactions like Like/Announce/etc, update related count
            $object = $payload['object'] ?? null;
            if ($object) {
                $objectId = is_array($object) ? ($object['id'] ?? null) : $object;
                if ($objectId) {
                    $note = $this->findLocalEntryByUrl($objectId);
                    if ($note) {
                        $this->updateRelatedActivityCount($note);
                    }
                }
            }
        }
    }

    protected function findLocalEntryByUrl(string $url): mixed
    {
        $entry = Entry::find($url);
        if (!$entry) {
            $entry = Entry::query()->whereIn('collection', ['notes', 'polls'])->where('activitypub_id', $url)->first();
        }
        if (!$entry) {
            // Check absolute URL match
            $baseUrl = \Statamic\Facades\Site::selected()->absoluteUrl();
            if (Str::startsWith($url, $baseUrl)) {
                $uri = str_replace($baseUrl, '', $url);
                $uri = '/' . ltrim($uri, '/');
                $entry = Entry::findByUri($uri, \Statamic\Facades\Site::selected()->handle());
            }
        }
        return $entry;
    }

    protected function updateRelatedActivityCount(mixed $note): void
    {
        if (!$note)
            return;

        // Count all activities where object is this note
        $ids = array_values(array_filter([$note->id(), $note->get('activitypub_id'), $note->absoluteUrl()]));

        $count = Entry::query()
            ->where('collection', 'activities')
            ->get()
            ->filter(function ($act) use ($ids) {
                $obj = $act->get('object');
                if (is_array($obj))
                    $obj = $obj['id'] ?? $obj[0] ?? null;
                return in_array($obj, $ids);
            })
            ->count();

        if ($note->get('related_activity_count') !== $count) {
            $note->set('related_activity_count', $count);
            $note->saveQuietly();
        }
    }
    protected function handlePollVote(string $pollId, mixed $voteNote, mixed $actor, ?string $voteValue = null): void
    {
        // 1. Find the Poll/Question
        $poll = Entry::find($pollId);
        if (!$poll) {
            $poll = Entry::query()->where('collection', 'polls')->where('activitypub_id', $pollId)->first();
        }

        // Also try local URI if internal
        if (!$poll && is_string($pollId) && \Illuminate\Support\Str::startsWith($pollId, \Statamic\Facades\Site::selected()->absoluteUrl())) {
            $uri = str_replace(\Statamic\Facades\Site::selected()->absoluteUrl(), '', $pollId);
            $uri = '/' . ltrim($uri, '/');
            $poll = Entry::findByUri($uri, \Statamic\Facades\Site::selected()->handle());
        }

        if (!$poll || $poll->collection()->handle() !== 'polls') {
            return;
        }

        // 2. Check if the vote matches an option
        // Use passed value or fallback to title
        if (!$voteValue) {
            $voteValue = $voteNote->get('title');
        }

        if (!$voteValue)
            return;

        $options = $poll->get('options', []);
        $updated = false;

        $newOptions = collect($options)->map(function ($opt) use ($voteValue, &$updated) {
            // Case-insensitive match (UTF-8 safe)
            if (mb_strtolower(trim($opt['name'])) === mb_strtolower(trim($voteValue))) {
                $count = (int) ($opt['count'] ?? 0);
                $opt['count'] = $count + 1;
                $updated = true;
            }
            return $opt;
        })->all();

        if ($updated) {
            $poll->set('options', $newOptions);
            $poll->save();
            Log::info("ActivityPub: Poll vote recorded for poll {$poll->id()}. Option: '{$voteValue}'");
        } else {
            Log::warning("ActivityPub: Poll vote received for {$poll->id()} but no matching option found for value '{$voteValue}'. Options: " . collect($options)->pluck('name')->implode(', '));
        }
    }

    protected function isFederated(string $handle): bool
    {
        $path = resource_path('settings/activitypub.yaml');
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
}
