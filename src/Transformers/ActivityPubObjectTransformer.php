<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Transformers;

use Statamic\Entries\Entry;
use Statamic\Facades\File;
use Statamic\Facades\YAML;
use Statamic\Facades\Asset;
use Statamic\Support\Str;
use Ethernick\ActivityPubCore\Services\ActivityPubUtils;
use Ethernick\ActivityPubCore\Services\ActivityPubTypes;
use Ethernick\ActivityPubCore\Contracts\OutboxHandlerInterface;

class ActivityPubObjectTransformer
{
    /**
     * Transform an entry into an ActivityPub JSON array.
     */
    public function transform(Entry $entry, ?string $actorId = null, ?string $type = null): array
    {
        $handle = $entry->collection()->handle();

        // 0. Handle Actor Type specifically
        if ($handle === 'actors') {
            return $this->transformActor($entry);
        }

        $actorId = $actorId ?? $entry->get('actor');
        if (is_array($actorId)) {
            $actorId = $actorId[0] ?? null;
        }

        $actorEntry = \Statamic\Facades\Entry::find($actorId);
        $actorUrl = $actorEntry ? url('@' . $actorEntry->slug()) : null;

        $url = $entry->get('activitypub_id') ?? $entry->absoluteUrl();
        $slug = $entry->slug();
        $isNonUnique = empty($url) || ($handle !== 'actors' && str_ends_with(rtrim((string) $url, '/'), '/' . $handle));

        if ($isNonUnique && !empty($slug)) {
            $url = url("/{$handle}/{$slug}");
        }
        $url = $this->sanitizeUrl((string) $url);

        // 1. Build Base Data
        $data = $this->buildBaseInfo($entry, $url, $actorUrl, $type);

        // 2. Build Content (Hashtags, Mentions, CWs)
        $data = $this->buildContent($entry, $data);

        // 3. Build Addressing (To, CC, Tags)
        $data = $this->buildAddressing($entry, $data, $actorUrl);

        // 4. Build Attachments
        $data = $this->buildAttachments($entry, $data);

        // 5. Build Interactions (Replies, Likes, Shares)
        $data = $this->buildInteractions($entry, $data, $handle, $actorUrl);

        // 6. Build Interaction Policy & Quotes
        $data = $this->buildQuotes($entry, $data);

        // 7. Special Activity Handling
        if ($handle === 'activities') {
            $data = $this->wrapInActivity($entry, $data, $actorUrl);
        }

        // 8. Type-specific formatting via registered outbox handlers
        $data = $this->applyTypeHandlers($entry, $data, $type);

        // 9. Apply external outbox payload hooks
        ActivityPubTypes::executeOutboxHooks($entry, $data);

        return $data;
    }

    /**
     * Specialized transformation for Actor (Person) objects.
     */
    public function transformActor(Entry $entry): array
    {
        $url = $this->sanitizeUrl(url('@' . $entry->slug()));
        $handle = $entry->slug();

        $settings = $this->getSettings();
        $allowQuotes = $settings['allow_quotes'] ?? false;

        $data = [
            '@context' => [
                'https://www.w3.org/ns/activitystreams',
                'https://w3id.org/security/v1',
                [
                    'manuallyApprovesFollowers' => 'as:manuallyApprovesFollowers',
                    'gts' => 'https://gotosocial.org/ns#',
                    'interactionPolicy' => [
                        '@id' => 'gts:interactionPolicy',
                        '@type' => '@id',
                    ],
                    'canQuote' => [
                        '@id' => 'gts:canQuote',
                        '@type' => '@id',
                    ],
                    'automaticApproval' => [
                        '@id' => 'gts:automaticApproval',
                        '@type' => '@id',
                    ],
                ],
            ],
            'id' => $url,
            'type' => 'Person',
            'preferredUsername' => $handle,
            'name' => $entry->get('title'),
            'summary' => (string) \Statamic\Facades\Markdown::parse((string) ($entry->get('content') ?? '')),
            'inbox' => $url . '/inbox',
            'outbox' => $url . '/outbox',
            'followers' => $url . '/followers',
            'following' => $url . '/following',
            'liked' => $url . '/liked',
            'url' => $url,
            'manuallyApprovesFollowers' => false,
            'discoverable' => true,
            'published' => $entry->date() ? $entry->date()->toIso8601String() : $entry->lastModified()->toIso8601String(),
            'publicKey' => [
                'id' => $url . '#main-key',
                'owner' => $url,
                'publicKeyPem' => $entry->get('public_key') ?? '',
            ],
            'icon' => $this->getActorIcon($entry),
        ];

        // Add interactionPolicy if quotes are allowed
        if ($allowQuotes) {
            $data['interactionPolicy'] = [
                'canQuote' => [
                    'automaticApproval' => ['https://www.w3.org/ns/activitystreams#Public'],
                ],
            ];
        }

        return $data;
    }

    /**
     * Unified transformation for OrderedCollection responses.
     */
    public function transformCollection(string $id, \Illuminate\Support\Collection $items, ?int $total = null): array
    {
        return [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'type' => 'OrderedCollection',
            'id' => $this->sanitizeUrl($id),
            'totalItems' => $total ?? $items->count(),
            'orderedItems' => $items->values()->toArray(),
        ];
    }

    protected function buildBaseInfo(Entry $entry, string $url, ?string $actorUrl, ?string $type): array
    {
        $published = $entry->date() ? $entry->date()->toIso8601String() : $entry->lastModified()->toIso8601String();
        $updated = $entry->lastModified()->toIso8601String();

        $data = [
            '@context' => $this->getContext(),
            'type' => $type ?? 'Note',
            'id' => $url,
            'published' => $published,
            'updated' => $updated,
            'url' => $url,
        ];

        if ($actorUrl) {
            $data['attributedTo'] = $actorUrl;
        }

        if ($entry->has('title') && $entry->get('title') !== $entry->id()) {
            $data['name'] = $entry->get('title');
        }

        // Handle inReplyTo
        $inReplyTo = $entry->get('in_reply_to');
        if ($inReplyTo) {
            if (is_array($inReplyTo)) $inReplyTo = $inReplyTo[0] ?? null;
            if ($inReplyTo) {
                $parentEntry = \Statamic\Facades\Entry::find($inReplyTo);
                if ($parentEntry) {
                    $parentId = $parentEntry->get('activitypub_id');
                    // If parent is an activity, reply to its object (the actual Note/Question)
                    if ($parentEntry->collection()->handle() === 'activities') {
                        $obj = $parentEntry->get('object');
                        if (is_array($obj)) $obj = $obj['id'] ?? $obj[0] ?? null;
                        if (is_array($obj)) $obj = $obj['id'] ?? null;
                        $parentId = $obj ?: $parentId;
                    }
                    $data['inReplyTo'] = $parentId ?: $parentEntry->absoluteUrl();
                } else {
                    $data['inReplyTo'] = $inReplyTo;
                }
            }
        }

        return $data;
    }

    protected function buildContent(Entry $entry, array $data): array
    {
        $rawContent = $entry->get('content') ?? $entry->get('title') ?? '';
        $htmlContent = \Statamic\Facades\Markdown::parse((string) $rawContent);
        
        // Hashtag parsing and linking logic
        $apHashtags = $this->getTags($entry, (string) $htmlContent);
        $data['content'] = $this->linkifyHashtags((string) $htmlContent, $apHashtags);

        // Summary / CW
        if ($entry->has('summary')) {
            $data['summary'] = $entry->get('summary');
        } elseif ($entry->has('cw')) {
            $data['summary'] = $entry->get('cw');
        }

        // Sensitive
        $data['sensitive'] = (bool) $entry->get('sensitive', false);

        return $data;
    }

    protected function buildAddressing(Entry $entry, array $data, ?string $actorUrl): array
    {
        $apHashtags = $this->getTags($entry, (string) ($entry->get('content') ?? ''));
        $mentions = $this->extractMentions($data['content'] ?? '');
        
        $tags = $apHashtags;
        $cc = $actorUrl ? [$actorUrl . '/followers'] : [];
        $to = ['https://www.w3.org/ns/activitystreams#Public'];

        foreach ($mentions as $mention) {
            $tags[] = [
                'type' => 'Mention',
                'href' => $mention['href'],
                'name' => $mention['name'],
            ];
            $cc[] = $mention['href'];
        }

        // Add quote authorization stamp to tags if present (FEP-044f)
        $authStamp = $entry->get('quote_authorization_stamp');
        if ($authStamp) {
            $tags[] = [
                'type' => 'Link',
                'mediaType' => 'application/ld+json; profile="https://www.w3.org/ns/activitystreams"',
                'href' => $authStamp,
                'rel' => 'https://w3id.org/fep/044f#quoteAuthorization',
                'name' => 'Quote authorization',
            ];
        }

        // Handle reply addressing
        $inReplyTo = $entry->get('in_reply_to');
        if ($inReplyTo) {
            if (is_array($inReplyTo)) $inReplyTo = $inReplyTo[0] ?? null;
            if ($inReplyTo) {
                $parentEntry = \Statamic\Facades\Entry::find($inReplyTo);
                if ($parentEntry) {
                    $parentActorId = $parentEntry->get('actor');
                    if (is_array($parentActorId)) $parentActorId = $parentActorId[0] ?? null;
                    
                    if ($parentActorId) {
                        $parentActor = \Statamic\Facades\Entry::find($parentActorId);
                        if ($parentActor) {
                            $parentActorUrl = url('@' . $parentActor->slug());
                            $cc[] = $parentActorUrl;
                            
                            // Add parent actor as a Mention tag
                            $tags[] = [
                                'type' => 'Mention',
                                'href' => $parentActorUrl,
                                'name' => '@' . $parentActor->slug(),
                            ];
                        }
                    }
                }
            }
        }

        $data['tag'] = $tags;
        $data['to'] = array_values(array_unique(array_merge($data['to'] ?? [], $to)));
        $data['cc'] = array_values(array_unique(array_merge($data['cc'] ?? [], $cc)));

        return $data;
    }

    protected function buildAttachments(Entry $entry, array $data): array
    {
        $attachments = $entry->get('activitypub_attachments') ?: [];
        if (!is_array($attachments)) {
            $attachments = [];
        }

        if ($assetId = $entry->get('attachment')) {
            if (is_array($assetId)) {
                $assetId = $assetId[0] ?? null;
            }
            if ($assetId) {
                $asset = Asset::find($assetId);
                if ($asset) {
                    $attachments[] = [
                        'type' => 'Image',
                        'mediaType' => $asset->mimeType(),
                        'url' => $asset->absoluteUrl(),
                        'name' => $asset->get('title') ?: $asset->filename(),
                    ];
                }
            }
        }

        if (!empty($attachments)) {
            $data['attachment'] = $attachments;
        }

        return $data;
    }

    protected function buildInteractions(Entry $entry, array $data, string $handle, ?string $actorUrl): array
    {
        $url = $data['id'];
        
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

        // Interaction Counts
        $sanitizedUrl = $url;
        $absoluteUrl = $entry->absoluteUrl();

        $likesCount = \Statamic\Facades\Entry::query()
            ->where('collection', 'activities')
            ->where('type', '=', 'Like')
            ->get()
            ->filter(function ($act) use ($sanitizedUrl, $absoluteUrl) {
                $obj = $act->get('object');
                return $obj === $sanitizedUrl || $obj === $absoluteUrl;
            })->count();

        $sharesCount = \Statamic\Facades\Entry::query()
            ->where('collection', 'activities')
            ->where('type', '=', 'Announce')
            ->get()
            ->filter(function ($act) use ($sanitizedUrl, $absoluteUrl) {
                $obj = $act->get('object');
                return $obj === $sanitizedUrl || $obj === $absoluteUrl;
            })->count();

        $actorHandle = 'unknown';
        if ($actorUrl) {
            $actorHandle = basename($actorUrl);
            if (str_starts_with($actorHandle, '@')) {
                $actorHandle = substr($actorHandle, 1);
            }
        }

        $interactionBase = url('@' . $actorHandle . '/' . $handle . '/' . $entry->slug());
        
        $data['likes_count'] = $likesCount;
        $data['likes_url'] = $interactionBase . '/likes';
        $data['shares_count'] = $sharesCount;
        $data['shares_url'] = $interactionBase . '/shares';

        return $data;
    }

    protected function buildQuotes(Entry $entry, array $data): array
    {
        $settings = $this->getSettings();
        $allowQuotes = $settings['allow_quotes'] ?? false;
        
        $data['interactionPolicy'] = [
            'type' => 'gts:interactionPolicy',
            'canQuote' => [
                'type' => 'gts:interactionPolicyRule',
                'automaticApproval' => $allowQuotes ? ['https://www.w3.org/ns/activitystreams#Public'] : []
            ]
        ];

        $quoteOf = $entry->get('quote_of');
        if ($quoteOf && is_string($quoteOf)) {
            $quoteOf = [$quoteOf];
            $entry->set('quote_of', $quoteOf);
        }

        if ($quoteOf && is_array($quoteOf) && count($quoteOf) > 0) {
            $quotedId = $quoteOf[0];
            $quotedEntry = \Statamic\Facades\Entry::find($quotedId);
            if ($quotedEntry) {
                $quotedUrl = $quotedEntry->get('activitypub_id') ?: $quotedEntry->absoluteUrl();
                $data['quote'] = $quotedUrl;
                $data['_misskey_quote'] = $quotedUrl;
                $data['quoteUrl'] = $quotedUrl;
                $data['quoteUri'] = $quotedUrl;

                $authStamp = $entry->get('quote_authorization_stamp');
                if ($authStamp) {
                    $data['quoteAuthorization'] = $authStamp;
                }

                if (!empty($data['content'])) {
                    $data['content'] = rtrim((string) $data['content']) . '<br><br>RE: <a href="' . htmlspecialchars($quotedUrl, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($quotedUrl, ENT_QUOTES, 'UTF-8') . '</a>';
                }
            }
        }

        return $data;
    }

    protected function wrapInActivity(Entry $entry, array $data, ?string $actorUrl): array
    {
        $activityType = $entry->get('type') ?? 'Create';
        if (is_array($activityType)) {
            $activityType = $activityType[0] ?? 'Create';
        }
        $data['type'] = $activityType;

        if ($actorUrl) {
            $data['actor'] = $actorUrl;
        }

        // Remove Note specific fields when wrapping in activity
        unset($data['content']);
        unset($data['sensitive']);
        unset($data['attachment']);
        unset($data['tag']);
        unset($data['quoteUrl']);
        unset($data['quote']);
        unset($data['_misskey_quote']);

        $objectId = $entry->get('object');
        if (is_array($objectId)) {
            $objectId = $objectId[0] ?? null;
        }

        $objectData = null;
        if ($activityType === 'Delete' && $entry->get('deleted_object_url')) {
            $objectData = $entry->get('deleted_object_url');
        } elseif ($objectId) {
            $objectEntry = \Statamic\Facades\Entry::find($objectId);
            if ($objectEntry) {
                $objectJson = $objectEntry->get('activitypub_json');

                if (!$objectJson) {
                    // Recursive call to self for nested objects
                    $objectCollectionHandle = $objectEntry->collection()->handle();
                    $objectType = ActivityPubTypes::getCollections($objectCollectionHandle)[0] ?? 'Note'; // Approximate
                    $objectData = $this->transform($objectEntry, null, $objectType);
                } else {
                    if (is_array($objectJson) && isset($objectJson['code'])) {
                        $objectJson = $objectJson['code'];
                    }
                    $objectData = json_decode((string) $objectJson, true);
                }

                if ($objectData && isset($objectData['@context'])) {
                    unset($objectData['@context']);
                }
            }
        }

        if (!$objectData && $entry->get('object_url')) {
            $objectData = $entry->get('object_url');
        }

        if ($objectData) {
            $data['object'] = $objectData;
            if (is_array($objectData)) {
                if (isset($objectData['to'])) {
                    $data['to'] = array_values(array_unique(array_merge($data['to'], (array) $objectData['to'])));
                }
                if (isset($objectData['cc'])) {
                    $data['cc'] = array_values(array_unique(array_merge($data['cc'], (array) $objectData['cc'])));
                }
            }
        }

        return $data;
    }

    protected function applyTypeHandlers(Entry $entry, array $data, ?string $type): array
    {
        if ($type && $handlerClass = ActivityPubTypes::getOutboxHandler($type)) {
            if (class_exists($handlerClass)) {
                $handler = app($handlerClass);
                if ($handler instanceof OutboxHandlerInterface) {
                    $data = $handler->format($data, $entry);
                }
            }
        }
        return $data;
    }

    protected function getContext(): array
    {
        return [
            'https://www.w3.org/ns/activitystreams',
            [
                'Hashtag' => 'as:Hashtag',
                'sensitive' => 'as:sensitive',
                'focalPoint' => [
                    '@container' => '@list',
                    '@id' => 'toot:focalPoint'
                ],
                'quote' => 'https://w3id.org/fep/044f#quote',
                'quoteUri' => 'http://fedibird.com/ns#quoteUri',
                '_misskey_quote' => 'https://misskey-hub.net/ns#_misskey_quote',
                'quoteAuthorization' => [
                    '@id' => 'https://w3id.org/fep/044f#quoteAuthorization',
                    '@type' => '@id'
                ],
                'gts' => 'https://gotosocial.org/ns#',
                'interactionPolicy' => [
                    '@id' => 'gts:interactionPolicy',
                    '@type' => '@id'
                ],
                'canQuote' => [
                    '@id' => 'gts:canQuote',
                    '@type' => '@id'
                ],
                'automaticApproval' => [
                    '@id' => 'gts:automaticApproval',
                    '@type' => '@id'
                ]
            ]
        ];
    }

    protected function getTags(Entry $entry, string $content): array
    {
        $settings = $this->getSettings();
        $hashtagSettings = $settings['hashtags'] ?? [];
        if (!($hashtagSettings['enabled'] ?? false)) {
            return [];
        }

        $field = $hashtagSettings['field'] ?? 'tags';
        $taxonomyStr = $hashtagSettings['taxonomy'] ?? 'tags';

        // 1. Get from content
        preg_match_all('/(?<!\S)#(?!\d+\b)([A-Za-z0-9_]+)/u', $content, $matches);
        $tagHandles = $matches[1] ?? [];

        // 2. Merge with entry's tags field
        $entryTags = $entry->get($field, []);
        if (!is_array($entryTags)) {
            $entryTags = $entryTags ? [$entryTags] : [];
        }
        
        $tagHandles = array_unique(array_merge($tagHandles, $entryTags));

        $apHashtags = [];
        foreach ($tagHandles as $termHandle) {
            if (empty($termHandle)) continue;
            $slug = (string) Str::slug((string) $termHandle);
            $term = \Statamic\Facades\Term::find($taxonomyStr . '::' . $slug);
            if ($term) {
                $apHashtags[] = [
                    'type' => 'Hashtag',
                    'href' => $term->absoluteUrl(),
                    'name' => '#' . $term->slug(),
                ];
            } else {
                $apHashtags[] = [
                    'type' => 'Hashtag',
                    'href' => url("/tags/" . $slug),
                    'name' => '#' . ltrim((string) $termHandle, '#'),
                ];
            }
        }

        return $apHashtags;
    }

    protected function linkifyHashtags(string $html, array $hashtags): string
    {
        foreach ($hashtags as $hashtag) {
            $name = $hashtag['name']; // e.g. #statamic
            $href = $hashtag['href'];
            $tagName = ltrim($name, '#');

            $link = '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" class="mention hashtag" rel="tag">#<span>' . htmlspecialchars($tagName, ENT_QUOTES, 'UTF-8') . '</span></a>';
            
            // Secure replacement (only if not already linked)
            $pattern = '/(?<!href=")(?<!">)' . preg_quote($name, '/') . '(?!\<\/a\>)/u';
            $html = preg_replace($pattern, $link, $html);
        }
        return $html;
    }

    protected function extractMentions(?string $html): array
    {
        if ($html === null || $html === '') {
            return [];
        }

        $mentions = [];
        if (preg_match_all('/<a[^>]+href="([^"]+)"[^>]*>(@.*?)<\/a>/i', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $mentions[] = [
                    'href' => $match[1],
                    'name' => strip_tags($match[2]),
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
        return rtrim($url, '/');
    }

    protected function getActorIcon(Entry $entry): ?array
    {
        $staticPath = 'activitypub/avatars/' . $entry->slug() . '.jpg';
        if (file_exists(public_path($staticPath))) {
            return [
                'type' => 'Image',
                'mediaType' => 'image/jpeg',
                'url' => url($staticPath),
            ];
        }

        $avatar = $entry->get('avatar');
        if ($avatar) {
            if (! $avatar instanceof \Statamic\Assets\Asset) {
                // If it's a string (asset ID), find it
                $avatar = Asset::find($avatar);
            }
            if ($avatar) {
                return [
                    'type' => 'Image',
                    'mediaType' => $avatar->mimeType(),
                    'url' => $avatar->absoluteUrl(),
                ];
            }
        }
        return null;
    }

    protected function getSettings(): array
    {
        $path = ActivityPubUtils::settingsPath();
        return File::exists($path) ? YAML::parse(File::get($path)) : [];
    }
}
