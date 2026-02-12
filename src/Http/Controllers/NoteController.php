<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Http\Controllers;

use Statamic\Facades\Entry;
use Ethernick\ActivityPubCore\Services\ThreadService;
use Ethernick\ActivityPubCore\Services\ActorResolver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

use Ethernick\ActivityPubCore\Contracts\ActivityHandlerInterface;

class NoteController extends BaseObjectController implements ActivityHandlerInterface
{
    protected static array $handledActivityTypes = [
        'Create:Note',
        'Update:Note',
        'Delete:Note',
    ];

    // --- ActivityPub Handlers ---

    public function handleCreate(array $payload, mixed $localActor, mixed $externalActor): bool
    {
        Log::info("NoteController: Handling Create Activity");
        $object = $payload['object'] ?? null;
        if (!$object)
            return false;

        // Stray Check: Only accept if Following, Mentioned, or Reply to Known
        // Note: For ephemeral actors (id() is null), we need to check by activitypub_id
        $following = $localActor->get('following_actors', []) ?: [];

        $to = $object['to'] ?? [];
        $cc = $object['cc'] ?? [];
        if (!is_array($to))
            $to = [$to];
        if (!is_array($cc))
            $cc = [$cc];
        $addressed = array_merge($to, $cc);

        $myApId = $localActor->get('activitypub_id') ?: $localActor->absoluteUrl();
        $isMentioned = in_array($myApId, $addressed);

        $inReplyTo = $object['inReplyTo'] ?? null;
        $isReplyToKnown = false;

        if ($inReplyTo) {
            if (is_array($inReplyTo))
                $inReplyTo = $inReplyTo['id'] ?? $inReplyTo['url'] ?? $inReplyTo[0] ?? null;

            if (is_string($inReplyTo)) {
                $isReplyToKnown = Entry::query()->where('collection', 'notes')->where('activitypub_id', $inReplyTo)->exists()
                    || Entry::find($inReplyTo);
            }
        }

        // Check if we should accept this activity
        $isFollowing = $externalActor && $externalActor->id() && in_array($externalActor->id(), $following);

        if ($isFollowing || $isMentioned || $isReplyToKnown) {
            // Only save the author after we've determined the activity is not stray
            if ($externalActor && !$externalActor->id()) {
                $externalActor->save();
            }

            $this->createNoteEntry($object, $externalActor);
            return true;
        } else {
            Log::info("NoteController: Ignoring Create Note from non-followed/irrelevant actor");
            return false;
        }
    }

    public function handleUpdate(array $payload, mixed $localActor, mixed $externalActor): bool
    {
        Log::info("NoteController: Handling Update Activity");
        $object = $payload['object'] ?? null;
        if (!$object)
            return false;

        $id = $object['id'] ?? null;
        if (!$id)
            return false;

        $existingNote = Entry::query()->whereIn('collection', ['notes', 'polls'])->where('activitypub_id', $id)->first();

        if (!$existingNote) {
            Log::info("NoteController: Update target note not found: $id");
            return false;
        }

        // Ensure external actor is persisted before verification
        // This handles cases where the actor is ephemeral (not yet saved)
        if ($externalActor && !$externalActor->id()) {
            try {
                $externalActor->save();
            } catch (\Exception $e) {
                // If save fails (e.g., slug conflict), try to find existing actor by activitypub_id
                Log::warning("NoteController: Failed to save external actor, attempting to find existing: " . $e->getMessage());
                $activityPubId = $externalActor->get('activitypub_id');
                if ($activityPubId) {
                    $existingActor = Entry::query()
                        ->where('collection', 'actors')
                        ->where('activitypub_id', $activityPubId)
                        ->first();
                    if ($existingActor) {
                        $externalActor = $existingActor;
                        Log::info("NoteController: Found and using existing actor: " . $existingActor->id());
                    } else {
                        // Still no ID, cannot proceed
                        Log::error("NoteController: Cannot save or find external actor, rejecting update");
                        return false;
                    }
                }
            }
        }

        // Security: Verify the external actor is the original author of the note
        $existingActorId = $existingNote->get('actor');
        if (is_array($existingActorId)) {
            $existingActorId = $existingActorId[0] ?? null;
        }

        // First check: Do the entry IDs match?
        if ($existingActorId === $externalActor->id()) {
            // Perfect match, proceed
        } else {
            // Second check: Verify by ActivityPub ID (handles cases where actor was deleted/recreated)
            $existingActor = Entry::find($existingActorId);

            // If the existing actor is missing/deleted, we need to verify via the note's metadata
            // In this case, we'll allow the update if we can't verify (orphaned note scenario)
            if (!$existingActor) {
                Log::info("NoteController: Original actor missing/deleted, allowing update from actor with AP ID: " . $externalActor->get('activitypub_id'));
                // Update the note's actor reference to point to the current actor
                $existingNote->set('actor', $externalActor->id());
            } else {
                // Actor exists, verify by ActivityPub ID
                $existingActorApId = $existingActor->get('activitypub_id');
                $externalActorApId = $externalActor->get('activitypub_id');

                if (!$existingActorApId || !$externalActorApId || $existingActorApId !== $externalActorApId) {
                    Log::warning("NoteController: Update rejected - actor mismatch. Note actor: $existingActorId (AP: $existingActorApId), Request actor: " . $externalActor->id() . " (AP: $externalActorApId)");
                    return false;
                }

                Log::info("NoteController: Actor verified by ActivityPub ID match despite entry ID mismatch");
            }
        }

        $this->updateNoteEntry($object, $externalActor);
        Log::info("NoteController: Updated note $id");
        return true;
    }

    public function handleDelete(array $payload, mixed $localActor, mixed $externalActor): bool
    {
        Log::info("NoteController: Handling Delete Activity");
        $object = $payload['object'] ?? null;
        $objectId = is_string($object) ? $object : ($object['id'] ?? null);

        if (!$objectId)
            return false;

        $existingEntry = Entry::query()->whereIn('collection', ['notes', 'polls'])->where('activitypub_id', $objectId)->first();

        if ($existingEntry) {
            $existingEntryActorId = $existingEntry->get('actor');
            if (is_array($existingEntryActorId))
                $existingEntryActorId = $existingEntryActorId[0] ?? null;

            if ($existingEntryActorId === $externalActor->id()) { // Verify ownership
                $replyTo = $existingEntry->get('in_reply_to');
                $existingEntry->delete();

                if ($replyTo) {
                    ThreadService::decrement($replyTo);
                }
                Log::info("NoteController: Deleted note $objectId");
                return true;
            }
        }
        return false;
    }

    // --- Internal Helpers ---

    protected function createNoteEntry(array $object, \Statamic\Contracts\Entries\Entry $authorActor): ?\Statamic\Contracts\Entries\Entry
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
        $published = $date->toIso8601String();

        // Resolve Reply URL
        // Resolve Reply URL
        $replyUrl = null;
        $inReplyTo = null; // Initialize default
        if (isset($object['inReplyTo'])) {
            $inReplyTo = $object['inReplyTo'];

            if (is_string($inReplyTo)) {
                $replyUrl = $inReplyTo;
            } elseif (is_array($inReplyTo)) {
                $replyUrl = $inReplyTo['id'] ?? $inReplyTo['url'] ?? $inReplyTo[0] ?? null;
            }
        }

        if ($replyUrl && is_string($replyUrl)) {
            $parentNote = Entry::find($replyUrl); // Try ID
            if (!$parentNote) {
                // Also check polls as parents
                $parentNote = Entry::query()->whereIn('collection', ['notes', 'polls'])->where('activitypub_id', $replyUrl)->first();
            }

            /** @var string|null $replyUrl */
            if (!$parentNote && \Illuminate\Support\Str::startsWith($replyUrl, \Statamic\Facades\Site::selected()->absoluteUrl())) {
                $uri = str_replace(\Statamic\Facades\Site::selected()->absoluteUrl(), '', $replyUrl);
                $uri = '/' . ltrim($uri, '/');
                $parentNote = Entry::findByUri($uri, \Statamic\Facades\Site::selected()->handle());
            }

            // Fetch if missing
            if (!$parentNote) {
                if ($replyUrl !== $id) { // Prevent recursion
                    $parentNote = $this->fetchAndCreateNote($replyUrl);
                }
            }
        }

        // Quote Logic
        $quoteOfId = null;
        $quoteUrl = $object['quoteUrl'] ?? $object['quote'] ?? $object['_misskey_quote'] ?? $object['quoteUri'] ?? null;
        if ($quoteUrl && is_string($quoteUrl)) {
            $origQuoteNote = $this->findLocalEntryByUrl($quoteUrl);
            if (!$origQuoteNote) {
                $origQuoteNote = $this->fetchAndCreateNote($quoteUrl);
            }
            if ($origQuoteNote) {
                // Store ID as string, wrap it later if needed (Statamic relationship expects array, but let's be consistent)
                $quoteOfId = $origQuoteNote->id();
            }
        }

        $title = $object['name'] ?? strip_tags($content);

        // Sensitive / Summary Defaults
        $summary = $object['summary'] ?? null;
        $sensitive = $object['sensitive'] ?? false;
        if (empty($summary) && $sensitive) {
            $summary = 'Sensitive Content';
        }

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
                'quote_of' => $quoteOfId ? [$quoteOfId] : null,
                'sensitive' => $sensitive,
                'summary' => $summary,
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
        $note->save();

        if ($inReplyTo) {
            ThreadService::increment($inReplyTo);
            // Handle Poll Vote if parent is Poll
            // $this->handlePollVote($inReplyTo, $note, $authorActor, $title);
        }

        return $note;
    }

    protected function fetchAndCreateNote(string $url): ?\Statamic\Contracts\Entries\Entry
    {
        try {
            Log::info("NoteController: Fetching note from $url");
            $response = Http::withHeaders(['Accept' => 'application/activity+json, application/ld+json'])->get($url);

            if (!$response->successful()) {
                Log::error("NoteController: Failed to fetch note from $url. Status: " . $response->status());
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

            $resolver = new ActorResolver();
            $authorActor = $resolver->resolve($attributedTo, true);
            if (!$authorActor)
                return null;

            return $this->createNoteEntry($object, $authorActor);

        } catch (\Exception $e) {
            Log::error("NoteController: Exception fetching note $url: " . $e->getMessage());
            return null;
        }
    }

    protected function updateNoteEntry(array $object, mixed $authorActor): mixed
    {
        $id = $object['id'] ?? null;
        if (!$id)
            return false;

        $entry = Entry::query()->where('collection', 'notes')->where('activitypub_id', $id)->first();
        if (!$entry)
            return false;

        if (isset($object['content'])) {
            $content = $object['content'];
            $entry->set('content', $content);
        }

        $title = $object['name'] ?? null;
        if ($title) {
            $entry->set('title', $title);
        } elseif (isset($content)) {
            // If content changed but no title, maybe update title from content? 
            // Existing code: $title = $object['name'] ?? strip_tags($content);
            $entry->set('title', strip_tags($content));
        }

        if (array_key_exists('summary', $object)) {
            $summary = $object['summary'];
            $entry->set('summary', $summary);
        }

        if (isset($object['sensitive'])) {
            $sensitive = $object['sensitive'];
            $entry->set('sensitive', $sensitive);
            // Re-evaluate sensitive summary default
            if (empty($entry->get('summary')) && $sensitive) {
                $entry->set('summary', 'Sensitive Content');
            }
        }

        $entry->set('activitypub_json', json_encode($object));

        // Mentions update
        $mentioned = [];
        if (isset($object['tag']) && is_array($object['tag'])) {
            foreach ($object['tag'] as $tag) {
                if (($tag['type'] ?? '') === 'Mention' && isset($tag['href'])) {
                    $mentioned[] = $tag['href'];
                }
            }
        }
        if (!empty($mentioned)) {
            $entry->set('mentioned_urls', array_values(array_unique($mentioned)));
        } else {
            $entry->remove('mentioned_urls');
        }

        $entry->save();
        return $entry;
    }
}
