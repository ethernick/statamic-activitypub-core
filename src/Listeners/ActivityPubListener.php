<?php

namespace Ethernick\ActivityPubCore\Listeners;

use Statamic\Events\EntryBlueprintFound;
use Statamic\Events\EntrySaving;
use Statamic\Events\EntrySaved;
use Statamic\Events\TermBlueprintFound;
use Statamic\Events\TermSaving;
use Ethernick\ActivityPubCore\Jobs\SendActivityPubPost;
use Statamic\Facades\YAML;
use Statamic\Facades\File;
use Statamic\Facades\User;
use Statamic\Facades\Markdown;

class ActivityPubListener
{
    /**
     * Cache settings in memory to avoid repeated file reads
     */
    protected static $settingsCache = null;

    /**
     * Cache actors in memory to avoid repeated Entry::find() calls
     */
    protected static $actorCache = [];

    public function handle($event)
    {
        if ($event instanceof EntryBlueprintFound) {
            $this->handleBlueprintFound($event, $event->entry?->collection()->handle());
        }

        if ($event instanceof TermBlueprintFound) {
            $this->handleBlueprintFound($event, $event->term?->taxonomy()->handle());
        }

        if ($event instanceof EntrySaving) {
            $this->handleEntrySaving($event, $event->entry, $event->entry->collection()->handle());
        }

        if ($event instanceof EntrySaved) {
            $this->handleEntrySaved($event);
        }

        if ($event instanceof TermSaving) {
            // We likely don't need to generate AP JSON for taxonomy terms themselves in this context,
            // or if we do, we need a separate handler. For now, let's skip terms to fix the crash.
            // $this->handleEntrySaving($event, $event->term, $event->term->taxonomy()->handle());
        }
    }

    /**
     * Get cached settings to avoid repeated file reads
     */
    protected function getSettings()
    {
        if (self::$settingsCache !== null) {
            return self::$settingsCache;
        }

        $path = resource_path('settings/activitypub.yaml');
        if (!File::exists($path)) {
            self::$settingsCache = [];
            return [];
        }

        self::$settingsCache = YAML::parse(File::get($path));
        return self::$settingsCache;
    }

    /**
     * Get cached actor to avoid repeated Entry::find() calls
     */
    protected function getActor($actorId)
    {
        if (!$actorId) {
            return null;
        }

        // Normalize to string
        if (is_array($actorId)) {
            $actorId = $actorId[0] ?? null;
        }

        if (!$actorId) {
            return null;
        }

        // Check cache
        if (isset(self::$actorCache[$actorId])) {
            return self::$actorCache[$actorId];
        }

        // Load and cache
        $actor = \Statamic\Facades\Entry::find($actorId);
        self::$actorCache[$actorId] = $actor;

        return $actor;
    }

    protected function isEnabled($handle)
    {
        $settings = $this->getSettings();
        $config = $settings[$handle] ?? [];

        // Handle legacy boolean format or new array format
        if (is_bool($config)) {
            return $config;
        }

        return $config['enabled'] ?? false;
    }

    protected function getType($handle)
    {
        $settings = $this->getSettings();
        $config = $settings[$handle] ?? [];

        if (is_bool($config)) {
            return 'Object';
        }

        return $config['type'] ?? 'Object';
    }

    protected function handleBlueprintFound($event, $handle)
    {
        if (!$this->isEnabled($handle)) {
            return;
        }

        $blueprint = $event->blueprint;

        // Inject activitypub_json field
        if (!$blueprint->hasField('activitypub_json')) {
            $blueprint->ensureField('activitypub_json', [
                'type' => 'textarea',
                'display' => 'ActivityPub JSON',
                'visibility' => 'hidden', // Hide from UI but keep in data
                'read_only' => true,
            ]);
        }

        // Inject actor field if not present
        if (!$blueprint->hasField('actor')) {
            $blueprint->ensureField('actor', [
                'type' => 'actor_selector',
                'display' => 'Actor',
                'max_items' => 1,
                'collections' => ['actors'],
                'mode' => 'select',
            ]);
        }

        // Inject is_internal field if not present
        if (!$blueprint->hasField('is_internal')) {
            $blueprint->ensureField('is_internal', [
                'type' => 'toggle',
                'display' => 'Is Internal',
                'default' => true,
                'instructions' => 'Toggle on if this item is internal to the site.',
                'visibility' => ($handle === 'actors') ? 'visible' : 'read_only', // Only editable for actors
                'read_only' => ($handle !== 'actors'), // Enforce read-only for content
            ]);
        }
    }

    protected function handleEntrySaving($event, $entry, $handle)
    {
        if (!$this->isEnabled($handle)) {
            return;
        }

        // Track old quote_of value to detect if it's being added during edit
        if ($entry->id() && File::exists($entry->path())) {
            $oldData = YAML::parse(File::get($entry->path()));
            $oldQuoteOf = $oldData['quote_of'] ?? null;
            $entry->setSupplement('_old_quote_of', $oldQuoteOf);
        } else {
            $entry->setSupplement('_old_quote_of', null);
        }

        // 1. Ensure Actor is set
        $actorId = $entry->get('actor');
        if (!$actorId) {
            // Try to set from current user
            $user = User::current();
            if ($user) {
                $actors = $user->get('actors');
                if ($actors && count($actors) > 0) {
                    $entry->set('actor', $actors[0]);
                    $actorId = $actors[0];
                }
            }
        }

        // 1.5 Handle is_internal flag
        if ($handle === 'actors') {
            // If linked to a user, force internal
            $user = User::current();
            if ($user && $user->get('actors') && in_array($entry->id(), $user->get('actors'))) {
                $entry->set('is_internal', true);
            }
            // Otherwise respect what was passed (default true in blueprint)
        } else {
            // For other entities, copy from actor
            if ($actorId) {
                if (is_array($actorId)) {
                    $actorId = $actorId[0] ?? null;
                }

                if ($actorId) {
                    $actor = $this->getActor($actorId);
                    if ($actor) {
                        $isInternal = $actor->get('is_internal', false);
                        $entry->set('is_internal', $isInternal);
                    }
                }
            }
        }

        // 2. Generate ActivityPub JSON
        // Only generate for internal items. External items should keep their original JSON.
        if ($entry->get('is_internal') !== false) {
            $type = $this->getType($handle);
            $json = $this->generateActivityPubJson($entry, $actorId, $type);
            $entry->set('activitypub_json', $json);
        }
    }

    protected function generateActivityPubJson($entry, $actorId, $type)
    {
        // Resolve Actor URL
        if (is_array($actorId)) {
            $actorId = $actorId[0] ?? null;
        }

        $actorUrl = $actorId;
        $actorHandle = null;
        if ($actorId) {
            $actor = $this->getActor($actorId);
            if ($actor) {
                $actorHandle = $actor->slug();
                $actorUrl = url("/@{$actorHandle}");
            }
        }

        $url = $entry->absoluteUrl();
        $published = now();
        if (method_exists($entry, 'date') && $entry->date()) {
            $published = $entry->date();
        }

        $data = [
            'type' => $type,
            'url' => $this->sanitizeUrl($url),
            'actor_url' => $this->sanitizeUrl($actorUrl),
            'published' => $published->toIso8601String(),
            'replies' => $this->sanitizeUrl(url('@' . ($actorHandle ?? 'unknown') . '/notes/' . $entry->slug() . '/replies')),
        ];

        // Special handling for Activities collection
        if ($entry->collection()->handle() === 'activities') {
            $activityType = $entry->get('type') ?? 'Create';
            if (is_array($activityType)) {
                $activityType = $activityType[0] ?? 'Create';
            }
            $data['type'] = $activityType;

            // Get the object
            $objectId = $entry->get('object');
            if (is_array($objectId)) {
                $objectId = $objectId[0] ?? null;
            }

            $objectJson = 'null';
            $objectSummary = "an object";

            // Special handling for Delete activities - use deleted_object_url if entry no longer exists
            if ($activityType === 'Delete' && $entry->get('deleted_object_url')) {
                $deletedUrl = $entry->get('deleted_object_url');
                $objectJson = json_encode($deletedUrl, JSON_UNESCAPED_SLASHES);
                $objectSummary = "a note";
            } elseif ($objectId) {
                $objectEntry = \Statamic\Facades\Entry::find($objectId);
                if ($objectEntry) {
                    // Recursively generate JSON for the object
                    $objectCollectionHandle = $objectEntry->collection()->handle();
                    $objectType = $this->getType($objectCollectionHandle);

                    $objectActorId = $objectEntry->get('actor');
                    // This returns a JSON string now
                    $objectJson = $this->generateActivityPubJson($objectEntry, $objectActorId, $objectType);

                    // Strip @context from embedded objects (they inherit from parent activity)
                    $objectData = json_decode($objectJson, true);
                    if ($objectData && isset($objectData['@context'])) {
                        unset($objectData['@context']);
                        $objectJson = json_encode($objectData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    }

                    $objectSummary = "a " . match ($objectType) {
                        'Note' => 'note',
                        'Question' => 'question',
                        default => 'article'
                    };
                }
            }

            // If we didn't find an entry object, check if we have a direct URL (e.g. for Likes on external objects)
            if ($objectJson === 'null') {
                $objectUrl = $entry->get('object_url');
                if ($objectUrl) {
                    $objectJson = json_encode($objectUrl);
                    $objectSummary = "an external object";
                }
            }

            $data['object_json'] = $objectJson;

            // Use content field for summary
            $summary = $entry->get('content');

            // Fallback if content is empty (e.g. old activities)
            if (!$summary) {
                $summary = "{$actorHandle} {$activityType}d {$objectSummary}";
                if (strtolower($activityType) === 'create')
                    $summary = "{$actorHandle} created {$objectSummary}";
                if (strtolower($activityType) === 'update')
                    $summary = "{$actorHandle} updated {$objectSummary}";
                if (strtolower($activityType) === 'delete')
                    $summary = "{$actorHandle} deleted {$objectSummary}";
            }

            $data['summary'] = $summary;

            // Add Addressing
            // Only for Create/Update?
            // "When people are making an item in a colleciton that is flagged that an activity is created" -> this is likely the activity itself.
            // The prompt says: "add the following json values to the activity"

            $data['to'] = ['https://www.w3.org/ns/activitystreams#Public'];
            $data['cc'] = [$actorUrl . '/followers'];

            if ($objectJson && $objectJson !== 'null') {
                $objData = json_decode($objectJson, true);
                if (is_array($objData)) {
                    if (isset($objData['to'])) {
                        $data['to'] = array_values(array_unique(array_merge($data['to'], (array) $objData['to'])));
                    }
                    if (isset($objData['cc'])) {
                        $data['cc'] = array_values(array_unique(array_merge($data['cc'], (array) $objData['cc'])));
                    }

                    // Specific handling for Announce: Add original author to CC if not already present
                    if ($activityType === 'Announce' && isset($objData['attributedTo'])) {
                        $attributedTo = $objData['attributedTo'];
                        if (is_array($attributedTo))
                            $attributedTo = $attributedTo[0] ?? null;
                        if ($attributedTo) {
                            $data['cc'][] = $attributedTo;
                            $data['cc'] = array_values(array_unique($data['cc']));
                        }
                    }
                }
            }

            $json = view('activitypub::json.activity', $data)->render();
            \Illuminate\Support\Facades\Log::info("ActivityPubListener: Generated Activity JSON", ['json' => $json]);
            return $json;
        }

        // Standard handling for other collections
        $content = $entry->get('content') ?? $entry->get('title');

        // Convert content to HTML
        if ($content) {
            $content = Markdown::parse((string) $content);
        }

        $data['content'] = $content;

        // 2.5 Handle Question/Poll Options
        if ($type === 'Question') {
            $options = $entry->get('options', []);
            $isMultiple = $entry->get('multiple_choice', false);
            $apOptions = [];

            foreach ($options as $opt) {
                $apOptions[] = [
                    'type' => 'Note',
                    'name' => $opt['name'],
                    'replies' => [
                        'type' => 'Collection',
                        'totalItems' => (int) ($opt['count'] ?? 0)
                    ]
                ];
            }

            if ($isMultiple) {
                $data['anyOf'] = $apOptions;
            } else {
                $data['oneOf'] = $apOptions;
            }

            if ($endTime = $entry->get('end_time')) {
                $data['endTime'] = \Carbon\Carbon::parse($endTime)->toIso8601String();
            }

            if ($entry->get('closed')) {
                // If we have a 'closed_date', use that, otherwise use updated_at or now
                // ActivityPub 'closed' is typically a date-time.
                $data['closed'] = $entry->date()->toIso8601String();
            }
        }

        // Check for specific template override
        $template = 'activitypub::json.default';
        // We could check for activitypub::json.note, etc. here in the future.

        // 3. Dynamic Counts and Interaction Collections
        $sanitizedUrl = $data['url'];
        $absoluteUrl = $entry->absoluteUrl();

        // Mentions & Addressing
        $mentions = $this->extractMentions($content);
        $tags = [];
        $cc = [$actorUrl . '/followers'];
        $to = ['https://www.w3.org/ns/activitystreams#Public']; // Default to public

        foreach ($mentions as $mention) {
            $tags[] = [
                'type' => 'Mention',
                'href' => $mention['href'],
                'name' => $mention['name'],
            ];
            $cc[] = $mention['href'];
        }

        $data['to'] = $to;
        $data['cc'] = $cc;
        $data['tag'] = $tags;

        // Add content warning fields (sensitive/summary)
        if ($entry->get('sensitive')) {
            $data['sensitive'] = true;
            $data['summary'] = $entry->get('summary');
        }

        // Add quote authorization stamp to tags if present (FEP-044f)
        $authStamp = $entry->get('quote_authorization_stamp');
        if ($authStamp) {
            $data['tag'][] = [
                'type' => 'Link',
                'mediaType' => 'application/ld+json; profile="https://www.w3.org/ns/activitystreams"',
                'href' => $authStamp,
                'rel' => 'https://w3id.org/fep/044f#quoteAuthorization',
                'name' => 'Quote authorization',
            ];
        }

        // Count Likes
        $likesCount = \Statamic\Facades\Entry::query()
            ->where('collection', 'activities')
            ->where('type', '=', 'Like')
            ->get()
            ->filter(function ($act) use ($sanitizedUrl, $absoluteUrl) {
                $obj = $act->get('object');
                return $obj === $sanitizedUrl || $obj === $absoluteUrl;
            })->count();

        // Count Shares (Announce)
        $sharesCount = \Statamic\Facades\Entry::query()
            ->where('collection', 'activities')
            ->where('type', '=', 'Announce')
            ->get()
            ->filter(function ($act) use ($sanitizedUrl, $absoluteUrl) {
                $obj = $act->get('object');
                return $obj === $sanitizedUrl || $obj === $absoluteUrl;
            })->count();

        // Base Interaction URL
        $interactionBase = url('@' . ($actorHandle ?? 'unknown') . ($entry->collection()->handle() === 'articles' ? '/articles/' : '/notes/') . $entry->slug());

        $data['likes_count'] = $likesCount;
        $data['likes_url'] = $interactionBase . '/likes';
        $data['shares_count'] = $sharesCount;
        $data['shares_url'] = $interactionBase . '/shares';

        // interactionPolicy - load from cached settings
        $settings = $this->getSettings();
        $allowQuotes = $settings['allow_quotes'] ?? false;

        $data['interaction_policy'] = [
            'canQuote' => [
                'automaticApproval' => $allowQuotes ? ['https://www.w3.org/ns/activitystreams#Public'] : []
            ]
        ];

        // If this is a quote post, add quoteUrl
        $quoteOf = $entry->get('quote_of');

        // Normalize quote_of to array format (backward compatibility for string values)
        if ($quoteOf && is_string($quoteOf)) {
            $quoteOf = [$quoteOf];
            $entry->set('quote_of', $quoteOf);
        }

        if ($quoteOf && is_array($quoteOf) && count($quoteOf) > 0) {
            $quotedEntry = \Statamic\Facades\Entry::find($quoteOf[0]);
            if ($quotedEntry) {
                // Use ActivityPub ID if available (for federation), fallback to absoluteUrl
                $quoteUrl = $quotedEntry->get('activitypub_id') ?: $quotedEntry->absoluteUrl();
                $data['quote_url'] = $quoteUrl;

                // Append RE: link to content for Mastodon compatibility
                // Mastodon parses this and converts it to an embedded quote card
                if (!empty($data['content'])) {
                    $data['content'] = rtrim($data['content']) . '<br><br>RE: <a href="' . htmlspecialchars($quoteUrl, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($quoteUrl, ENT_QUOTES, 'UTF-8') . '</a>';
                }

                // Include authorization stamp if present (FEP-044f)
                $authStamp = $entry->get('quote_authorization_stamp');
                if ($authStamp) {
                    $data['quote_authorization_stamp'] = $authStamp;
                }
            }
        }

        return view($template, $data)->render();
    }

    protected function extractMentions($html)
    {
        $mentions = [];
        if (preg_match_all('/<a[^>]+href="([^"]+)"[^>]*>(@.*?)<\/a>/i', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                // $match[1] is href, $match[2] is text content (handle)
                // Filter out obviously non-actor URLs if needed, but for now rely on @ prefix
                $mentions[] = [
                    'href' => $match[1],
                    'name' => strip_tags($match[2]), // Ensure clean text
                ];
            }
        }
        return $mentions;
    }

    protected function sanitizeUrl($url)
    {
        return str_replace('://www.', '://', $url);
    }

    protected function handleEntrySaved($event)
    {
        $entry = $event->entry;

        // Only trigger for Internal items
        if ($entry->get('is_internal') === false) {
            return;
        }

        // Check if quote_of was added during edit (for notes/polls/articles)
        $collection = $entry->collection()->handle();
        if (in_array($collection, ['notes', 'polls', 'articles'])) {
            $oldQuoteOf = $entry->getSupplement('_old_quote_of');
            $newQuoteOf = $entry->get('quote_of');

            // If quote_of was added (changed from empty to having a value)
            if (empty($oldQuoteOf) && !empty($newQuoteOf)) {
                \Illuminate\Support\Facades\Log::info("ActivityPubListener: Quote added via edit, dispatching SendQuoteRequest", [
                    'entry' => $entry->id(),
                    'quote_of' => $newQuoteOf
                ]);
                \Ethernick\ActivityPubCore\Jobs\SendQuoteRequest::dispatch($entry->id())->onQueue('activitypub-outbox');
            }
        }

        // Only trigger for activities? OR notes as well?
        // "When people are making an item in a colleciton that is flagged that an activity is created, when the activity is created"
        // This implies we send the ACTIVITY.
        // So we should verify if this is an 'activities' entry or if we send the note itself?
        // AP usually wraps objects in Create activities.
        // Statamic's AutoGenerateActivityListener creates an 'activities' entry.
        // So we should probably listen for 'activities' entries being saved.

        if ($entry->collection()->handle() === 'activities') {
            // Dispatch to queue instead of running immediately
            SendActivityPubPost::dispatch($entry->id())->onQueue('activitypub-outbox');
        }
    }
}
