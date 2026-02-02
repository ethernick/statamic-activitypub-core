<?php

namespace Ethernick\ActivityPubCore\Http\Controllers\CP;

use Statamic\Http\Controllers\CP\CpController;
use Statamic\Facades\Entry;
use Statamic\Facades\Asset;
use Statamic\Facades\User;
use Statamic\Facades\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Ethernick\ActivityPubCore\Services\ThreadService;
use Ethernick\ActivityPubCore\Services\LinkPreview;
use Statamic\Facades\Collection;

class InboxController extends CpController
{
    protected $actorCache = [];

    protected $boostsMap = [];

    protected $voteCache = [];

    protected $quoteCache = [];

    /**
     * Preload all actors, quotes, and vote data for a batch of entries.
     * This eliminates N+1 queries in the transform loop.
     */
    protected function preloadBatchData(array $items, array $userActors): void
    {
        // Collect all actor IDs, quote IDs, and poll data needed
        $actorIds = [];
        $quoteIds = [];
        $pollIds = [];
        $pollUrls = [];

        foreach ($items as $item) {
            $collection = $item->collection()->handle();

            if (in_array($collection, ['notes', 'polls'])) {
                // Collect actor ID
                $actorId = $item->get('actor');
                if (is_array($actorId)) {
                    $actorId = $actorId[0] ?? null;
                }
                if ($actorId && is_string($actorId)) {
                    $actorIds[$actorId] = true;
                }

                // Collect booster IDs
                $boostedBy = $item->get('boosted_by', []);
                if (is_array($boostedBy)) {
                    foreach ($boostedBy as $boosterId) {
                        if ($boosterId && is_string($boosterId)) {
                            $actorIds[$boosterId] = true;
                        }
                    }
                }

                // Collect quote IDs
                $quoteOf = $item->get('quote_of');
                // Unwrap nested arrays (handle malformed data)
                while (is_array($quoteOf)) {
                    $quoteOf = $quoteOf[0] ?? null;
                }
                if ($quoteOf && is_string($quoteOf)) {
                    $quoteIds[$quoteOf] = true;
                }

                // Collect poll data for vote checks
                if ($collection === 'polls') {
                    $pollIds[] = $item->id();
                    $pollUrl = $item->get('activitypub_id');
                    if ($pollUrl) {
                        $pollUrls[$item->id()] = $pollUrl;
                    }
                }

                // Collect parent actor IDs (for in_reply_to)
                $parentId = $item->get('in_reply_to');
                if ($parentId) {
                    // We'll handle parent loading separately as it requires entry lookup
                }
            } elseif ($collection === 'activities') {
                $actorId = $item->get('actor');
                if (is_array($actorId)) {
                    $actorId = $actorId[0] ?? null;
                }
                if ($actorId && is_string($actorId)) {
                    $actorIds[$actorId] = true;
                }
            }
        }

        // Batch load all actors
        $actorIds = array_keys($actorIds);
        if (!empty($actorIds)) {
            $actors = Entry::query()
                ->where('collection', 'actors')
                ->whereIn('id', $actorIds)
                ->get();

            foreach ($actors as $actor) {
                $this->actorCache[$actor->id()] = $this->buildActorData($actor);
            }
        }

        // Batch load all quotes and their actors
        $quoteIds = array_keys($quoteIds);
        if (!empty($quoteIds)) {
            $quotes = Entry::query()
                ->whereIn('collection', ['notes', 'polls'])
                ->whereIn('id', $quoteIds)
                ->get();

            $quoteActorIds = [];
            foreach ($quotes as $quote) {
                $actorId = $quote->get('actor');
                if (is_array($actorId)) {
                    $actorId = $actorId[0] ?? null;
                }
                if ($actorId && is_string($actorId) && !isset($this->actorCache[$actorId])) {
                    $quoteActorIds[$actorId] = true;
                }
            }

            // Load quote actors that weren't already loaded
            $quoteActorIds = array_keys($quoteActorIds);
            if (!empty($quoteActorIds)) {
                $quoteActors = Entry::query()
                    ->where('collection', 'actors')
                    ->whereIn('id', $quoteActorIds)
                    ->get();

                foreach ($quoteActors as $actor) {
                    $this->actorCache[$actor->id()] = $this->buildActorData($actor);
                }
            }

            // Build quote cache
            foreach ($quotes as $quote) {
                $this->quoteCache[$quote->id()] = $quote;
            }
        }

        // Batch load vote data for polls
        if (!empty($pollIds) && !empty($userActors)) {
            $allPollIdentifiers = [];
            foreach ($pollIds as $pollId) {
                $allPollIdentifiers[] = (string) $pollId;
                if (isset($pollUrls[$pollId])) {
                    $allPollIdentifiers[] = (string) $pollUrls[$pollId];
                }
            }

            $votes = Entry::query()
                ->where('collection', 'notes')
                ->whereIn('in_reply_to', $allPollIdentifiers)
                ->whereIn('actor', $userActors)
                ->get();

            // Build vote cache keyed by poll ID
            foreach ($pollIds as $pollId) {
                $pollIdentifiers = [(string) $pollId];
                if (isset($pollUrls[$pollId])) {
                    $pollIdentifiers[] = (string) $pollUrls[$pollId];
                }

                $pollVotes = $votes->filter(function ($vote) use ($pollIdentifiers) {
                    $inReplyTo = $vote->get('in_reply_to');
                    return in_array($inReplyTo, $pollIdentifiers);
                });

                $this->voteCache[$pollId] = [
                    'has_voted' => $pollVotes->isNotEmpty(),
                    'voted_options' => $pollVotes->map(fn($v) => $v->get('content'))->unique()->values()->all(),
                ];
            }
        }
    }

    /**
     * Build actor data array from an actor entry.
     */
    protected function buildActorData($actorEntry): array
    {
        $name = $actorEntry->get('title');
        $slug = $actorEntry->slug();
        $handle = $slug;

        if ($actorEntry->get('is_internal')) {
            $handle = $slug . '@' . request()->getHost();
        } else {
            $handle = str_replace(['-at-', '-dot-'], ['@', '.'], $slug);
        }

        $avatarId = $actorEntry->get('avatar');
        $avatar = null;
        if ($avatarId) {
            if (is_string($avatarId) && str_starts_with($avatarId, 'http')) {
                $avatar = $avatarId;
            } else {
                $asset = Asset::find($avatarId);
                if ($asset) {
                    $avatar = $asset->url();
                }
            }
        }

        return [
            'name' => $name,
            'handle' => str_starts_with($handle, '@') ? $handle : '@' . $handle,
            'avatar' => $avatar ?? 'https://www.gravatar.com/avatar/' . md5($handle) . '?d=mp',
            'url' => $actorEntry->absoluteUrl(),
        ];
    }

    /**
     * Batch enrich notes with link previews and oembed data.
     * This endpoint fetches external metadata asynchronously after page load.
     */
    public function batchEnrichment(Request $request)
    {
        $request->validate([
            'note_ids' => 'required|array',
            'note_ids.*' => 'string'
        ]);

        $ids = $request->input('note_ids');
        $enrichments = [];

        foreach ($ids as $id) {
            $note = Entry::find($id);
            if (!$note) {
                continue;
            }

            $enrichment = [];
            $needsSave = false;

            // Parse content once for both checks
            $content = $note->get('content');

            // Check if content is already HTML (external notes) or Markdown (internal notes)
            // External notes from ActivityPub are stored as HTML
            $isInternal = $note->get('is_internal', false);
            $htmlContent = $isInternal
                ? \Statamic\Facades\Markdown::parse((string) $content)
                : (string) $content;

            // 1. OEmbed enrichment (rich embeds like YouTube, Twitter, etc.)
            $existingOembed = $note->get('oembed_data');
            $hasOembed = false;

            if ($existingOembed !== null) {
                // Already processed
                if ($existingOembed !== false) {
                    // Has real oembed data
                    $enrichment['oembed'] = $existingOembed;
                    $hasOembed = true;
                }
                // If false, we already tried and failed - don't return null, just skip
            } else {
                // Not yet processed - try to fetch
                $oembedData = \Ethernick\ActivityPubCore\Services\OEmbed::resolve($htmlContent);

                if ($oembedData) {
                    $note->set('oembed_data', $oembedData);
                    $enrichment['oembed'] = $oembedData;
                    $hasOembed = true;
                    $needsSave = true;
                } else {
                    // Store false to indicate "no oembed found" (prevents re-checking)
                    $note->set('oembed_data', false);
                    $needsSave = true;
                }
            }

            // 2. Link Preview enrichment (OpenGraph/Twitter cards)
            // ONLY return link preview if no OEmbed was found
            if (!$hasOembed) {
                $existingPreview = $note->get('link_preview');
                if ($existingPreview && !empty($existingPreview)) {
                    // Already has link preview
                    $enrichment['link_preview'] = $existingPreview;
                } else {
                    // Try to fetch link preview
                    if ($url = LinkPreview::extractUrl($htmlContent)) {
                        $previewData = LinkPreview::fetch($url);

                        if ($previewData) {
                            $note->set('link_preview', $previewData);
                            $enrichment['link_preview'] = $previewData;
                            $needsSave = true;
                        }
                    }
                }
            }

            // Save once if any changes
            if ($needsSave) {
                $note->saveQuietly();
            }

            // Return enrichment data for this note
            if (!empty($enrichment)) {
                $enrichments[$id] = $enrichment;
            }
        }

        return response()->json(['data' => $enrichments]);
    }

    /**
     * Legacy endpoint for backwards compatibility.
     * Redirects to batchEnrichment.
     *
     * @deprecated Use batchEnrichment instead
     */
    public function batchLinkPreview(Request $request)
    {
        return $this->batchEnrichment($request);
    }

    public function index()
    {
        $actors = Entry::query()
            ->where('collection', 'actors')
            ->where('is_internal', true)
            ->get()
            ->map(function ($actor) {
                return [
                    'id' => $actor->id(),
                    'name' => $actor->get('title'),
                    'handle' => $actor->slug() . '@' . request()->getHost(), // Simple handle formatting
                    'avatar' => $actor->get('avatar')
                ];
            });

        return view('activitypub::inbox', [
            'title' => 'Inbox',
            'localActors' => $actors,
            // 'createNoteUrl' => cp_route('collections.entries.create', ['collection' => 'notes', 'site' => \Statamic\Facades\Site::selected()->handle()])
            // Use long form route name to be safe if cp_route helper shorthands vary, though cp_route usually takes route name.
            // Actually cp_route takes the route name.
            'createNoteUrl' => cp_route('collections.entries.create', ['collection' => 'notes', 'site' => \Statamic\Facades\Site::selected()->handle()]),
            'storePollUrl' => cp_route('activitypub.inbox.store-poll'),
        ]);
    }

    public function reply(Request $request)
    {
        $request->validate([
            'content' => 'required|string',
            'actor' => 'required|string',
            'in_reply_to' => 'required|string',
        ]);

        $actor = Entry::find($request->input('actor'));
        if (!$actor) {
            return response()->json(['error' => 'Actor not found'], 404);
        }

        $entry = Entry::make()
            ->collection('notes')
            ->published(true)
            ->data([
                'content' => $request->input('content'),
                'actor' => [$actor->id()],
                'in_reply_to' => $request->input('in_reply_to'),
                'date' => now()->format('Y-m-d H:i'),
                'sensitive' => $request->filled('content_warning'),
                'summary' => $request->input('content_warning'),
            ]);

        $entry->save();

        return response()->json(['success' => true, 'message' => 'Reply posted'], 201);
    }

    /**
     * Store a new note from the CP compose form.
     */
    public function storeNote(Request $request)
    {
        $request->validate([
            'content' => 'required|string',
            'actor' => 'required|string',
            'content_warning' => 'nullable|string',
            'quote_of' => 'nullable|string',
            //'quote_of.*' => 'string',
        ]);

        $actor = Entry::find($request->input('actor'));
        if (!$actor) {
            return response()->json(['error' => 'Actor not found'], 404);
        }

        $quoteOf = $request->input('quote_of');

        $entry = Entry::make()
            ->collection('notes')
            ->published(true)
            ->data([
                'content' => $request->input('content'),
                'actor' => [$actor->id()],
                'date' => now()->format('Y-m-d H:i'),
                'sensitive' => $request->filled('content_warning'),
                'summary' => $request->input('content_warning'),
                'quote_of' => $quoteOf ? [$quoteOf] : null,
                'is_internal' => true,
                'quote_authorization_status' => $quoteOf ? 'pending' : null,
            ]);

        $entry->save();

        // Note: SendQuoteRequest is automatically dispatched by ActivityPubListener
        // when it detects a quote_of field, so no manual dispatch needed here

        return response()->json(['success' => true, 'message' => 'Note created', 'id' => $entry->id()]);
    }

    /**
     * Store a new poll from the CP compose form.
     */
    public function storePoll(Request $request)
    {
        $request->validate([
            'content' => 'required|string',
            'actor' => 'required|string',
            'options' => 'required|array|min:2',
            'options.*' => 'required|string',
            'multiple_choice' => 'boolean',
            'end_time' => 'nullable|date',
            'content_warning' => 'nullable|string',
        ]);

        $actor = Entry::find($request->input('actor'));
        if (!$actor) {
            return response()->json(['error' => 'Actor not found'], 404);
        }

        // Format options for storage
        $options = collect($request->input('options'))->map(function ($optionText) {
            return [
                'name' => $optionText,
                'count' => 0,
            ];
        })->all();

        $entry = Entry::make()
            ->collection('polls')
            ->published(true)
            ->data([
                'content' => $request->input('content'),
                'actor' => [$actor->id()],
                'date' => now()->format('Y-m-d H:i'),
                'options' => $options,
                'multiple_choice' => $request->boolean('multiple_choice', false),
                'end_time' => $request->input('end_time'),
                'closed' => false,
                'voters_count' => 0,
                'sensitive' => $request->filled('content_warning'),
                'summary' => $request->input('content_warning'),
                'is_internal' => true,
            ]);

        $entry->save();

        return response()->json(['success' => true, 'message' => 'Poll created', 'id' => $entry->id()]);
    }

    /**
     * Update an existing internal note.
     */
    public function updateNote(Request $request)
    {
        $request->validate([
            'id' => 'required|string',
            'content' => 'required|string',
            'content_warning' => 'nullable|string',
        ]);

        $entry = Entry::find($request->input('id'));
        if (!$entry) {
            return response()->json(['error' => 'Note not found'], 404);
        }

        // Only allow editing internal notes
        if ($entry->get('is_internal') === false) {
            return response()->json(['error' => 'Cannot edit external notes'], 403);
        }

        $entry->set('content', $request->input('content'));

        if ($request->filled('content_warning')) {
            $entry->set('sensitive', true);
            $entry->set('summary', $request->input('content_warning'));
        } else {
            $entry->set('sensitive', false);
            $entry->set('summary', null);
        }

        // Check if this is a quote that needs authorization
        $quoteOf = $entry->get('quote_of');
        $authStatus = $entry->get('quote_authorization_status');

        // If this is a quote without authorization, mark as pending
        // SendQuoteRequest will be automatically dispatched by ActivityPubListener
        if ($quoteOf && !$authStatus) {
            $entry->set('quote_authorization_status', 'pending');
            $entry->save();

            return response()->json([
                'success' => true,
                'message' => 'Note updated and quote authorization requested',
                'quote_status' => 'pending',
            ]);
        }

        $entry->save();

        return response()->json(['success' => true, 'message' => 'Note updated']);
    }

    /**
     * Delete a note or activity and its related items.
     */
    public function destroy(Request $request)
    {
        $request->validate([
            'id' => 'required|string',
        ]);

        $entry = Entry::find($request->input('id'));
        if (!$entry) {
            return response()->json(['error' => 'Entry not found'], 404);
        }

        $collection = $entry->collection()->handle();

        // Handle note/poll deletion
        if (in_array($collection, ['notes', 'polls'])) {
            $apId = $entry->get('activitypub_id');
            $entryUrl = $entry->absoluteUrl();
            $inReplyTo = $entry->get('in_reply_to');

            // Delete the note/poll
            $entry->delete();

            // Decrement reply count on parent if this was a reply
            if ($inReplyTo) {
                ThreadService::decrement($inReplyTo);
            }

            // Delete related activities (Create, Update, etc. that reference this note)
            if ($apId || $entryUrl) {
                $relatedActivities = Entry::query()
                    ->where('collection', 'activities')
                    ->get()
                    ->filter(function ($activity) use ($apId, $entryUrl) {
                        $object = $activity->get('object');
                        if (is_array($object)) {
                            $object = $object['id'] ?? $object[0] ?? null;
                        }
                        return $object === $apId || $object === $entryUrl;
                    });

                foreach ($relatedActivities as $activity) {
                    $activity->delete();
                }
            }

            return response()->json(['success' => true, 'message' => 'Note deleted']);
        }

        // Handle activity deletion
        if ($collection === 'activities') {
            $entry->delete();
            return response()->json(['success' => true, 'message' => 'Activity deleted']);
        }

        return response()->json(['error' => 'Cannot delete this entry type'], 400);
    }

    /**
     * Get activities related to a specific note/poll.
     */
    public function activities(Request $request, $id)
    {
        $entry = Entry::find($id);
        if (!$entry) {
            return response()->json(['error' => 'Entry not found'], 404);
        }

        $collection = $entry->collection()->handle();
        if (!in_array($collection, ['notes', 'polls'])) {
            return response()->json(['error' => 'Invalid entry type'], 400);
        }

        $apId = $entry->get('activitypub_id');
        $entryUrl = $entry->absoluteUrl();

        // Find all activities that reference this note/poll
        $activities = Entry::query()
            ->where('collection', 'activities')
            ->orderBy('date', 'desc')
            ->get()
            ->filter(function ($activity) use ($apId, $entryUrl, $id) {
                $object = $activity->get('object');
                if (is_array($object)) {
                    $object = $object['id'] ?? $object[0] ?? null;
                }
                return $object === $apId || $object === $entryUrl || $object === $id;
            })
            ->take(50)
            ->map(function ($activity) {
                return [
                    'id' => $activity->id(),
                    'type' => $activity->get('type'),
                    'date' => $activity->date()->format('M j, Y H:i'),
                    'date_human' => $activity->date()->diffForHumans(),
                    'activitypub_id' => $activity->get('activitypub_id'),
                ];
            })
            ->values();

        return response()->json(['data' => $activities]);
    }

    public function api(Request $request)
    {
        $user = User::current();
        $userActors = $user ? $user->get('actors', []) : [];

        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 20);
        if ($perPage > 100)
            $perPage = 100;

        $filter = $request->input('filter', 'all');

        $query = Entry::query();

        if ($filter === 'activities') {
            // Only query activities collection when filtering by activities
            $query->where('collection', 'activities');
        } elseif ($filter === 'mentions') {
            // For mentions, we need to search notes and polls (activities don't have mentioned_urls)
            $query->whereIn('collection', ['notes', 'polls']);
        } else {
            // Default: include all relevant collections
            $query->whereIn('collection', ['notes', 'polls', 'activities']);
        }

        // Store mention targets for post-query filtering (array field queries don't work in Statamic)
        $mentionTargets = null;
        if ($filter === 'mentions') {
            $mentionTargets = [];
            foreach ($userActors as $actorId) {
                $actor = Entry::find($actorId);
                if ($actor) {
                    $mentionTargets[] = $actor->absoluteUrl();
                    if ($aid = $actor->get('activitypub_id')) {
                        $mentionTargets[] = $aid;
                    }
                }
            }

            // If no targets, create impossible query
            if (empty($mentionTargets)) {
                $query->where('id', '___MATCH_FAIL___');
            }
        }

        if ($filter === 'all') {
            // For 'all' filter, exclude Announce and Create activities
            $query->where(function ($q) {
                $q->whereIn('collection', ['notes', 'polls'])
                    ->orWhere(function ($q2) {
                        $q2->where('collection', 'activities')
                            ->whereNotIn('type', ['Announce', 'Create']);
                    });
            });
        }

        $query->orderBy('date', 'desc');

        // Handle mentions filtering manually (array field queries don't work in Statamic)
        if ($filter === 'mentions' && !empty($mentionTargets)) {
            $allItems = $query->get();
            $filteredItems = $allItems->filter(function ($entry) use ($mentionTargets) {
                $mentionedUrls = $entry->get('mentioned_urls', []);
                if (!is_array($mentionedUrls)) {
                    return false;
                }
                foreach ($mentionTargets as $target) {
                    if (in_array($target, $mentionedUrls)) {
                        return true;
                    }
                }
                return false;
            });

            // Manual pagination
            $total = $filteredItems->count();
            $offset = ($page - 1) * $perPage;
            $items = $filteredItems->slice($offset, $perPage)->values();

            // Create a paginator instance manually
            $inboxItems = new \Illuminate\Pagination\LengthAwarePaginator(
                $items,
                $total,
                $perPage,
                $page,
                ['path' => \Illuminate\Pagination\Paginator::resolveCurrentPath()]
            );
        } else {
            $inboxItems = $query->paginate($perPage);
        }

        // --- BATCHING OPTIMIZATION ---
        // 1. Clear caches for fresh request
        $this->boostsMap = [];
        $this->actorCache = [];
        $this->voteCache = [];
        $this->quoteCache = [];

        // 2. Collect IDs of notes/objects in this page
        $objectIds = [];
        $items = $inboxItems->items();
        foreach ($items as $item) {
            if (in_array($item->collection()->handle(), ['notes', 'polls'])) {
                if ($apId = $item->get('activitypub_id')) {
                    if (is_string($apId)) {
                        $objectIds[] = $apId;
                    }
                }
            }
        }

        // 3. Preload all actors, quotes, and vote data in batch queries
        $this->preloadBatchData($items, $userActors);

        // 4. Batch Query for Boosts (Announce activities by USER)
        // We query only the user's announces to determine 'boosted_by_user' efficiently.
        // We use 'whereIn' which handles array lookups better than 'orWhere' loops in Statamic Stache.
        // We also wrap this in a TRY-CATCH to ensure the inbox NEVER crashes due to this optimization.
        if (!empty($objectIds) && !empty($userActors)) {
            try {
                $userBoosts = Entry::query()
                    ->where('collection', 'activities')
                    ->where('type', 'Announce')
                    ->whereIn('actor', $userActors)
                    ->get();

                // Filter in memory to match current page objects
                foreach ($userBoosts as $boost) {
                    $obj = $boost->get('object');
                    if (is_array($obj))
                        $obj = $obj['id'] ?? null;

                    if (is_string($obj) && in_array($obj, $objectIds)) {
                        $this->boostsMap[$obj]['boosted_by_user'] = true;
                    }
                }
            } catch (\Throwable $e) {
                // If optimization fails (e.g. dirty data crashing Stache), log it but allow inbox to load.
                \Log::error("ActivityPub Inbox Optimization Failed: " . $e->getMessage());
            }
        }

        $items = collect($items)->map(function ($entry) use ($request, $userActors) {
            return $this->transformEntry($entry, $request, $userActors, true);
        })->filter()->values();

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $inboxItems->currentPage(),
                'last_page' => $inboxItems->lastPage(),
                'total' => $inboxItems->total(),
            ]
        ]);
    }

    public function thread(Request $request, $id)
    {
        $rootNote = Entry::find($id);
        if (!$rootNote || in_array($rootNote->collection()->handle(), ['activities', 'actors'])) {
            return response()->json(['error' => 'Entry not found or invalid type'], 404);
        }

        $user = User::current();
        $userActors = $user ? $user->get('actors', []) : [];

        // Clear caches for thread context
        $this->actorCache = [];
        $this->voteCache = [];
        $this->quoteCache = [];

        // Cache valid collections once for this request
        $validCollections = Collection::all()
            ->map->handle()
            ->reject(fn($h) => in_array($h, ['activities', 'actors']))
            ->values()
            ->all();

        $thread = collect();

        // 1. Ancestors - traverse up the reply chain
        $current = $rootNote;
        $ancestors = collect();
        while ($current && $ancestors->count() < 10) {
            $replyToId = $current->get('in_reply_to');
            if (!$replyToId) {
                break;
            }

            // Try as ID first
            $parent = Entry::find($replyToId);

            // Fallback: try as AP ID
            if (!$parent) {
                $parent = Entry::query()
                    ->whereIn('collection', $validCollections)
                    ->where('activitypub_id', $replyToId)
                    ->first();
            }

            if ($parent) {
                $ancestors->prepend($parent);
                $current = $parent;
            } else {
                break;
            }
        }

        $thread = $thread->merge($ancestors);

        // 2. Current note
        $thread->push($rootNote);

        // 3. Descendants - use whereIn query instead of loading all entries
        $rootIds = [$rootNote->id()];
        if ($aid = $rootNote->get('activitypub_id')) {
            $rootIds[] = $aid;
        }
        $rootIds[] = $rootNote->absoluteUrl();

        // Query directly for replies using whereIn on in_reply_to
        // This is much more efficient than loading all entries and filtering
        $replies = Entry::query()
            ->whereIn('collection', $validCollections)
            ->where(function ($q) use ($rootIds) {
                foreach ($rootIds as $rootId) {
                    $q->orWhere('in_reply_to', $rootId);
                }
            })
            ->orderBy('date', 'asc')
            ->limit(100) // Safety limit for very popular threads
            ->get();

        $thread = $thread->merge($replies);

        // Preload batch data for all thread entries
        $this->preloadBatchData($thread->all(), $userActors);

        // Transform
        $items = $thread->map(function ($entry) use ($request, $userActors, $rootNote) {
            $transformed = $this->transformEntry($entry, $request, $userActors, false);

            if ($entry->id() === $rootNote->id()) {
                $transformed['is_focus'] = true;
            }

            return $transformed;
        });

        return response()->json([
            'data' => $items->values(),
        ]);
    }

    protected function resolveActor($actorId, $request)
    {
        if (is_array($actorId)) {
            $actorId = $actorId[0] ?? null;
        }

        if (!is_string($actorId) || !$actorId) {
            return null;
        }

        // Cache Check - should be populated by preloadBatchData()
        if (isset($this->actorCache[$actorId])) {
            return $this->actorCache[$actorId];
        }

        // Fallback: load actor if not in cache (e.g., thread view or edge cases)
        $actorEntry = Entry::find($actorId);
        if (!$actorEntry) {
            return null;
        }

        $result = $this->buildActorData($actorEntry);

        // Store in Cache for future lookups in this request
        $this->actorCache[$actorId] = $result;

        return $result;
    }

    protected function transformEntry($entry, $request, $userActors, $includeParent = false)
    {
        $collection = $entry->collection()->handle();

        // Shared properties
        $id = $entry->id();
        $date = $entry->date();
        $apJson = $entry->get('activitypub_json');
        $payload = $apJson ? json_decode($apJson, true) : [];

        // NOTES & POLLS Processing
        if (in_array($collection, ['notes', 'polls'])) {
            $note = $entry;
            $actor = $this->resolveActor($note->get('actor'), $request);
            if (!$actor) {
                $actor = ['name' => 'Unknown', 'handle' => '@unknown', 'avatar' => null, 'url' => '#'];
            }

            // Check for new Boost Logic (merged into note)
            $boostedByArray = $note->get('boosted_by', []);
            $lastBoosterId = null;
            if (!empty($boostedByArray) && is_array($boostedByArray)) {
                $lastBoosterId = end($boostedByArray);
            }

            $isBoost = $note->get('is_boost', false); // Legacy check
            $boostedBy = null;

            if ($lastBoosterId) {
                $isBoost = true;
                $boosterActor = $this->resolveActor($lastBoosterId, $request);
                if ($boosterActor) {
                    $boostedBy = [
                        'name' => $boosterActor['name'],
                        'handle' => $boosterActor['handle']
                    ];
                }
            } elseif ($isBoost) {
                // Legacy Boost Logic (separate entry)
                $reblogOf = $note->get('reblog_of');
                if (is_array($reblogOf))
                    $reblogOf = $reblogOf[0] ?? null; // Statamic relation

                $originalNote = null;
                if ($reblogOf)
                    $originalNote = Entry::find($reblogOf);

                if ($originalNote) {
                    $boosterActor = $this->resolveActor($note->get('actor'), $request);
                    if ($boosterActor) {
                        $boostedBy = [
                            'name' => $boosterActor['name'],
                            'handle' => $boosterActor['handle'] // Keep simplified for UI
                        ];
                    }
                    $note = $originalNote;
                    // Resolve original actor
                    $actor = $this->resolveActor($note->get('actor'), $request);
                }
            }

            // If actor is missing from original note (e.g. deleted), handle gracefully
            if (!$actor) {
                $actor = ['name' => 'Unknown', 'handle' => '@unknown', 'avatar' => null, 'url' => '#'];
            }

            $cleanContent = strip_tags(\Statamic\Facades\Markdown::parse((string) $note->get('content')), '<p><br><a><strong><em><u><i><b><blockquote><ul><ol><li><code><pre><img><span><div><h1><h2><h3><h4><h5><h6>');

            // Attachments
            $attachments = [];
            $oembed = null;

            if (isset($payload['attachment']) && is_array($payload['attachment'])) {
                foreach ($payload['attachment'] as $att) {
                    if (($att['type'] ?? '') === 'Document' && str_starts_with($att['mediaType'] ?? '', 'image/')) {
                        $attachments[] = [
                            'type' => 'image',
                            'url' => $att['url'] ?? '',
                            'description' => $att['name'] ?? '',
                            'aspect_ratio' => ($att['width'] ?? 0) && ($att['height'] ?? 0) ? ($att['width'] / $att['height']) : null
                        ];
                    }
                }
            }

            // Use cached oembed data (fetched asynchronously via batchEnrichment endpoint)
            $oembed = $note->get('oembed_data');
            $linkPreview = $note->get('link_preview');
            $needsPreview = false;

            // Flag for enrichment if neither oembed nor link preview exists
            if ($oembed === null && empty($linkPreview)) {
                if (LinkPreview::extractUrl($cleanContent)) {
                    $needsPreview = true;
                }
            }

            $obsUrl = $note->get('activitypub_id');
            if (!$obsUrl) {
                $obsUrl = $note->absoluteUrl();
            }

            // Parent Context (Recursive but limited)
            $parent = null;
            if ($includeParent && $note->get('in_reply_to')) {
                $parentId = $note->get('in_reply_to');
                $parentEntry = Entry::find($parentId);
                if (!$parentEntry) {
                    $parentEntry = Entry::query()->where('collection', 'notes')->where('activitypub_id', $parentId)->first();
                }

                if ($parentEntry) {
                    $parent = $this->transformEntry($parentEntry, $request, $userActors, false); // Don't recurse further for now
                }
            }

            return [
                'type' => $collection === 'polls' ? 'question' : 'note',
                'id' => $note->id(),
                'content' => $cleanContent,
                'raw_content' => $note->get('content'), // Added as per need
                'attachments' => $attachments,
                'oembed' => $oembed,
                'link_preview' => $linkPreview,
                'needs_preview' => $needsPreview,
                'date' => $date->format('M j, Y H:i'),
                'date_human' => $date->diffForHumans(),
                'actor' => $actor,
                'counts' => [
                    'replies' => (int) $note->get('reply_count', 0),
                    'boosts' => (int) $note->get('boost_count', 0),
                    'likes' => (int) $note->get('like_count', 0),
                ],
                'liked_by_user' => $this->checkIfLiked($note, $userActors),
                'actions' => [
                    'reply' => cp_route('collections.entries.create', ['collection' => 'notes', 'site' => \Statamic\Facades\Site::selected()->handle(), 'in_reply_to' => $note->id()]),
                    'view' => $obsUrl,
                    'thread' => cp_route('activitypub.thread', ['id' => $note->id()]),
                ],
                'activitypub_json' => $apJson,
                'is_boost' => $isBoost ?? false,
                'boosted_by' => $boostedBy,
                'boosted_by_user' => $this->checkIfBoosted($note, $userActors),
                'quote' => $this->resolveQuote($note),
                'sensitive' => (bool) $note->get('sensitive', false),
                'summary' => $note->get('summary'),
                'is_internal' => (bool) $note->get('is_internal', false),
                'related_activity_count' => (int) $note->get('related_activity_count', 0),
                'parent' => $parent,
                'options' => $note->get('options', []),
                'voters_count' => $note->get('voters_count', 0),
                'end_time' => $note->get('end_time'),
                'closed' => (bool) $note->get('closed', false),
                'multiple_choice' => (bool) $note->get('multiple_choice', false),
                'has_voted' => $this->checkIfVoted($note, $userActors),
                'voted_options' => $this->getVotedOptions($note, $userActors),
            ];
        }

        // ACTIVITIES Processing
        if ($collection === 'activities') {
            $activity = $entry;
            $actor = $this->resolveActor($activity->get('actor'), $request);
            if (!$actor) {
                $actor = ['name' => 'Unknown', 'handle' => '@unknown', 'avatar' => null, 'url' => '#'];
            }

            $object = $activity->get('object');
            $type = $activity->get('type');

            // Re-implement the nesting check if $includeParent is true (meaning main stream context)
            if ($includeParent) {
                $objId = null;
                if (is_array($object)) {
                    $objId = $object['id'] ?? $object[0] ?? null;
                } else {
                    $objId = $object;
                }

                if ($objId) {
                    // Optimized query to avoid Stache 'selectedQueryColumns on null' crash in nested loops
                    $notes = \Statamic\Facades\Collection::find('notes');
                    $exists = $notes ? $notes->queryEntries()->where('activitypub_id', $objId)->first() : null;

                    // Fallback checks...
                    if (!$exists) {
                        $exists = Entry::find($objId);
                        if ($exists && $exists->collection()->handle() !== 'notes') {
                            $exists = null;
                        }
                    }
                    if (!$exists) {
                        $baseUrl = \Statamic\Facades\Site::selected()->absoluteUrl();
                        if (\Illuminate\Support\Str::startsWith($objId, $baseUrl)) {
                            $uri = str_replace($baseUrl, '', $objId);
                            $uri = '/' . ltrim($uri, '/');
                            $localEntry = Entry::findByUri($uri, \Statamic\Facades\Site::selected()->handle());
                            if ($localEntry && !in_array($localEntry->collection()->handle(), ['activities', 'actors'])) {
                                $exists = $localEntry;
                            }
                        }
                    }

                    if ($exists) {
                        return null; // FILTER OUT: Nested under the parent Note
                    }
                }
            }


            $resolveObjUrl = function ($obj) {
                if (is_string($obj))
                    return $obj;
                if (is_array($obj))
                    return $obj['id'] ?? $obj['url'] ?? null;
                return null;
            };

            $objectUrl = $resolveObjUrl($object);
            if (!$objectUrl)
                $objectUrl = $activity->get('activitypub_id');

            $objectType = is_array($object) ? ($object['type'] ?? 'Object') : 'Object';
            if ($type === 'Follow')
                $objectType = 'Actor';

            $actContent = $activity->get('content');
            if ($actContent) {
                $summary = $actContent . ' <br><a href="' . $objectUrl . '" target="_blank">' . $objectUrl . '</a>';
            } else {
                $summary = sprintf(
                    '%s %s <a href="%s" target="_blank">%s</a>',
                    $type,
                    $objectType,
                    $objectUrl,
                    $objectUrl
                );
            }

            // Fix apJson variable scope if needed, assume it comes from entry
            $apJson = $activity->get('activitypub_json');

            return [
                'type' => 'activity',
                'id' => $activity->id(),
                'content' => $summary,
                'attachments' => [],
                'date' => $activity->date()->format('M j, Y H:i'), // Use activity date
                'date_human' => $activity->date()->diffForHumans(),
                'actor' => $actor,
                'actions' => [
                    'view' => $objectUrl,
                ],
                'activitypub_json' => $apJson,
                'activitypub_id' => $activity->get('activitypub_id'),
            ];
        }

        return null;
    }

    protected function resolveQuote($note)
    {
        $quoteOf = $note->get('quote_of');
        if (!$quoteOf) {
            return null;
        }

        // Unwrap nested arrays (handle malformed data)
        while (is_array($quoteOf)) {
            $quoteOf = $quoteOf[0] ?? null;
            if ($quoteOf === null) {
                return null;
            }
        }

        if (!is_string($quoteOf)) {
            return null;
        }

        // Use cache if available (populated by preloadBatchData)
        $quotedNote = $this->quoteCache[$quoteOf] ?? null;

        // Fallback: load if not in cache
        if (!$quotedNote) {
            $quotedNote = Entry::find($quoteOf);
        }

        if (!$quotedNote) {
            return null;
        }

        // Transform the quoted note using the same logic as main notes (no recursion)
        $transformed = $this->transformEntry($quotedNote, request(), [], false);

        // Add url field for backward compatibility (tests expect this)
        if ($transformed && !isset($transformed['url'])) {
            $transformed['url'] = $quotedNote->get('activitypub_id') ?? $quotedNote->absoluteUrl();
        }

        return $transformed;
    }

    // ...

    protected function checkIfLiked($note, $userActors)
    {
        $likes = $note->get('liked_by', []);
        if (!is_array($likes))
            return false;
        foreach ($userActors as $actorId) {
            if (in_array($actorId, $likes))
                return true;
        }
        return false;
    }

    protected function checkIfBoosted($note, $userActors)
    {
        // Optimized: Instead of querying, we can check if the user has this note in their 'boosted' list?
        // OR we just keep the simple query but ONLY for the user's actors, which is fast.
        // Or efficient batching I added earlier?
        // The implementation plan said "Batch Query for Boosts ...".
        // Use the map if available.

        $objectUrl = $note->get('activitypub_id');
        if (!$objectUrl || !is_string($objectUrl))
            return false;

        if (isset($this->boostsMap[$objectUrl]['boosted_by_user'])) {
            return $this->boostsMap[$objectUrl]['boosted_by_user'];
        }
        return false;
    }

    protected function getBoostCount($note)
    {
        return (int) $note->get('boost_count', 0);
    }

    protected function getRelatedActivityCount($note)
    {
        return (int) $note->get('related_activity_count', 0);
    }


    protected function checkIfVoted($note, $actors)
    {
        if ($note->collection()->handle() !== 'polls') {
            return false;
        }

        $pollId = $note->id();

        // Use cache if available (populated by preloadBatchData)
        if (isset($this->voteCache[$pollId])) {
            return $this->voteCache[$pollId]['has_voted'];
        }

        // Fallback: query if not in cache (e.g., thread view)
        $pollUrl = $note->get('activitypub_id');
        if (!$pollUrl) {
            $pollUrl = $note->absoluteUrl();
        }

        $ids = array_values(array_filter([(string) $pollId, (string) $pollUrl]));

        $hasVoted = Entry::query()
            ->where('collection', 'notes')
            ->whereIn('in_reply_to', $ids)
            ->whereIn('actor', $actors)
            ->exists();

        // Cache the result
        if (!isset($this->voteCache[$pollId])) {
            $this->voteCache[$pollId] = ['has_voted' => $hasVoted, 'voted_options' => []];
        }

        return $hasVoted;
    }

    protected function getVotedOptions($note, $actors)
    {
        if ($note->collection()->handle() !== 'polls') {
            return [];
        }

        $pollId = $note->id();

        // Use cache if available (populated by preloadBatchData)
        if (isset($this->voteCache[$pollId])) {
            return $this->voteCache[$pollId]['voted_options'];
        }

        // Fallback: query if not in cache (e.g., thread view)
        $pollUrl = $note->get('activitypub_id');
        if (!$pollUrl) {
            $pollUrl = $note->absoluteUrl();
        }

        $ids = array_values(array_filter([(string) $pollId, (string) $pollUrl]));

        $votes = Entry::query()
            ->where('collection', 'notes')
            ->whereIn('in_reply_to', $ids)
            ->whereIn('actor', $actors)
            ->get();

        $votedOptions = $votes->map(fn($v) => $v->get('content'))->unique()->values()->all();

        // Cache the result
        $this->voteCache[$pollId] = [
            'has_voted' => !empty($votedOptions),
            'voted_options' => $votedOptions,
        ];

        return $votedOptions;
    }
}
