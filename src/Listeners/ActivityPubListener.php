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
use Statamic\Facades\Blink;

class ActivityPubListener
{


    /**
     * Cache actors in memory to avoid repeated Entry::find() calls
     */
    protected static $actorCache = [];

    public function handle(mixed $event): void
    {
        if ($event instanceof EntryBlueprintFound) {
            $handle = $event->entry?->collection()?->handle();
            if ($handle !== null) {
                $this->handleBlueprintFound($event, $handle);
            }
        }

        if ($event instanceof TermBlueprintFound) {
            $handle = $event->term?->taxonomy()?->handle();
            if ($handle !== null) {
                $this->handleBlueprintFound($event, $handle);
            }
        }

        if ($event instanceof EntrySaving) {
            $this->handleEntrySaving($event);
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
    /**
     * Get cached settings to avoid repeated file reads
     */
    protected function getSettings(): array
    {
        return Blink::once('activitypub-settings', function () {
            $path = resource_path('settings/activitypub.yaml');
            if (!File::exists($path)) {
                return [];
            }
            return YAML::parse(File::get($path));
        });
    }

    /**
     * Get cached actor to avoid repeated Entry::find() calls
     */
    protected function getActor(mixed $actorId): ?\Statamic\Entries\Entry
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

    protected function isEnabled(string $handle): bool
    {
        $settings = $this->getSettings();
        $config = $settings[$handle] ?? [];

        // Handle legacy boolean format or new array format
        if (is_bool($config)) {

            return $config;
        }

        $enabled = $config['enabled'] ?? false;

        return $enabled;
    }

    protected function getType(string $handle): string
    {
        $settings = $this->getSettings();
        $config = $settings[$handle] ?? [];

        if (is_bool($config)) {
            return 'Object';
        }

        return $config['type'] ?? 'Object';
    }

    protected function handleBlueprintFound(mixed $event, string $handle): void
    {
        if (!$this->isEnabled($handle)) {
            return;
        }



        $blueprint = $event->blueprint;

        // Inject activitypub_json field
        if (!$blueprint->hasField('activitypub_json')) {
            $blueprint->ensureField('activitypub_json', [
                'type' => 'textarea', // Use 'textarea' for JSON storage
                'display' => 'ActivityPub JSON',
                'visibility' => 'hidden',
                'read_only' => false,
            ]);
        } else {
            // Field exists, force read_only to false
            $field = $blueprint->field('activitypub_json');
            if ($field) {
                $config = $field->config();
                $config['read_only'] = false;
                $config['visibility'] = 'visible'; // Debug visibility
                $field->setConfig($config);
            }
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

    public function handleEntrySaving(EntrySaving $event): void
    {
        $entry = $event->entry;
        $handle = $entry->collection()->handle();

        // \Illuminate\Support\Facades\Log::info("ActivityPubListener: handleEntrySaving START for {$entry->id()} in {$handle}");

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

        \Illuminate\Support\Facades\Log::info("ActivityPubListener: handleEntrySaving for {$entry->id()}", [
            'is_internal' => $entry->get('is_internal'),
            'actor' => $entry->get('actor'),
            'handle' => $handle,
            'enabled' => $this->isEnabled($handle),
        ]);

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
                    } else {

                    }
                }
            }
        }

        // 2. Generate ActivityPub JSON
        // Only generate for internal items. External items should keep their original JSON.
        // Logic above ensures is_internal is synced with actor.
        $shouldGen = $entry->get('is_internal');

        if ($shouldGen !== false) {
            try {
                $type = $this->getType($handle);
                $json = $this->generateActivityPubJson($entry, $actorId, $type);
                $entry->set('activitypub_json', $json);

            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("ActivityPubListener: Error generating JSON: " . $e->getMessage(), [
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }
    }

    protected function generateActivityPubJson(mixed $entry, mixed $actorId, string $type): string
    {
        \Illuminate\Support\Facades\Log::info("ActivityPubListener: Generating JSON for {$entry->id()}", [
            'type' => $type,
            'collection' => $entry->collection()->handle()
        ]);

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
            '@context' => [
                'https://www.w3.org/ns/activitystreams',
                [
                    'quote' => 'https://w3id.org/fep/044f#quote',
                    'quoteUri' => 'http://fedibird.com/ns#quoteUri',
                    '_misskey_quote' => 'https://misskey-hub.net/ns#_misskey_quote',
                    'quoteUrl' => 'https://w3id.org/fep/044f#quoteUrl', // Wait, quoteUrl is property? Yes.
                    'quoteAuthorization' => [
                        '@id' => 'https://w3id.org/fep/044f#quoteAuthorization',
                        '@type' => '@id',
                    ],
                    'interactionPolicy' => [
                        '@id' => 'gts:interactionPolicy',
                        '@type' => '@id',
                    ],
                    'gts' => 'https://gotosocial.org/ns#',
                ]
            ],
            'id' => $this->sanitizeUrl($url),
            'type' => $type,
            'actor' => $this->sanitizeUrl($actorUrl),
            'published' => $published->toIso8601String(),
            'url' => $this->sanitizeUrl($url),
            'attributedTo' => $this->sanitizeUrl($actorUrl),
            'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            'cc' => [$actorUrl . '/followers'],
        ];

        // Content
        if ($entry->has('content')) {
            $data['content'] = $entry->get('content');
        }

        // Summary / CW
        if ($entry->has('summary')) {
            $data['summary'] = $entry->get('summary');
        } elseif ($entry->has('cw')) {
            $data['summary'] = $entry->get('cw');
        }

        // Sensitive
        if ($entry->has('sensitive')) {
            $data['sensitive'] = (bool) $entry->get('sensitive');
        } else {
            $data['sensitive'] = false;
        }

        // Tags
        $tags = [];
        $mentions = $this->extractMentions($data['content'] ?? '');
        foreach ($mentions as $mention) {
            $tags[] = [
                'type' => 'Mention',
                'href' => $mention['href'],
                'name' => $mention['name'],
            ];
            $data['cc'][] = $mention['href'];
        }

        // Hashtags logic if needed...

        if (!empty($tags)) {
            $data['tag'] = $tags;
        }

        // Attachments
        if ($assetId = $entry->get('attachment')) { // Single attachment for now
            // Simplified attachment handling
            // In a real implementation we'd resolve the asset
        }

        // Replies collection
        $data['replies'] = [
            'id' => $url . '/replies',
            'type' => 'Collection',
            'first' => [
                'type' => 'CollectionPage',
                'next' => $url . '/replies?page=1',
                'partOf' => $url . '/replies',
                'items' => []
            ]
        ];

        // --- QUOTE LOGIC ---
        // Add quote fields if this is a quote
        $quoteOf = $entry->get('quote_of');
        if (is_array($quoteOf)) {
            $quoteOf = $quoteOf[0] ?? null;
        }

        if ($quoteOf) {
            // Check if it's an internal ID or external URL
            if (\Illuminate\Support\Str::isUuid($quoteOf)) {
                $quotedEntry = \Statamic\Facades\Entry::find($quoteOf);
                if ($quotedEntry) {
                    $quotedUrl = $quotedEntry->get('activitypub_id') ?: $quotedEntry->absoluteUrl();
                } else {
                    $quotedUrl = null;
                }
            } else {
                // Assume external URL from supplement or just use ID if it looks like URL?
                // Usually quote_of stores ID.
                // If external, Statamic stores ID but we need URL.
                // Wait, if external quote, how is it stored?
                // Usually external actors are stored as Entries.
                // Quoted external notes might be stored as Entries too?
                $quotedEntry = \Statamic\Facades\Entry::find($quoteOf);
                if ($quotedEntry) {
                    $quotedUrl = $quotedEntry->get('activitypub_id');
                } else {
                    $quotedUrl = $quoteOf; // Fallback?
                }
            }

            if ($quotedUrl) {
                // Determine quoteUrl vs quote
                // FEP-044f says 'quoteUrl' is Link/Url, 'quote' is Object/Url.
                // We use both for compatibility. Misskey uses _misskey_quote.
                $data['quoteUrl'] = $quotedUrl;
                $data['quote'] = $quotedUrl;
                $data['_misskey_quote'] = $quotedUrl;

                // Add Authorization Status/Stamp if present
                $authStamp = $entry->get('quote_authorization_stamp');
                if ($authStamp) {
                    $data['quoteAuthorization'] = $authStamp;
                }
            }
        }

        // Interaction Policy (GTS)
        $data['interactionPolicy'] = [
            'id' => $url . '#interaction-policy',
            'type' => 'gts:interactionPolicy',
            'canQuote' => [
                'type' => 'gts:interactionPolicyRule',
                // Public by default for now
                'automaticApproval' => ['https://www.w3.org/ns/activitystreams#Public']
            ]
        ];


        // Special handling for Activities collection (overwrite type, object, summary)
        if ($entry->collection()->handle() === 'activities') {
            $activityType = $entry->get('type') ?? 'Create';
            if (is_array($activityType)) {
                $activityType = $activityType[0] ?? 'Create';
            }
            $data['type'] = $activityType;

            // Remove Note specific fields if wrapping activity?
            // Usually Activity wraps Note in 'object'.
            // But here we are generating JSON for the entry itself.
            // If the entry IS an activity, it points to an object.

            unset($data['content']);
            unset($data['sensitive']);
            unset($data['attachment']);
            unset($data['tag']);
            unset($data['quoteUrl']); // Quote is on valid object, not activity wrapper usually?

            // Get the object
            $objectId = $entry->get('object');
            if (is_array($objectId)) {
                $objectId = $objectId[0] ?? null;
            }

            $objectJson = 'null';
            $objectSummary = "an object";

            // Special handling for Delete activities - use deleted_object_url
            if ($activityType === 'Delete' && $entry->get('deleted_object_url')) {
                $deletedUrl = $entry->get('deleted_object_url');
                $objectJson = json_encode($deletedUrl, JSON_UNESCAPED_SLASHES);
                $objectSummary = "a note";
            } elseif ($objectId) {
                // Recursion for object
                $objectEntry = \Statamic\Facades\Entry::find($objectId);
                if ($objectEntry) {

                    // Get object JSON (recursive call)
                    $objectCollectionHandle = $objectEntry->collection()->handle();
                    $objectType = $this->getType($objectCollectionHandle);
                    $objectActorId = $objectEntry->get('actor');

                    // Avoid infinite recursion?
                    $objectJson = $this->generateActivityPubJson($objectEntry, $objectActorId, $objectType);


                    // Decode
                    $chk = json_decode($objectJson, true);


                    // Decode to strip @context
                    $objectData = json_decode($objectJson, true);
                    if ($objectData && isset($objectData['@context'])) {
                        unset($objectData['@context']);
                        $objectJson = json_encode($objectData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    }
                } else {

                }
            }
            // If we didn't find entry object, handle external object URL
            if ($objectJson === 'null') {
                $objectUrl = $entry->get('object_url');
                if ($objectUrl) {
                    $objectJson = json_encode($objectUrl);
                    $objectSummary = "an external object";
                }
            }

            if ($objectJson !== 'null') {
                $data['object'] = json_decode($objectJson, true);
            }

            if (isset($data['object']) && is_array($data['object'])) {
                if (isset($data['object']['to'])) {
                    $data['to'] = array_values(array_unique(array_merge($data['to'], (array) $data['object']['to'])));
                }
                if (isset($data['object']['cc'])) {
                    $data['cc'] = array_values(array_unique(array_merge($data['cc'], (array) $data['object']['cc'])));
                }
            }
        }

        // Standard handling for other collections
        $content = $entry->get('content') ?? $entry->get('title');

        // Convert content to HTML
        if ($content) {
            $content = \Statamic\Facades\Markdown::parse((string) $content);
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

        $data['to'] = array_values(array_unique(array_merge($data['to'] ?? [], $to)));
        $data['cc'] = array_values(array_unique(array_merge($data['cc'] ?? [], $cc)));
        $data['tag'] = array_merge($data['tag'] ?? [], $tags);


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

        $json = view($template, $data)->render();

        return $json;
    }

    protected function extractMentions(?string $html): array
    {
        if ($html === null || $html === '') {
            return [];
        }

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

    protected function sanitizeUrl(?string $url): string
    {
        if ($url === null) {
            return '';
        }

        // Remove trailing slashes
        return rtrim($url, '/');
    }

    protected function handleEntrySaved(mixed $event): void
    {
        $entry = $event->entry;

        // Only trigger for Internal items
        if ($entry->get('is_internal') === false) {
            return;
        }

        // Check if quote_of was added during edit (for notes/polls/articles)
        $collection = $entry->collection()->handle();
        \Illuminate\Support\Facades\Log::info("ActivityPubListener: handleEntrySaved for {$entry->id()} in {$collection}", [
            'is_internal' => $entry->get('is_internal'),
            'old_quote_of' => $entry->getSupplement('_old_quote_of'),
            'new_quote_of' => $entry->get('quote_of'),
        ]);

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
