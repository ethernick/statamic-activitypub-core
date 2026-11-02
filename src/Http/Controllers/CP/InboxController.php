<?php

declare(strict_types=1);

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
use Statamic\Facades\Taxonomy;
use Statamic\Facades\YAML;
use Statamic\Facades\File;
use Statamic\Facades\Blink;
use Illuminate\Support\Facades\Log;
use Ethernick\ActivityPubCore\Services\ActivityPubUtils;
use Ethernick\ActivityPubCore\Storage\ActivityPubPayload;

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
        // Collect all actor IDs, quote IDs, and other data for hooks
        $actorIds = [];
        $quoteIds = [];

        $inboxCollections = \Ethernick\ActivityPubCore\Services\ActivityPubTypes::getInboxCollections();

        foreach ($items as $item) {
            $collection = $item->collection()->handle();

            if (in_array($collection, $inboxCollections)) {
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
                $this->actorCache[$actor->id()] = \Ethernick\ActivityPubCore\Services\ActivityPubUtils::transformActorForCp($actor);
            }
        }

        // Batch load all quotes and their actors
        $quoteIds = array_keys($quoteIds);
        if (!empty($quoteIds)) {
            $quotes = Entry::query()
                ->whereIn('collection', $inboxCollections)
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
                    $this->actorCache[$actor->id()] = \Ethernick\ActivityPubCore\Services\ActivityPubUtils::transformActorForCp($actor);
                }
            }

            // Build quote cache
            foreach ($quotes as $quote) {
                $this->quoteCache[$quote->id()] = $quote;
            }
        }

        // Execute addon preloading hooks
        \Ethernick\ActivityPubCore\Services\ActivityPubTypes::executePreloadHooks($items, $userActors);
    }



    /**
     * Batch enrich notes with link previews and oembed data.
     * This endpoint fetches external metadata asynchronously after page load.
     */
    public function batchEnrichment(Request $request): mixed
    {
        $request->validate([
            'note_ids' => 'required|array',
            'note_ids.*' => 'string'
        ]);

        $ids = $request->input('note_ids');
        $enrichments = [];

        $notesToEnrich = [];
        $linksToFetch = [];

        foreach ($ids as $id) {
            $note = Entry::find($id);
            if (!$note) {
                continue;
            }

            $content = $note->get('content');
            $isInternal = $note->get('is_internal', false);
            $htmlContent = $isInternal
                ? \Statamic\Facades\Markdown::parse((string) $content)
                : (string) $content;

            // 1. OEmbed enrichment (rich embeds like YouTube, Twitter, etc.)
            $existingOembed = $note->get('oembed_data');
            $hasOembed = false;

            if ($existingOembed !== null) {
                if ($existingOembed !== false) {
                    $enrichments[$id]['oembed'] = $existingOembed;
                    $hasOembed = true;
                }
            } else {
                $oembedData = \Ethernick\ActivityPubCore\Services\OEmbed::resolve($htmlContent);
                if ($oembedData) {
                    $note->set('oembed_data', $oembedData);
                    $enrichments[$id]['oembed'] = $oembedData;
                    $hasOembed = true;
                    $note->saveQuietly();
                } else {
                    $note->set('oembed_data', false);
                    $note->saveQuietly();
                }
            }

            // 2. Link Preview enrichment (OpenGraph/Twitter cards)
            if (!$hasOembed) {
                $existingPreview = $note->get('link_preview');
                if ($existingPreview && !empty($existingPreview)) {
                    $enrichments[$id]['link_preview'] = $existingPreview;
                } else {
                    if ($url = LinkPreview::extractUrl($htmlContent)) {
                        $notesToEnrich[$id] = ['note' => $note, 'url' => $url];
                        $linksToFetch[$url] = true;
                    }
                }
            }
        }

        // Concurrent Link Preview Pooling: Fetch all external targets simultaneously
        if (!empty($linksToFetch)) {
            $urls = array_keys($linksToFetch);
            $responses = [];

            try {
                $responses = \Illuminate\Support\Facades\Http::pool(function ($pool) use ($urls) {
                    return collect($urls)->mapWithKeys(function ($url) use ($pool) {
                        return [$url => $pool->timeout(4)->get($url)];
                    })->all();
                });
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning('Batch link enrichment pool error: ' . $e->getMessage());
            }

            foreach ($notesToEnrich as $id => $item) {
                $note = $item['note'];
                $url = $item['url'];
                $previewData = null;

                if (isset($responses[$url])) {
                    $res = $responses[$url];
                    if ($res instanceof \Illuminate\Http\Client\Response && $res->successful()) {
                        $previewData = LinkPreview::parseHtml($res->body(), $url);
                    }
                }

                // If pool missed or failed, fall back to cached fetch
                if (!$previewData) {
                    $previewData = LinkPreview::fetch($url);
                }

                if ($previewData) {
                    $note->set('link_preview', $previewData);
                    $note->saveQuietly();
                    $enrichments[$id]['link_preview'] = $previewData;
                }
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
    public function batchLinkPreview(Request $request): mixed
    {
        return $this->batchEnrichment($request);
    }

    public function index(): mixed
    {
        $user = User::current();
        $userActors = $user ? $user->get('actors', []) : [];

        $actors = Entry::query()
            ->where('collection', 'actors')
            ->whereIn('id', $userActors)
            ->get()
            ->unique(fn($actor) => $actor->slug())
            ->values()
            ->map(fn($actor) => \Ethernick\ActivityPubCore\Services\ActivityPubUtils::transformActorForCp($actor));

        $settings = Blink::once('activitypub-settings', function () {
            $path = \Ethernick\ActivityPubCore\Services\ActivityPubUtils::settingsPath();
            if (!File::exists($path)) {
                return [];
            }
            return YAML::parse(File::get($path));
        });

        $hashtagSettings = $settings['hashtags'] ?? [];

        return view('activitypub::inbox', [
            'title' => 'Inbox',
            'localActors' => $actors,
            'createNoteUrl' => cp_route('collections.entries.create', ['collection' => 'notes', 'site' => \Statamic\Facades\Site::selected()->handle()]),
            'hashtagField' => $hashtagSettings['field'] ?? 'tags',
            'hashtagTaxonomy' => $hashtagSettings['taxonomy'] ?? 'tags',
            'hashtagEnabled' => $hashtagSettings['enabled'] ?? false,
        ]);
    }

    public function reply(Request $request): mixed
    {
        $request->validate([
            'content' => 'required|string',
            'actor' => 'required|string',
            'in_reply_to' => 'required|string',
            'content_warning' => 'nullable|string',
            'tags' => 'nullable|array',
        ]);

        $user = User::current();
        $userActors = $user ? $user->get('actors', []) : [];

        $actor = Entry::find($request->input('actor'));
        if (!$actor) {
            return response()->json(['error' => 'Actor not found'], 404);
        }

        if (!in_array($actor->id(), $userActors)) {
            return response()->json(['error' => 'Not authorized to post as this actor'], 403);
        }

        $path = \Ethernick\ActivityPubCore\Services\ActivityPubUtils::settingsPath();
        $settings = File::exists($path) ? YAML::parse(File::get($path)) : [];
        $hashtagField = $settings['hashtags']['field'] ?? 'tags';

        $uuid = (string) \Illuminate\Support\Str::uuid();
        $now = now();

        $entry = Entry::make()
            ->collection('notes')
            ->id($uuid)
            ->slug($uuid)
            ->date($now)
            ->published(true)
            ->data([
                'content' => $request->input('content'),
                'actor' => [$actor->id()],
                'in_reply_to' => $request->input('in_reply_to'),
                'date' => $now->format('Y-m-d H:i:s'),
                'sensitive' => $request->filled('content_warning'),
                'summary' => $request->input('content_warning'),
                $hashtagField => $request->input('tags', []),
                'is_internal' => true,
            ]);

        $entry->save();

        return response()->json(['success' => true, 'message' => 'Reply posted'], 201);
    }

    /**
     * Store a new note from the CP compose form.
     */
    public function store(Request $request, string $type): mixed
    {
        return $this->storeByType($request, $type);
    }

    /**
     * Generic store method that delegates to registered type handlers.
     */
    protected function storeByType(Request $request, string $type): mixed
    {
        $handlerClass = \Ethernick\ActivityPubCore\Services\ActivityPubTypes::getStoreHandler($type);

        if (!$handlerClass) {
            return response()->json(['error' => "No store handler registered for type: {$type}"], 400);
        }

        try {
            /** @var \Ethernick\ActivityPubCore\Contracts\StoreHandlerInterface $handler */
            $handler = app($handlerClass);
            $entry = $handler->store($request);

            return response()->json([
                'success' => true,
                'message' => "{$type} created",
                'id' => $entry->id()
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update an existing internal note.
     */
    public function updateNote(Request $request, string $id): mixed
    {
        $request->validate([
            'content' => 'required|string',
            'content_warning' => 'nullable|string',
            'tags' => 'nullable|array',
        ]);

        $entry = Entry::find($id);
        if (!$entry) {
            return response()->json(['error' => 'Note not found'], 404);
        }

        // Only allow editing internal notes
        if ($entry->get('is_internal') === false) {
            return response()->json(['error' => 'Cannot edit external notes'], 403);
        }

        $path = \Ethernick\ActivityPubCore\Services\ActivityPubUtils::settingsPath();
        $settings = File::exists($path) ? YAML::parse(File::get($path)) : [];
        $hashtagField = $settings['hashtags']['field'] ?? 'tags';

        $entry->set('content', $request->input('content'));
        $entry->set($hashtagField, $request->input('tags', []));

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
     * Search terms in a taxonomy.
     */
    public function searchTerms(Request $request): mixed
    {
        $taxonomy = $request->input('taxonomy', 'tags');
        $query = $request->input('q', '');

        $terms = \Statamic\Facades\Term::query()
            ->where('taxonomy', $taxonomy);

        if ($query) {
            $terms->where('title', 'like', "%{$query}%");
        }

        return $terms->limit(20)->get()->map(function ($term) {
            return [
                'id' => $term->slug(),
                'title' => $term->get('title') ?? $term->slug(),
            ];
        });
    }

    /**
     * Delete a note or activity and its related items.
     */
    public function destroy(Request $request): mixed
    {
        $request->validate([
            'id' => 'required|string',
        ]);

        $entry = Entry::find($request->input('id'));

        \Illuminate\Support\Facades\Log::info("ActivityPub CP: Attempting to delete entry", [
            'id' => $request->input('id'),
            'found' => (bool) $entry,
            'collection' => $entry ? $entry->collection()->handle() : null
        ]);

        if (!$entry) {
            return response()->json(['error' => 'Entry not found'], 404);
        }

        $collection = $entry->collection()->handle();
        $inboxCollections = \Ethernick\ActivityPubCore\Services\ActivityPubTypes::getInboxCollections();

        // Handle collection-based deletion
        if (in_array($collection, $inboxCollections)) {
            $apId = $entry->get('activitypub_id');
            $entryUrl = $entry->absoluteUrl();
            $inReplyTo = $entry->get('in_reply_to');

            // Delete the entry
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
     * Get activities related to a specific item.
     * 
     * @param string $id
     * @return mixed
     */
    public function activities(string $id): mixed
    {
        $entry = Entry::find($id);
        if (!$entry) {
            return response()->json(['error' => 'Entry not found'], 404);
        }

        $collection = $entry->collection()->handle();
        $inboxCollections = \Ethernick\ActivityPubCore\Services\ActivityPubTypes::getInboxCollections();

        if (!in_array($collection, $inboxCollections)) {
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
                    $objId = $object['id'] ?? $object[0] ?? null;
                    if (is_array($objId))
                        $objId = $objId['id'] ?? null;
                    $object = $objId;
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

    public function api(Request $request): mixed
    {
        $user = User::current();
        $userActors = $user ? $user->get('actors', []) : [];

        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 20);
        if ($perPage > 100)
            $perPage = 100;

        $filter = $request->input('filter', 'all');

        $query = Entry::query();

        $inboxCollections = \Ethernick\ActivityPubCore\Services\ActivityPubTypes::getInboxCollections();

        if ($filter === 'activities') {
            // Only query activities collection when filtering by activities
            $query->where('collection', 'activities');
        } elseif ($filter === 'mentions') {
            // For mentions, we need to search inbox collections
            $query->whereIn('collection', $inboxCollections);
        } elseif ($filter === 'all') {
            // Include all standard content collections + filtered activities
            $query->whereIn('collection', array_merge(['activities'], $inboxCollections));
            $query->where(function ($q) {
                $q->whereNotIn('collection', ['activities'])
                    ->orWhereIn('type', ['Arrive', 'Travel', 'Offer', 'Listen', 'Question', 'Follow', 'Like', 'Announce']);
            });
        } else {
            // Default: include all relevant collections
            $query->whereIn('collection', array_merge(['activities'], $inboxCollections));
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


        $query->orderBy('date', 'desc')->orderBy('id', 'desc');

        $search = trim((string) $request->input('search', $request->input('q', '')));

        // 1. Mentions filter using indexed mentioned_urls
        if ($filter === 'mentions') {
            if (!empty($mentionTargets)) {
                $query->where(function ($q) use ($mentionTargets) {
                    $q->whereJsonOverlaps('mentioned_urls', $mentionTargets)
                      ->orWhereIn('mentioned_urls', $mentionTargets);
                });
            } else {
                $query->where('id', '___MATCH_FAIL___');
            }
        }

        // 2. Search filter utilizing indexed fields directly on the query builder
        if ($search !== '') {
            $settings = Blink::once('activitypub-settings', function () {
                $path = \Ethernick\ActivityPubCore\Services\ActivityPubUtils::settingsPath();
                if (!File::exists($path)) {
                    return [];
                }
                return YAML::parse(File::get($path));
            });
            $hashtagField = $settings['hashtags']['field'] ?? 'tags';

            if (str_starts_with($search, '@')) {
                // Filter by author/actor handle, username, domain, or slug using fast cached map
                $rawHandle = trim(ltrim($search, '@'));
                if ($rawHandle === '') {
                    // Empty '@' entered - match nothing rather than returning entire collection
                    $query->where('id', '___MATCH_FAIL___');
                } else {
                    $handleLower = strtolower($rawHandle);
                    $extractedUsername = explode('@', $handleLower)[0] ?? $handleLower;

                    // Resolve matching actor IDs instantly from dedicated actor search index (sub-millisecond)
                    $matchedActorIds = \Ethernick\ActivityPubCore\Search\ActorSearchIndex::findActorIds($rawHandle);

                    if (!empty($matchedActorIds)) {
                        // PURE INDEXED LOOKUP: Avoid linear disk scans across thousands of entries
                        $query->where(function ($q) use ($matchedActorIds) {
                            $q->whereJsonOverlaps('actor', $matchedActorIds)
                              ->orWhereIn('actor', $matchedActorIds);
                        });
                    } else {
                        // Fallback substring search ONLY if no actors resolved in index
                        $query->where(function ($q) use ($handleLower, $extractedUsername) {
                            $q->where('actor', 'like', "%{$handleLower}%")
                              ->orWhere('actor_name', 'like', "%{$handleLower}%");

                            if ($extractedUsername !== '' && $extractedUsername !== $handleLower) {
                                $q->orWhere('actor', 'like', "%{$extractedUsername}%")
                                  ->orWhere('actor_name', 'like', "%{$extractedUsername}%");
                            }
                        });
                    }
                }
            } elseif (str_starts_with($search, '#')) {
                // Filter by hashtag using indexed tags field + content body search
                $rawTag = trim(ltrim($search, '#'));
                if ($rawTag === '') {
                    // Empty '#' entered - match nothing rather than returning entire collection
                    $query->where('id', '___MATCH_FAIL___');
                } else {
                    $tagLower = strtolower($rawTag);
                    $tagUpper = strtoupper($rawTag);

                    $query->where(function ($q) use ($tagLower, $tagUpper, $hashtagField) {
                        $q->whereJsonOverlaps($hashtagField, [$tagLower, $tagUpper, ucfirst($tagLower)])
                          ->orWhere($hashtagField, 'like', "%{$tagLower}%")
                          ->orWhere('content', 'like', "%#{$tagLower}%");
                    });
                }
            } else {
                // Free-text search:
                // Check if query matches an author/actor/user, or keywords in content/title
                $tokens = \Ethernick\ActivityPubCore\Search\SearchTokenExtractor::extract($search);
                $matchedActorIds = \Ethernick\ActivityPubCore\Search\ActorSearchIndex::findActorIds($search);
                $needle = strtolower($search);

                $query->where(function ($q) use ($matchedActorIds, $tokens, $needle, $hashtagField) {
                    // 1. Match author/actor if search matches an actor/user
                    if (!empty($matchedActorIds)) {
                        $q->whereJsonOverlaps('actor', $matchedActorIds)
                          ->orWhereIn('actor', $matchedActorIds);
                    }

                    // 2. Match content tokens or fallback text fields
                    if (!empty($tokens)) {
                        $contentMatch = function ($sub) use ($tokens, $needle) {
                            foreach ($tokens as $token) {
                                $sub->whereJsonOverlaps('search_tokens', [$token]);
                            }
                            $sub->orWhere('actor', 'like', "%{$needle}%")
                                ->orWhere('actor_name', 'like', "%{$needle}%");
                        };

                        if (!empty($matchedActorIds)) {
                            $q->orWhere($contentMatch);
                        } else {
                            $contentMatch($q);
                        }
                    } else {
                        // Fallback substring scan ONLY if query has zero extractable tokens (e.g. stop words)
                        $fallback = function ($sub) use ($needle, $hashtagField) {
                            $sub->where('title', 'like', "%{$needle}%")
                                ->orWhere('content', 'like', "%{$needle}%")
                                ->orWhere('summary', 'like', "%{$needle}%")
                                ->orWhere('actor', 'like', "%{$needle}%")
                                ->orWhere('actor_name', 'like', "%{$needle}%")
                                ->orWhere($hashtagField, 'like', "%{$needle}%")
                                ->orWhereJsonOverlaps($hashtagField, [$needle, strtoupper($needle), ucfirst($needle)]);
                        };

                        if (!empty($matchedActorIds)) {
                            $q->orWhere($fallback);
                        } else {
                            $fallback($q);
                        }
                    }
                });
            }
        }

        $inboxItems = $query->paginate($perPage);

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
            if (in_array($item->collection()->handle(), $inboxCollections)) {
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
                $userBoostsQuery = Entry::query()
                    ->where('collection', 'activities')
                    ->where('type', 'Announce')
                    ->whereIn('actor', $userActors);

                // Scope to recent activities window to prevent full scan across long historical datasets
                $userBoostsQuery->where('date', '>=', now()->subDays(60)->format('Y-m-d'));

                $userBoosts = $userBoostsQuery->get();

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
                Log::error("ActivityPub Inbox Optimization Failed: " . $e->getMessage());
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

    public function thread(Request $request, string $id): mixed
    {
        $rootNote = Entry::find($id);
        if (!$rootNote || $rootNote->collection()->handle() === 'actors') {
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
            ->reject(fn($h) => in_array($h, ['actors']))
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
        if ($rootNote->collection()->handle() === 'activities' && $obj = $rootNote->get('object')) {
            if (is_string($obj))
                $rootIds[] = $obj;
            elseif (is_array($obj) && isset($obj['id']))
                $rootIds[] = $obj['id'];
        }
        if ($absUrl = $rootNote->absoluteUrl()) {
            $rootIds[] = $absUrl;
        }

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

    protected function resolveActor(mixed $actorId, Request $request): ?array
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

        $result = ActivityPubUtils::transformActorForCp($actorEntry);

        // Store in Cache for future lookups in this request
        $this->actorCache[$actorId] = $result;

        return $result;
    }

    protected function transformEntry(mixed $entry, Request $request, array $userActors, bool $includeParent = false): ?array
    {
        $collection = $entry->collection()->handle();
        $inboxCollections = \Ethernick\ActivityPubCore\Services\ActivityPubTypes::getInboxCollections();

        // Shared properties
        $id = $entry->id();
        $apJson = ActivityPubPayload::for($entry)->raw();
        $payload = is_string($apJson) ? json_decode($apJson, true) : [];

        // Determine date: prioritize ActivityPub JSON updated timestamp first, then explicit entry date, published date, and fallback
        if (!empty($payload['updated'])) {
            $date = \Carbon\Carbon::parse($payload['updated']);
        } elseif ($rawEntryDate = ($entry->get('date') ?? $entry->get('start_time'))) {
            $date = \Carbon\Carbon::parse($rawEntryDate);
        } elseif (!empty($payload['published'])) {
            $date = \Carbon\Carbon::parse($payload['published']);
        } else {
            $date = $entry->date();
        }

        // INBOX COLLECTIONS Processing
        if (in_array($collection, $inboxCollections)) {
            $note = $entry;

            // 1. Fetch or build the immutable Content Shell (Markdown, Attachments, Sidecar JSON, Actor)
            $shell = $this->getOrBuildCardShell($note, $request, $includeParent, $userActors);
            if (!$shell) {
                return null;
            }

            // 2. Clone the shell and overlay volatile, dynamic interaction state & user status
            $data = $shell;
            $data['counts'] = [
                'replies' => (int) $note->get('reply_count', 0),
                'boosts'  => (int) $note->get('boost_count', 0),
                'likes'   => (int) $note->get('like_count', 0),
            ];
            $data['liked_by_user'] = $this->checkIfLiked($note, $userActors);
            $data['boosted_by_user'] = $this->checkIfBoosted($note, $userActors);
            $data['related_activity_count'] = (int) $note->get('related_activity_count', 0);

            // Execute addon transform hooks
            \Ethernick\ActivityPubCore\Services\ActivityPubTypes::executeTransformHooks($entry, $data);

            return $data;
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

            // Handle Statamic relationship arrays (e.g. ['uuid'])
            if (is_array($object) && !isset($object['type']) && isset($object[0])) {
                $object = $object[0];
            }

            // Re-implement the nesting check if $includeParent is true (meaning main stream context)
            if ($includeParent) {
                $objId = null;
                if (is_array($object)) {
                    $objId = $object['id'] ?? $object[0] ?? null;
                } else {
                    $objId = $object;
                }

                if ($objId) {
                    // Direct indexed lookup against CustomCollectionEntriesStore (indexed activitypub_id: ~2ms vs ~25ms)
                    $exists = Entry::query()
                        ->where('collection', 'notes')
                        ->where('activitypub_id', $objId)
                        ->first();

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
            $objectSummary = is_array($object) ? ($object['name'] ?? $object['summary'] ?? null) : null;

            // If object is just a string URL, try to resolve its type/summary from local system
            if (is_string($object)) {
                $localEntry = Entry::find($object);
                if (!$localEntry) {
                    $baseUrl = \Statamic\Facades\Site::selected()->absoluteUrl();
                    if (\Illuminate\Support\Str::startsWith($object, $baseUrl)) {
                        $uri = '/' . ltrim(str_replace($baseUrl, '', $object), '/');
                        $localEntry = Entry::findByUri($uri);
                    }
                }

                if ($localEntry) {
                    $localActorId = $localEntry->get('actor');
                    if (is_array($localActorId))
                        $localActorId = $localActorId[0] ?? null;
                    $objectActorId = $localActorId;

                    $handle = $localEntry->collection()->handle();
                    $objectType = ($handle === 'notes') ? 'Note' : (($handle === 'places') ? 'Place' : ucfirst(\Illuminate\Support\Str::singular($handle)));
                    $objectSummary = $localEntry->get('title') ?? $localEntry->get('name');

                    // If summary is a UUID or empty, try content field
                    if (!$objectSummary || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) $objectSummary)) {
                        $entryContent = $localEntry->get('content');
                        if ($entryContent) {
                            $objectSummary = \Illuminate\Support\Str::limit(strip_tags($entryContent), 100);
                        }
                    }
                }
            }

            if ($type === 'Follow') {
                $objectType = 'Actor';
            }

            // Fallback: If we still have a generic 'Object' OR a UUID summary, try to peek into the activitypub_json
            $isUuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) $objectSummary);
            if ($apJson && ($objectType === 'Object' || $isUuid)) {
                $nestedObj = is_array($payload) ? ($payload['object'] ?? null) : null;
                if (is_array($nestedObj) && isset($nestedObj['type'])) {
                    $objectType = $nestedObj['type'];

                    // If we had a UUID or nothing, try to get a better name/summary/content
                    if (!$objectSummary || $isUuid) {
                        $newSummary = $nestedObj['name'] ?? $nestedObj['summary'] ?? null;
                        if (!$newSummary && in_array($nestedObj['type'], ['Question', 'Note', 'Place'])) {
                            $summaryText = strip_tags($nestedObj['content'] ?? '');
                            $newSummary = \Illuminate\Support\Str::limit($summaryText, 100);
                        }
                        if ($newSummary) {
                            $objectSummary = $newSummary;
                        }
                    }
                }
            }

            $actContent = $activity->get('content');
            if ($actContent) {
                $summary = $actContent . ' <br><a href="' . $objectUrl . '" target="_blank">' . $objectUrl . '</a>';
            } else {
                $summary = sprintf(
                    '%s %s %s <a href="%s" target="_blank">%s</a>',
                    $type,
                    $objectType,
                    $objectSummary ? "({$objectSummary})" : "",
                    $objectUrl,
                    $objectUrl
                );
            }

            // Fix apJson variable scope if needed, assume it comes from entry

            $isMajor = in_array($type, ['Arrive', 'Travel', 'Offer', 'Listen', 'Question', 'Create', 'Event']);

            $data = [
                'type' => 'activity',
                'collection' => 'activities',
                'activity_type' => $type,
                'is_major' => $isMajor,
                'id' => $activity->id(),
                'title' => $activity->get('title'),
                'content' => $summary,
                'attachments' => [],
                'date' => $date ? $date->format('M j, Y H:i') : ($activity->date() ? $activity->date()->format('M j, Y H:i') : null),
                'date_human' => $date ? $date->diffForHumans() : ($activity->date() ? $activity->date()->diffForHumans() : null),
                'actor' => $actor,
                'actor_name' => $actor['name'] ?? ($activity->get('actor_name') ?? 'ActivityPub User'),
                'actions' => [
                    'view' => $objectUrl,
                ],
                'activitypub_json' => $apJson,
                'activitypub_id' => $activity->get('activitypub_id'),
                'object_type' => $objectType,
                'object_summary' => $objectSummary,
                'object_url' => $objectUrl,
                'object_actor_id' => $objectActorId ?? (is_array($object) ? ($object['attributedTo'] ?? $object['actor'] ?? null) : null),
                'tags' => $activity->get('tags', []),
            ];

            // Execute addon transform hooks (e.g. for Arrive activities)
            \Ethernick\ActivityPubCore\Services\ActivityPubTypes::executeTransformHooks($entry, $data);

            return $data;
        }

        return null;
    }

    /**
     * Get or build the cached immutable Content Shell for a note.
     * Caches CommonMark parsed HTML, sidecar JSON, attachments, and actor resolution.
     * Volatile interaction state (counts, liked_by_user, boosted_by_user) is NEVER cached here.
     */
    protected function getOrBuildCardShell(mixed $note, Request $request, bool $includeParent, array $userActors): ?array
    {
        $id = $note->id();
        $cacheKey = "ap:card_shell:{$id}";

        return \Illuminate\Support\Facades\Cache::rememberForever($cacheKey, function () use ($note, $request, $includeParent, $userActors) {
            $collection = $note->collection()->handle();

            $apJson = ActivityPubPayload::for($note)->raw();
            $payload = is_string($apJson) ? json_decode($apJson, true) : [];

            // Determine date: prioritize ActivityPub JSON updated timestamp first, then explicit entry date, published date, and fallback
            if (!empty($payload['updated'])) {
                $date = \Carbon\Carbon::parse($payload['updated']);
            } elseif ($rawEntryDate = ($note->get('date') ?? $note->get('start_time'))) {
                $date = \Carbon\Carbon::parse($rawEntryDate);
            } elseif (!empty($payload['published'])) {
                $date = \Carbon\Carbon::parse($payload['published']);
            } else {
                $date = $note->date();
            }

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

            $isBoost = $note->get('is_boost', false);
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
                $reblogOf = $note->get('reblog_of');
                if (is_array($reblogOf)) {
                    $reblogOf = $reblogOf[0] ?? null;
                }

                $originalNote = null;
                if ($reblogOf) {
                    $originalNote = Entry::find($reblogOf);
                }

                if ($originalNote) {
                    $boosterActor = $this->resolveActor($note->get('actor'), $request);
                    if ($boosterActor) {
                        $boostedBy = [
                            'name' => $boosterActor['name'],
                            'handle' => $boosterActor['handle']
                        ];
                    }
                    $note = $originalNote;
                    $actor = $this->resolveActor($note->get('actor'), $request);
                }
            }

            if (!$actor) {
                $actor = ['name' => 'Unknown', 'handle' => '@unknown', 'avatar' => null, 'url' => '#'];
            }

            $cleanContent = strip_tags(
                \Statamic\Facades\Markdown::parse((string) $note->get('content')),
                '<p><br><a><strong><em><u><i><b><blockquote><ul><ol><li><code><pre><img><span><div><h1><h2><h3><h4><h5><h6><del><s><strike>'
            );

            // Filter out items with no content and no attachments, unless it's a boost or a Poll
            if (empty(trim(strip_tags($cleanContent))) && empty($note->get('attachment', [])) && !$isBoost) {
                if (!isset($payload['votersCount']) && !isset($payload['oneOf']) && !isset($payload['anyOf'])) {
                    return null;
                }
            }

            // Attachments
            $attachments = [];
            if (isset($payload['attachment']) && is_array($payload['attachment'])) {
                foreach ($payload['attachment'] as $att) {
                    $type = $att['type'] ?? '';
                    if (in_array($type, ['Document', 'Image']) && str_starts_with($att['mediaType'] ?? '', 'image/')) {
                        $attachments[] = [
                            'type' => 'image',
                            'url' => $att['url'] ?? '',
                            'description' => $att['name'] ?? '',
                            'aspect_ratio' => ($att['width'] ?? 0) && ($att['height'] ?? 0) ? ($att['width'] / $att['height']) : null
                        ];
                    }
                }
            }

            $oembed = $note->get('oembed_data');
            $linkPreview = $note->get('link_preview');
            $needsPreview = false;

            if ($oembed === null && empty($linkPreview)) {
                if (LinkPreview::extractUrl($cleanContent)) {
                    $needsPreview = true;
                }
            }

            $obsUrl = $note->get('activitypub_id') ?: $note->absoluteUrl();

            // Parent Context
            $parent = null;
            if ($includeParent && $note->get('in_reply_to')) {
                $parentId = $note->get('in_reply_to');
                $parentEntry = Entry::find($parentId);
                if (!$parentEntry) {
                    $parentEntry = Entry::query()->where('collection', 'notes')->where('activitypub_id', $parentId)->first();
                }

                if ($parentEntry) {
                    $parent = $this->transformEntry($parentEntry, $request, $userActors, false);
                }
            }

            return [
                'type' => $collection === 'notes' ? 'note' : $collection,
                'collection' => $collection,
                'is_major' => true,
                'id' => $note->id(),
                'title' => ($collection === 'notes') ? null : $note->get('title'),
                'content' => $cleanContent,
                'content_raw' => $note->get('content'),
                'attachments' => $attachments,
                'oembed' => $oembed,
                'link_preview' => $linkPreview,
                'needs_preview' => $needsPreview,
                'date' => $date ? $date->format('M j, Y H:i') : null,
                'date_human' => $date ? $date->diffForHumans() : null,
                'actor' => $actor,
                'actor_name' => $actor['name'] ?? ($note->get('actor_name') ?? 'ActivityPub User'),
                'actions' => [
                    'reply' => cp_route('collections.entries.create', ['collection' => 'notes', 'site' => \Statamic\Facades\Site::selected()->handle(), 'in_reply_to' => $note->id()]),
                    'view' => $obsUrl,
                    'thread' => cp_route('activitypub.thread', ['id' => $note->id()]),
                ],
                'activitypub_json' => $apJson,
                'is_boost' => $isBoost ?? false,
                'boosted_by' => $boostedBy,
                'quote' => $this->resolveQuote($note),
                'sensitive' => (bool) $note->get('sensitive', false),
                'summary' => $note->get('summary'),
                'is_internal' => (bool) $note->get('is_internal', false),
                'parent' => $parent,
                'tags' => $note->get('tags', []),
            ];
        });
    }

    protected function resolveQuote(mixed $note): ?array
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

    protected function checkIfLiked(mixed $note, array $userActors): bool
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

    protected function checkIfBoosted(mixed $note, array $userActors): bool
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

    protected function getBoostCount(mixed $note): int
    {
        return (int) $note->get('boost_count', 0);
    }

    protected function getRelatedActivityCount(mixed $note): int
    {
        return (int) $note->get('related_activity_count', 0);
    }


}
