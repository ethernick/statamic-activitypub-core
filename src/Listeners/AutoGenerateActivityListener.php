<?php

namespace Ethernick\ActivityPubCore\Listeners;

use Statamic\Events\EntrySaving;
use Statamic\Events\EntrySaved;
use Statamic\Events\EntryDeleted;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Statamic\Facades\File;
use Statamic\Facades\YAML;
use Illuminate\Support\Carbon;

class AutoGenerateActivityListener
{
    public function handle($event)
    {
        if ($event instanceof EntrySaving) {
            $this->handleSaving($event);
        } elseif ($event instanceof EntrySaved) {
            $this->handleSaved($event);
        } elseif ($event instanceof EntryDeleted) {
            $this->handleDeleted($event);
        }
    }

    protected function handleSaving($event)
    {
        $entry = $event->entry;
        // Check if file exists. If not, it's a new entry.
        // We use supplements to pass this info to the Saved event.

        if (!File::exists($entry->path())) {
            $entry->setSupplement('is_new', true);
        } else {
            $entry->setSupplement('is_new', false);
        }
    }

    protected function handleSaved($event)
    {
        $entry = $event->entry;
        $collection = $entry->collection()->handle();

        if ($collection === 'activities') {
            return;
        }

        // Do not auto-generate activities for external items
        $isInternal = $entry->get('is_internal');

        if ($isInternal === false) {
            return;
        }

        if (!$this->shouldAutoGenerate($collection)) {
            return;
        }

        // Check if this is a quote pending authorization (FEP-044f)
        $quoteOf = $entry->get('quote_of');
        $authStatus = $entry->get('quote_authorization_status');

        if ($quoteOf && $authStatus === 'pending') {
            \Illuminate\Support\Facades\Log::info("AutoGenerateActivityListener: Skipping activity for pending quote", [
                'entry' => $entry->id(),
                'is_new' => $entry->getSupplement('is_new'),
            ]);
            return;
        }

        $type = 'Update';
        if ($entry->getSupplement('is_new')) {
            $type = 'Create';
        }

        // Treat quote approval as a Create only if it's a NEW note
        // For existing notes getting quotes added via edit, keep it as Update
        if ($entry->get('_quote_approved') && $entry->getSupplement('is_new')) {
            $type = 'Create';
        }

        // SAFETY: Check if this activity type already exists for this entry
        $existingActivity = Entry::query()
            ->where('collection', 'activities')
            ->where('type', $type)
            ->get()
            ->filter(function ($activity) use ($entry) {
                $object = $activity->get('object');
                if (is_array($object)) {
                    return in_array($entry->id(), $object);
                }
                return $object === $entry->id();
            })
            ->first();

        if ($existingActivity) {
            \Illuminate\Support\Facades\Log::info("AutoGenerateActivityListener: {$type} activity already exists for {$entry->id()}, skipping");

            // Clean up the flag if present
            if ($entry->get('_quote_approved')) {
                $entry->set('_quote_approved', null);
                $entry->saveQuietly();
            }
            return;
        }

        $this->createActivity($type, $entry);

        // Clean up the flag after successful activity creation
        if ($entry->get('_quote_approved')) {
            $entry->set('_quote_approved', null);
            $entry->saveQuietly();
        }
    }

    protected function handleDeleted($event)
    {
        $entry = $event->entry;
        $collection = $entry->collection()->handle();

        if ($collection === 'activities') {
            return;
        }

        // Do not auto-generate activities for external items
        if ($entry->get('is_internal') === false) {
            return;
        }

        if (!$this->shouldAutoGenerate($collection)) {
            return;
        }

        $this->createActivity('Delete', $entry);
    }

    protected function createActivity($type, $objectEntry)
    {
        $actorId = $objectEntry->get('actor');
        if (!$actorId) {
            $user = User::current();
            if ($user) {
                $actors = $user->get('actors');
                if ($actors && count($actors) > 0) {
                    $actorId = $actors[0];
                }
            }
        }

        // Special handling for actors collection
        if ($objectEntry->collection()->handle() === 'actors') {
            $actorId = $objectEntry->id();
        }

        // If still no actor, maybe use the author?
        if (!$actorId && method_exists($objectEntry, 'author') && $objectEntry->author()) {
            $author = $objectEntry->author();
            $actors = $author->get('actors');
            if ($actors && count($actors) > 0) {
                $actorId = $actors[0];
            }
        }

        if (!$actorId) {
            // Cannot create activity without actor
            return;
        }

        // Ensure actorId is a string before putting it in array
        if (is_array($actorId)) {
            $actorId = $actorId[0] ?? null;
        }

        $title = $objectEntry->get('title');
        if (is_array($title)) {
            // If title is localized/array, take the first value or stringify it
            $title = reset($title);
        }

        // Generate Summary
        $actorEntry = \Statamic\Facades\Entry::find($actorId);
        $actorName = $actorEntry ? $actorEntry->get('title') : 'Someone';

        if ($objectEntry->collection()->handle() === 'actors') {
            $objectType = 'profile';
        } else {
            // Dynamic Type Lookup
            $apType = $this->getType($objectEntry->collection()->handle());
            $objectType = strtolower($apType); // Note -> note, Question -> question
        }

        if (is_array($type)) {
            $type = $type[0] ?? 'Create';
        }

        $verb = strtolower($type) . 'd'; // created, updated, deleted
        if ($type === 'Create')
            $verb = 'created';
        if ($type === 'Update')
            $verb = 'updated';
        if ($type === 'Delete')
            $verb = 'deleted';

        if ($objectType === 'profile' && $type === 'Update') {
            $summary = "{$actorName} updated their profile";
        } else {
            // Improvements for grammar could be added here (a/an), but "a [type]" is usually acceptable generic fallback
            // or specific overrides.
            $article = in_array(substr($objectType, 0, 1), ['a', 'e', 'i', 'o', 'u']) ? 'an' : 'a';
            $summary = "{$actorName} {$verb} {$article} {$objectType}";
        }

        $activityData = [
            'title' => "{$type} " . $title,
            'content' => $summary,
            'type' => $type,
            'actor' => [$actorId], // Expecting array for actor_selector/entries
            'object' => [$objectEntry->id()],
            'published' => true, // Make it public?
            'date' => now()->format('Y-m-d H:i:s'),
            'activitypub_collections' => ['outbox'],
        ];

        // For Delete activities, store the object's activitypub_id before it's deleted
        if ($type === 'Delete') {
            $objectApId = $objectEntry->get('activitypub_id') ?: $objectEntry->absoluteUrl();
            $activityData['deleted_object_url'] = $objectApId;
        }

        $activity = Entry::make()
            ->collection('activities')
            ->slug(uniqid('activity-'))
            ->data($activityData);

        $activity->save();
    }

    protected function shouldAutoGenerate($handle)
    {
        $path = resource_path('settings/activitypub.yaml');
        if (!File::exists($path)) {
            return false;
        }
        $settings = YAML::parse(File::get($path));
        $config = $settings[$handle] ?? [];

        // Support array config format
        if (is_array($config)) {
            return $config['federated'] ?? false;
        }

        // Legacy boolean format (though AutoGenerateActivityListener seemingly only used for new format collections?)
        // If it was just 'true', it meant enabled, but we need federated flag specifically.
        // Assuming legacy config didn't have federated separte from enabled, or this listener assumes new config.
        return false;
    }

    protected function getType($handle)
    {
        $path = resource_path('settings/activitypub.yaml');
        if (!File::exists($path)) {
            return 'Object';
        }
        $settings = YAML::parse(File::get($path));
        $config = $settings[$handle] ?? [];

        if (is_bool($config)) {
            return 'Object';
        }

        return $config['type'] ?? 'Object';
    }
}
