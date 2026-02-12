<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore;

use Statamic\Entries\Entry;
use Illuminate\Support\Str;

class ActivityPresenter
{
    protected $activity;
    protected $data;

    public function __construct(Entry $activity)
    {
        $this->activity = $activity;
        $this->data = json_decode($activity->get('activitypub_json'), true) ?? [];
    }

    public function getSentence(): string
    {
        $type = $this->activity->get('type');
        $actorLink = $this->getActorLink();

        switch ($type) {
            case 'Follow':
                $target = $this->getObjectLink(); // Usually the user being followed
                return "{$actorLink} followed {$target}";

            case 'Create':
                $objectLink = $this->getObjectLink();
                return "{$actorLink} created a new {$objectLink}";

            case 'Like':
                $objectLink = $this->getObjectLink();
                return "{$actorLink} liked {$objectLink}";

            case 'Announce':
                $objectLink = $this->getObjectLink();
                return "{$actorLink} boosted {$objectLink}";

            case 'Undo':
                // Usually "Undo Follow" or "Undo Like"
                $object = $this->data['object'] ?? [];
                $objectType = is_array($object) ? ($object['type'] ?? '') : '';
                if ($objectType === 'Follow') {
                    // For Undo Follow, the object is the Follow activity itself usually, 
                    // but broadly it means they unfollowed.
                    // The 'object' of the Follow is the target.
                    return "{$actorLink} unfollowed target";
                }
                if ($objectType === 'Like') {
                    return "{$actorLink} unliked object";
                }
                return "{$actorLink} performed an Undo";

            case 'Accept':
                return "{$actorLink} accepted a request";

            case 'Update':
                $objectLink = $this->getObjectLink();
                return "{$actorLink} updated {$objectLink}";

            case 'Delete':
                return "{$actorLink} deleted an object";

            default:
                return "{$actorLink} performed {$type}";
        }
    }

    public function getActorLink(): string
    {
        $actorId = $this->activity->get('actor');
        $actor = null;

        // Try to verify if it's an Entry ID or AP ID
        if (Str::isUuid($actorId)) {
            $actor = Entry::find($actorId);
        } else {
            // Maybe it's stored as array in recent versions
            if (is_array($actorId))
                $actorId = $actorId[0] ?? null;

            // Try to resolve by ID first
            if ($actorId) {
                $actor = Entry::find($actorId);
            }
        }

        if ($actor) {
            $name = $actor->title ?? 'Unknown';
            $url = $actor->editUrl(); // Link to internal edit if possible
            // Or public link? 
            // Better to show internal CP edit link or a public profile link.
            // Let's use AP ID as public link if not internal?
            // User requested: "[@Actor1@domain](link to actor activitypub id)"

            $handle = $actor->get('username') ?? $actor->slug(); // fallback
            // Try to construct proper @handle if possible
            // We usually store 'username' in actor.

            return "<a href=\"{$url}\" class=\"font-bold text-blue-600 hover:underline\">{$name}</a>";
        }

        // Fallback to raw ID from JSON
        $actorId = $this->data['actor'] ?? 'Unknown';
        return "<a href=\"{$actorId}\" class=\"font-bold text-blue-600 hover:underline\" target=\"_blank\">{$actorId}</a>";
    }

    public function getObjectLink(): string
    {
        $object = $this->data['object'] ?? null;

        if (is_string($object)) {
            // It's a URL
            return "<a href=\"{$object}\" class=\"text-blue-600 hover:underline\" target=\"_blank\">link</a>";
        }

        if (is_array($object)) {
            $type = $object['type'] ?? 'Object';
            $url = $object['id'] ?? $object['url'] ?? '#';
            return "<a href=\"{$url}\" class=\"text-blue-600 hover:underline\" target=\"_blank\">{$type}</a>";
        }

        return "something";
    }

    public function getRelatedLinks(): array
    {
        $links = [];
        // Extract interesting links from JSON

        // Image
        if (isset($this->data['object']['image'])) {
            $img = $this->data['object']['image'];
            $url = is_array($img) ? ($img['url'] ?? null) : $img;
            if ($url)
                $links['Image'] = $url;
        }

        // In Reply To
        if (isset($this->data['object']['inReplyTo'])) {
            $links['In Reply To'] = $this->data['object']['inReplyTo'];
        }

        // Attachment
        if (isset($this->data['object']['attachment'])) {
            $att = $this->data['object']['attachment'];
            // could be array of attachments
            if (is_array($att) && isset($att[0])) {
                foreach ($att as $k => $a) {
                    $links['Attachment ' . ($k + 1)] = $a['url'] ?? '#';
                }
            } elseif (isset($att['url'])) {
                $links['Attachment'] = $att['url'];
            }
        }

        return $links;
    }

    public function getContent(): ?string
    {
        // Try to get the actual content of the object (e.g. Note content)
        if (isset($this->data['object']) && is_array($this->data['object'])) {
            if (isset($this->data['object']['content'])) {
                return $this->data['object']['content'];
            }
        }
        // Fallback or explicit check for types?
        return null;
    }

    public function getActor(): ?\Statamic\Entries\Entry
    {
        $actorId = $this->activity->get('actor');
        if (is_array($actorId))
            $actorId = $actorId[0] ?? null;

        $actor = null;
        if ($actorId) {
            // Try to find local entry
            if (Str::isUuid($actorId)) {
                $actor = Entry::find($actorId);
            } else {
                // Or find by activitypub_id? The field in 'activities' is usually the ID.
                // But let's assume it stored the ID string if external.
                // We can try to find an actor with that activitypub_id
                $actor = Entry::query()->where('collection', 'actors')->where('activitypub_id', $actorId)->first();
            }
        }
        return $actor; // returns Entry or null
    }

    public function getActorName(): string
    {
        $actor = $this->getActor();
        if ($actor)
            return $actor->title;
        // Fallback to JSON
        return $this->data['actor']['name'] ?? 'Unknown';
    }

    public function getActorAvatarUrl(): ?string
    {
        $actor = $this->getActor();
        if ($actor && $actor->get('avatar')) {
            return $actor->augmentedValue('avatar')->value()?->url();
        }
        // Fallback to JSON icon
        $icon = $this->data['actor']['icon']['url'] ?? null;
        // Or if the actor is just a string ID in data['actor'], we can't get icon easily without fetching.
        return $icon;
    }

    public function getActorHandle(): string
    {
        $actor = $this->getActor();
        if ($actor) {
            $handle = $actor->get('username');
            // If external, might need domain?
            // Local actors usually just username. External usually `user@domain`.
            // But we store 'username' often as just username.
            // Let's rely on slug or ID if needed.
            // Actually, we construct handle logic elsehwere.
            // If slug contains '-at-', it's likely external: `user-at-domain` -> `@user@domain`.
            $slug = $actor->slug();
            if (str_contains($slug, '-at-')) {
                $parts = explode('-at-', $slug);
                $user = $parts[0];
                $domain = str_replace('-dot-', '.', $parts[1] ?? '');
                return "@{$user}@{$domain}";
            }
            return "@{$handle}";
        }

        // Fallback to JSON
        $url = $this->data['actor']['id'] ?? $this->data['actor'] ?? '';
        if ($url) {
            $host = parse_url($url, PHP_URL_HOST);
            $user = basename($url);
            return "@{$user}@{$host}";
        }
        return '@unknown';
    }

    public function getActionIcon(): string
    {
        $type = $this->activity->get('type');
        switch ($type) {
            case 'Create':
                // Post icon
                return '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>';
            case 'Follow':
                // User Add icon
                return '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" /></svg>';
            case 'Like':
                // Heart icon
                return '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" /></svg>';
            case 'Announce':
                // Boost icon
                return '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>';
            case 'Undo':
                return '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>';
            default:
                return '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>';
        }
    }

    public function getDescription(): string
    {
        // Re-use logic from getSentence? but maybe without actor link?
        // User asked for "Create a new note" or "Mentioned @actor".
        // Let's customize per type but without the subject actor.

        $type = $this->activity->get('type');
        switch ($type) {
            case 'Create':
                return "Posted a new note";
            case 'Follow':
                $target = $this->getObjectLink();
                return "Followed {$target}";
            case 'Like':
                $target = $this->getObjectLink();
                return "Liked {$target}";
            case 'Announce':
                $target = $this->getObjectLink();
                return "Boosted {$target}";
            case 'Update':
                return "Updated profile/note";
            case 'Undo':
                return "Undid an action";
            default:
                return $type;
        }
    }

    public function getRawJson(): string
    {
        return json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
