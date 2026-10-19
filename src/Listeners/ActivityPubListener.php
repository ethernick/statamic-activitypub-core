<?php


namespace Ethernick\ActivityPubCore\Listeners;

use Statamic\Events\EntryBlueprintFound;
use Statamic\Events\EntrySaving;
use Statamic\Events\EntrySaved;
use Statamic\Events\EntryDeleted;
use Statamic\Events\TermBlueprintFound;
use Statamic\Events\TermSaving;
use Ethernick\ActivityPubCore\Jobs\SendActivityPubPost;
use Statamic\Facades\YAML;
use Statamic\Facades\File;
use Statamic\Facades\User;
use Statamic\Facades\Blink;
use Ethernick\ActivityPubCore\Services\ActivityPubTypes;
use Ethernick\ActivityPubCore\Services\ActivityPubUtils;
use Ethernick\ActivityPubCore\Contracts\OutboxHandlerInterface;
use Ethernick\ActivityPubCore\Transformers\ActivityPubObjectTransformer;
use Ethernick\ActivityPubCore\Storage\ActivityPubPayload;
use Statamic\Entries\Entry;

class ActivityPubListener
{


    /**
     * Cache actors in memory to avoid repeated Entry::find() calls
     */
    protected static $actorCache = [];

    /**
     * Track entry IDs currently being saved to prevent infinite loops / blueprint re-injection
     */
    protected static array $savingEntries = [];

    public static function setSaving(?string $id, bool $saving): void
    {
        if (!$id) {
            return;
        }
        if ($saving) {
            static::$savingEntries[$id] = true;
        } else {
            unset(static::$savingEntries[$id]);
        }
    }

    /**
     * Cache settings in memory
     */
    /**
     * Cache settings in memory
     */
    protected static $settingsCache = [];

    public function handle(mixed $event): void
    {
        if ($event instanceof EntryBlueprintFound) {
            $handle = $event->entry?->collection()?->handle();

            // Handle creation view where entry is null
            if ($handle === null && $event->blueprint->namespace()) {
                $namespace = $event->blueprint->namespace();
                if (str_starts_with($namespace, 'collections.')) {
                    $handle = str_replace('collections.', '', $namespace);
                }
            }

            if ($handle !== null) {
                $this->handleBlueprintFound($event, $handle);
            }
        }

        if ($event instanceof TermBlueprintFound) {
            $handle = $event->term?->taxonomy()?->handle();

            // Handle creation view where term is null
            if ($handle === null && $event->blueprint->namespace()) {
                $namespace = $event->blueprint->namespace();
                if (str_starts_with($namespace, 'taxonomies.')) {
                    $handle = str_replace('taxonomies.', '', $namespace);
                }
            }

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

        if ($event instanceof EntryDeleted) {
            $this->handleEntryDeleted($event);
        }

        if ($event instanceof TermSaving) {
            // We likely don't need to generate AP JSON for taxonomy terms themselves in this context,
            // or if we do, we need a separate handler. For now, let's skip terms to fix the crash.
            // $this->handleEntrySaving($event, $event->term, $event->term->taxonomy()->handle());
        }
    }

    protected function getSettings(string $collection = 'all'): ?array
    {
        if (!app()->runningUnitTests() && isset(self::$settingsCache[$collection])) {
            return self::$settingsCache[$collection];
        }

        $path = ActivityPubUtils::settingsPath();
        if (!File::exists($path)) {
            return null;
        }

        $raw = File::get($path);
        $settings = YAML::parse($raw);
        self::$settingsCache[$collection] = $settings;
        return $settings;
    }

    /**
     * Clear all static caches (useful for testing)
     */
    public static function clearCaches(): void
    {
        self::$settingsCache = [];
        self::$actorCache = [];
    }

    /**
     * Clear the settings cache (useful for testing)
     */
    public static function clearSettingsCache(): void
    {
        self::$settingsCache = [];
    }

    /**
     * Clear the actor cache (useful for testing)
     */
    public static function clearActorCache(): void
    {
        self::$actorCache = [];
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

        // Inject actor_type field into actors collection if not present
        if ($handle === 'actors' && !$blueprint->hasField('actor_type')) {
            $blueprint->ensureField('actor_type', [
                'type' => 'select',
                'display' => 'Actor Type',
                'instructions' => 'The ActivityPub Actor type for this profile.',
                'default' => 'Person',
                'options' => [
                    'Person' => 'Person (Individual human)',
                    'Organization' => 'Organization (Company, Team, Publication)',
                    'Service' => 'Service (Bot, Automated publishing agent)',
                    'Application' => 'Application (Software client)',
                    'Group' => 'Group (Community collective)',
                ],
                'validate' => ['required'],
            ]);
        }

        // Hashtag field injection
        $settings = $this->getSettings();
        $hashtagSettings = $settings['hashtags'] ?? [];
        if ($hashtagSettings['enabled'] ?? false) {
            $field = $hashtagSettings['field'] ?? 'tags';
            $taxonomy = $hashtagSettings['taxonomy'] ?? 'tags';
            if (!$blueprint->hasField($field)) {
                $blueprint->ensureField($field, [
                    'type' => 'terms',
                    'display' => ucfirst($field),
                    'taxonomies' => [$taxonomy],
                    'mode' => 'tags',
                ]);
            }
        }

        // Inject activitypub_json field into the Advanced section of the main tab
        // We do this LAST to ensure other dynamically ensured fields don't fall into the Advanced section.
        // Resolve the target tab (usually 'main' or the first tab)
        $contents = $blueprint->contents();
        $tabs = $contents['tabs'] ?? [];
        $targetTab = isset($tabs['main']) ? 'main' : (array_key_first($tabs) ?: 'main');

        // Unconditionally remove to ensure we don't have duplicates across sections of the same tab.
        // Statamic's removeField() clears internal caches.
        $blueprint->removeField('activitypub_json');
        $blueprint->removeField('activitypub_json_manual');

        // Re-fetch contents after removals.
        $contents = $blueprint->contents();
        $tabs = $contents['tabs'] ?? [];

        $jsonConfig = [
            'type' => 'code',
            'mode' => 'javascript',
            'mode_selectable' => false,
            'line_numbers' => false,
            'line_wrapping' => true,
            'display' => 'ActivityPub JSON',
            'instructions' => 'Advanced: Override the generated ActivityPub payload or view the current payload. Must be valid JSON.',
            'validate' => [
                'nullable',
                new \Ethernick\ActivityPubCore\Rules\ActivityPubJson,
            ],
        ];

        $manualToggleConfig = [
            'type' => 'toggle',
            'display' => 'Manual JSON Override',
            'instructions' => 'If enabled, the system will not auto-generate or update the JSON payload below.',
            'default' => false,
        ];

        if (!isset($tabs[$targetTab]['sections'])) {
            $tabs[$targetTab]['sections'] = [];
        }

        $advancedSectionIndex = null;
        foreach ($tabs[$targetTab]['sections'] as $i => $section) {
            if (isset($section['display']) && strtolower($section['display']) === 'advanced') {
                $advancedSectionIndex = $i;
                break;
            }
        }

        if ($advancedSectionIndex === null) {
            $tabs[$targetTab]['sections'][] = [
                'display' => 'Advanced',
                'fields' => []
            ];
            $advancedSectionIndex = count($tabs[$targetTab]['sections']) - 1;
        }

        // Add Manual Toggle First in section
        $tabs[$targetTab]['sections'][$advancedSectionIndex]['fields'][] = [
            'handle' => 'activitypub_json_manual',
            'field' => $manualToggleConfig
        ];

        // Add Single JSON Field
        $tabs[$targetTab]['sections'][$advancedSectionIndex]['fields'][] = [
            'handle' => 'activitypub_json',
            'field' => $jsonConfig
        ];

        $contents['tabs'] = $tabs;
        $blueprint->setContents($contents);

        if ($event instanceof EntryBlueprintFound && $event->entry) {
            $entryId = $event->entry->id();
            if ($entryId && isset(static::$savingEntries[$entryId])) {
                return;
            }
            $json = ActivityPubPayload::for($event->entry)->raw();
            if ($json) {
                $event->entry->set('activitypub_json', $json);
            }
        }
    }

    public function handleEntrySaving(EntrySaving $event): void
    {
        $entry = $event->entry;
        static::setSaving($entry->id(), true);
        $handle = $entry->collection()->handle();

        // Allow plugins to extend saving logic
        ActivityPubTypes::executeStoreHooks($entry);

        // \Illuminate\Support\Facades\Log::info("ActivityPubListener: handleEntrySaving START for {$entry->id()} in {$handle}");

        if (!$this->isEnabled($handle)) {
            return;
        }

        // Grab any activitypub_json currently set on the entry
        $incomingJson = $entry->get('activitypub_json');
        if (is_array($incomingJson) && isset($incomingJson['code'])) {
            $incomingJson = $incomingJson['code'];
        }
        if ($incomingJson) {
            $entry->setSupplement('activitypub_json', $incomingJson);
            $entry->remove('activitypub_json');
        }

        // Check for manual override early
        if ($entry->get('activitypub_json_manual')) {
            if (!$entry->getSupplement('activitypub_json')) {
                $existing = ActivityPubPayload::for($entry)->raw();
                if ($existing) {
                    $entry->setSupplement('activitypub_json', $existing);
                }
            }
            \Illuminate\Support\Facades\Log::info("ActivityPubListener: Skipping JSON generation due to manual override for {$entry->id()}");
            return;
        }

        // Track existing entry data from disk to ensure date immutability and content diffing
        $oldData = null;
        $contentChanged = true;
        if ($entry->id() && File::exists($entry->path())) {
            try {
                $oldData = YAML::parse(File::get($entry->path()));
            } catch (\Throwable $e) {
                $oldData = null;
            }
        }

        if (is_array($oldData)) {
            // 1. IMMUTABILITY: published creation date can NEVER be altered on existing entries
            $existingPublished = $oldData['date'] ?? null;
            if ($existingPublished) {
                try {
                    $origCarbon = \Illuminate\Support\Carbon::parse($existingPublished);
                    $entry->date($origCarbon);
                    $entry->set('date', $origCarbon->toIso8601String());
                } catch (\Throwable $e) {
                    // ignore
                }
            }

            // 2. CONTENT CHANGE DETECTION: Check if genuine content, title, summary, or attachments changed
            $oldContent = (string) ($oldData['content'] ?? '');
            $newContent = (string) ($entry->get('content') ?? '');
            $oldTitle = (string) ($oldData['title'] ?? '');
            $newTitle = (string) ($entry->get('title') ?? '');
            $oldSummary = (string) ($oldData['summary'] ?? '');
            $newSummary = (string) ($entry->get('summary') ?? '');
            $oldSensitive = (bool) ($oldData['sensitive'] ?? false);
            $newSensitive = (bool) ($entry->get('sensitive') ?? false);
            $oldAttachments = $oldData['attachments'] ?? [];
            $newAttachments = $entry->get('attachments', []);

            $contentChanged = ($oldContent !== $newContent)
                || ($oldTitle !== $newTitle)
                || ($oldSummary !== $newSummary)
                || ($oldSensitive !== $newSensitive)
                || (json_encode($oldAttachments) !== json_encode($newAttachments));

            // Check registered type-specific substantive fields (e.g. Poll options/counts, Event dates/venues)
            if (!$contentChanged) {
                $substantiveFields = \Ethernick\ActivityPubCore\Services\ActivityPubTypes::getSubstantiveFields($handle);
                foreach ($substantiveFields as $field) {
                    if (json_encode($oldData[$field] ?? null) !== json_encode($entry->get($field))) {
                        $contentChanged = true;
                        break;
                    }
                }
            }

            if ($contentChanged) {
                // Legitimate edit: update timestamp to now
                $entry->set('updated', now()->toIso8601String());
            } else {
                // Background touch: preserve existing updated date (or leave unset)
                if (isset($oldData['updated'])) {
                    $entry->set('updated', $oldData['updated']);
                } else {
                    $entry->remove('updated');
                }
            }

            $oldQuoteOf = $oldData['quote_of'] ?? null;
            $entry->setSupplement('_old_quote_of', $oldQuoteOf);
        } else {
            $entry->setSupplement('_old_quote_of', null);
            // Brand new entry: do NOT set updated yet
            $entry->remove('updated');
        }

        $entry->setSupplement('_content_changed', $contentChanged);

        \Illuminate\Support\Facades\Log::info("ActivityPubListener: handleEntrySaving for {$entry->id()}", [
            'is_internal' => $entry->get('is_internal'),
            'actor' => $entry->get('actor'),
            'handle' => $handle,
            'enabled' => $this->isEnabled($handle),
            'content_changed' => $contentChanged,
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
        } else {
            // For other entities, copy from actor IF NOT ALREADY SET TRUE
            // This prevents overwriting a true flag set by a StoreHandler
            if ($entry->get('is_internal') !== true && $actorId) {
                if (is_array($actorId)) {
                    $actorId = $actorId[0] ?? null;
                }

                if ($actorId) {
                    $actor = $this->getActor($actorId);
                    if ($actor) {
                        $isInternal = $actor->get('is_internal', false);

                        // If the actor is ours (linked to a user) but flag is missing, it should be true
                        // This handles cases where actors were created before the flag was added,
                        // and cases where User::current() is null (CLI/Jobs) but the actor is still local.
                        if (!$isInternal) {
                            $user = User::current();
                            if ($user && $user->get('actors') && in_array($actor->id(), $user->get('actors'))) {
                                $isInternal = true;
                            } elseif (empty($actor->get('activitypub_id')) || str_starts_with((string) $actor->get('activitypub_id'), url('/'))) {
                                // Either it's a brand new local actor with no AP ID yet,
                                // or its AP ID matches our local site URL.
                                $isInternal = true;
                            }
                        }

                        $entry->set('is_internal', $isInternal);
                    }
                }
            }
        }

        // 2. Generate ActivityPub JSON
        // Only generate for internal items. External items should keep their original JSON.
        // Logic above ensures is_internal is synced with actor.
        $shouldGen = $entry->get('is_internal');

        if ($shouldGen !== false) {
            \Illuminate\Support\Facades\Log::info("ActivityPubListener: Entering Generation Block for {$entry->id()}");
            try {
                // 1.7.a Process manual tags first to ensure they are persisted as terms
                $settings = $this->getSettings();
                $hashtagSettings = $settings['hashtags'] ?? [];
                if ($hashtagSettings['enabled'] ?? false) {
                    $field = $hashtagSettings['field'] ?? 'tags';
                    $taxonomy = $hashtagSettings['taxonomy'] ?? 'tags';
                    $manualTags = $entry->get($field, []);
                    if (!is_array($manualTags)) {
                        $manualTags = $manualTags ? [$manualTags] : [];
                    }
                    if (!empty($manualTags)) {
                        $this->ensureTermsExist($manualTags, $taxonomy, $entry);
                    }
                }

                // 1.7.b Parse Hashtags from content and add them to the entry
                $this->parseHashtags($entry->get('content', '') . ' ' . $entry->get('summary', ''), $entry);

                // 1.7.c Parse Mentions from content and persist mentioned_urls for fast index querying
                $this->parseMentions($entry);

                // 1.7.d Extract normalized search tokens for fast Stache keyword index matching
                $tokens = \Ethernick\ActivityPubCore\Search\SearchTokenExtractor::extractFromEntry($entry);
                if (!empty($tokens)) {
                    $entry->set('search_tokens', $tokens);
                }

                $type = $this->getType($handle);
                $json = $this->generateActivityPubJson($entry, $actorId, $type);
                $entry->setSupplement('activitypub_json', $json);

            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("ActivityPubListener: Error generating JSON: " . $e->getMessage(), [
                    'trace' => $e->getTraceAsString()
                ]);
            }
        } else {
            // Also index search tokens for external incoming entries to accelerate inbox searches
            try {
                $tokens = \Ethernick\ActivityPubCore\Search\SearchTokenExtractor::extractFromEntry($entry);
                if (!empty($tokens)) {
                    $entry->set('search_tokens', $tokens);
                }
            } catch (\Throwable $e) {
                // Non-critical, continue
            }
        }
    }

    public function generateActivityPubJson(Entry $entry, $actorId = null, $type = null): string
    {
        if (is_array($actorId)) {
            $actorId = $actorId[0] ?? null;
        }

        /** @var ActivityPubObjectTransformer $transformer */
        $transformer = app(ActivityPubObjectTransformer::class);
        $data = $transformer->transform($entry, (string) $actorId, $type);

        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    protected function parseHashtags(string $content, \Statamic\Entries\Entry $entry): void
    {
        $settings = $this->getSettings();
        $hashtagSettings = $settings['hashtags'] ?? [];
        if (!($hashtagSettings['enabled'] ?? false)) {
            return;
        }

        if (!preg_match_all('/(?<!\S)#(?!\d+\b)([A-Za-z0-9_]+)/u', $content, $matches)) {
            return;
        }

        $taxonomyStr = $hashtagSettings['taxonomy'] ?? 'tags';
        $this->ensureTermsExist($matches[1], $taxonomyStr, $entry);
    }

    protected function ensureTermsExist(array $tagNames, string $taxonomy, \Statamic\Entries\Entry $entry): void
    {
        $settings = $this->getSettings();
        $hashtagSettings = $settings['hashtags'] ?? [];
        $field = $hashtagSettings['field'] ?? 'tags';

        $rawTags = $entry->get($field, []);
        if (!is_array($rawTags)) {
            $rawTags = $rawTags ? [$rawTags] : [];
        }

        // Normalize current tags to slugs to avoid duplicates like ["Tag", "tag"]
        $currentTags = array_map(function ($tag) {
            return (string) \Statamic\Support\Str::slug((string) $tag);
        }, $rawTags);

        foreach ($tagNames as $tagName) {
            $slug = (string) \Statamic\Support\Str::slug($tagName);
            $term = \Statamic\Facades\Term::find($taxonomy . '::' . $slug);

            if (!$term) {
                $term = \Statamic\Facades\Term::make()
                    ->taxonomy($taxonomy)
                    ->slug($slug)
                    ->data(['title' => $tagName]);

                \Illuminate\Support\Facades\Log::info("ActivityPub: Creating new tag '{$tagName}' (#{$slug}) in taxonomy '{$taxonomy}'");

                try {
                    $term->save();
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error("ActivityPub: Failed to save hashtag '{$tagName}': " . $e->getMessage());
                }
            }

            if ($term && !in_array($slug, $currentTags)) {
                $currentTags[] = $slug;
            }
        }

        // Set the normalized and unique slugs back to the entry
        $entry->set($field, array_values(array_filter(array_unique($currentTags))));
    }

    protected function parseMentions(\Statamic\Entries\Entry $entry): void
    {
        $rawContent = (string) ($entry->get('content') ?? '');
        $summary = (string) ($entry->get('summary') ?? '');
        $combined = $rawContent . ' ' . $summary;

        if (trim($combined) === '') {
            return;
        }

        $mentionedUrls = [];

        // 1. Check existing mentioned_urls if already set
        $existing = $entry->get('mentioned_urls', []);
        if (is_array($existing)) {
            $mentionedUrls = array_values(array_filter($existing));
        }

        // 2. Parse HTML links with mention class or href format: <a ... href="..." ...>@user...</a>
        $html = (string) \Statamic\Facades\Markdown::parse($combined);
        if (preg_match_all('/<a[^>]+href="([^"]+)"[^>]*>(?:@|\w)/i', $html, $matches)) {
            foreach ($matches[1] as $href) {
                if (filter_var($href, FILTER_VALIDATE_URL) && !in_array($href, $mentionedUrls)) {
                    $mentionedUrls[] = $href;
                }
            }
        }

        // 3. Parse explicit @handle mentions (@user@domain or @user)
        if (preg_match_all('/(?<![a-zA-Z0-9_])@([a-zA-Z0-9._-]+)(?:@([a-zA-Z0-9.-]+\.[a-zA-Z]{2,}))?/u', $combined, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $username = $match[1];
                $domain = $match[2] ?? null;

                if ($domain) {
                    $fullHandle = "@{$username}@{$domain}";
                    // Resolve via ActorResolver/Webfinger
                    try {
                        $actorResolver = app(\Ethernick\ActivityPubCore\Services\ActorResolver::class);
                        $resolvedActor = $actorResolver->resolveByHandle($fullHandle, true);
                        if ($resolvedActor) {
                            $apId = $resolvedActor->get('activitypub_id') ?: $resolvedActor->absoluteUrl();
                            if ($apId && !in_array($apId, $mentionedUrls)) {
                                $mentionedUrls[] = $apId;
                            }
                        }
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning("ActivityPubListener: Could not resolve mention {$fullHandle}: " . $e->getMessage());
                    }
                } else {
                    // Local actor lookup by username or slug
                    $localActor = \Statamic\Facades\Entry::query()
                        ->where('collection', 'actors')
                        ->where(function ($q) use ($username) {
                            $q->where('slug', $username)
                              ->orWhere('username', $username);
                        })
                        ->first();

                    if ($localActor) {
                        $apId = $localActor->get('activitypub_id') ?: $localActor->absoluteUrl();
                        if ($apId && !in_array($apId, $mentionedUrls)) {
                            $mentionedUrls[] = $apId;
                        }
                    }
                }
            }
        }

        if (!empty($mentionedUrls)) {
            $entry->set('mentioned_urls', array_values(array_unique($mentionedUrls)));
        }
    }

    protected function handleEntrySaved(mixed $event): void
    {
        $entry = $event->entry;
        static::setSaving($entry->id(), false);

        // Write the sidecar JSON if the supplement is set
        $json = $entry->getSupplement('activitypub_json');
        if ($json) {
            ActivityPubPayload::for($entry)->save($json);
        }

        // Invalidate card shell cache on content updates
        \Illuminate\Support\Facades\Cache::forget("ap:card_shell:{$entry->id()}");

        // Invalidate actor fast lookup map if an actor was saved
        if ($entry->collection()->handle() === 'actors') {
            \Ethernick\ActivityPubCore\Search\ActorSearchIndex::clear();
        }

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

        if ($entry->collection()->handle() === 'activities') {
            // Dispatch to queue instead of running immediately
            SendActivityPubPost::dispatch($entry->id())->onQueue('activitypub-outbox');
        }

        // --- Re-persist activitypub_id if missing post-save ---
        // On first save via the Statamic CP, Statamic assigns the entry's final ID and slug
        // AFTER the EntrySaving event fires. The listener may have computed a URL based on
        // a temporary slug that is no longer valid. Detect this and recompute + save quietly
        // now that the entry has its stable, final identity.
        if ($entry->get('is_internal') !== false &&
            ! $entry->get('activitypub_json_manual') &&
            empty($entry->get('activitypub_id')) &&
            $this->isEnabled($entry->collection()->handle())
        ) {

            $handle = $entry->collection()->handle();
            if ($handle !== 'activities') {
                $url = $entry->absoluteUrl();
                $slug = $entry->slug();

                $isNonUnique = empty($url) || str_ends_with(rtrim($url, '/'), '/' . $handle);
                if ($isNonUnique && !empty($slug)) {
                    $url = url("/{$handle}/{$slug}");
                }

                if (!empty($url)) {
                    \Illuminate\Support\Facades\Log::info("ActivityPubListener: Re-persisting activitypub_id post-save for {$entry->id()}: {$url}");
                    $entry->set('activitypub_id', $url);

                    // Also regenerate JSON now that the URL is stable
                    $actorId = $entry->get('actor');
                    if (is_array($actorId)) {
                        $actorId = $actorId[0] ?? null;
                    }
                    $type = $this->getType($handle);
                    try {
                        $json = $this->generateActivityPubJson($entry, $actorId, $type);
                        ActivityPubPayload::for($entry)->save($json);
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::error("ActivityPubListener: Failed to regenerate JSON post-save: " . $e->getMessage());
                    }

                    $entry->saveQuietly();
                }
            }
        }
    }

    protected function handleEntryDeleted(EntryDeleted $event): void
    {
        $entry = $event->entry;
        ActivityPubPayload::for($entry)->delete();

        \Illuminate\Support\Facades\Cache::forget("ap:card_shell:{$entry->id()}");
        if ($entry->collection()->handle() === 'actors') {
            \Ethernick\ActivityPubCore\Search\ActorSearchIndex::clear();
        }
    }
}
