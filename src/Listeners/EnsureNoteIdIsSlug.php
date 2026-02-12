<?php


namespace Ethernick\ActivityPubCore\Listeners;

use Statamic\Events\EntrySaving;
use Statamic\Entries\Entry;

class EnsureNoteIdIsSlug
{
    public function handle(EntrySaving $event): void
    {
        /** @var Entry $entry */
        $entry = $event->entry;

        if ($entry->collectionHandle() !== 'notes') {
            return;
        }

        // If ID is missing, generate it.
        if (!$entry->id()) {
            $entry->id((string) \Illuminate\Support\Str::uuid());
        }

        // Ensure slug matches ID
        if ($entry->slug() !== $entry->id()) {
            $entry->slug($entry->id());
        }

        // Ensure title matches ID (overwriting blueprint default)
        if ($entry->get('title') !== $entry->id()) {
            $entry->set('title', $entry->id());
        }
    }
}
