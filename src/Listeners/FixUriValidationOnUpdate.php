<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Listeners;

use Statamic\Events\EntryBlueprintFound;

class FixUriValidationOnUpdate
{
    /**
     * Handle the event.
     *
     * Make the slug field read-only for existing actors and notes to prevent
     * URI validation errors and preserve ActivityPub identity.
     */
    public function handle(EntryBlueprintFound $event)
    {
        $entry = $event->entry;

        // Entry might be null for new entries
        if (!$entry) {
            return;
        }

        // Only process actors and notes collections
        if (!in_array($entry->collectionHandle(), ['actors', 'notes'])) {
            return;
        }

        // Only for existing entries (updates), not new ones
        if (!$entry->id()) {
            return;
        }

        $blueprint = $event->blueprint;

        // Make slug field read-only for existing entries
        // This prevents the URI validation error and is best practice for ActivityPub
        // since changing a handle breaks federation
        $contents = $blueprint->contents();

        foreach ($contents['tabs'] ?? [] as $tabKey => &$tab) {
            foreach ($tab['sections'] ?? [] as $sectionKey => &$section) {
                foreach ($section['fields'] ?? [] as $fieldKey => &$field) {
                    if ($field['handle'] === 'slug') {
                        $field['field']['read_only'] = true;
                        $field['field']['instructions'] = 'Handle cannot be changed after creation to preserve ActivityPub identity.';
                    }
                }
            }
        }

        $blueprint->setContents($contents);
    }
}
