<?php

namespace Ethernick\ActivityPubCore\Tests;

use Tests\TestCase;
use Statamic\Facades\Entry;
use Statamic\Facades\Config;
use Statamic\Facades\File;
use Statamic\Facades\YAML;
use Illuminate\Support\Facades\Event;

class AutoGenerateActivityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure Pro is on for collection features
        config(['statamic.editions.pro' => true]);

        // Ensure settings exist in sandbox
        File::put(
            \Ethernick\ActivityPubCore\Services\ActivityPubUtils::settingsPath(),
            YAML::dump([
                'actors' => ['enabled' => true, 'federated' => true, 'type' => 'Person'],
                'notes' => ['enabled' => true, 'federated' => true, 'type' => 'Note'],
                'activities' => ['enabled' => true, 'federated' => true, 'type' => 'Activity'],
            ])
        );

        $this->setupCollections(['actors', 'activities', 'notes']);

        // Clear Blink cache
        \Statamic\Facades\Blink::forget('activitypub-settings');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function test_creating_internal_note_generates_create_activity()
    {
        // 1. Create a local actor
        $actor = Entry::make()
            ->collection('actors')
            ->slug('test-auto-gen-actor')
            ->data([
                'title' => 'Test Actor',
                'is_internal' => true,
            ]);
        $actor->save();

        // 2. Create a Note
        // This should trigger AutoGenerateActivityListener via EntrySaved event
        $note = Entry::make()
            ->collection('notes')
            ->slug('test-auto-gen-note')
            ->data([
                'content' => 'This is a test note to check activity generation.',
                'actor' => $actor->id(),
                'is_internal' => true,
                'published' => true,
            ]);

        $note->save();

        // 3. Assert Activity Created
        $activity = Entry::query()
            ->where('collection', 'activities')
            ->where('slug', 'like', 'activity-%') // The listener uses uniqid('activity-')
            ->get()
            ->first(function ($entry) use ($note) {
                // Check if this activity is for our note
                $object = $entry->get('object');
                return $object && in_array($note->id(), $object);
            });

        $this->assertNotNull($activity, 'An activity should have been generated for the new note.');
        $this->assertEquals('Create', $activity->get('type'));
        $this->assertEquals([$actor->id()], $activity->get('actor'));
        $this->assertContains('outbox', $activity->get('activitypub_collections'));
    }

    public function test_updating_note_generates_update_activity()
    {
        // 1. Create Actor & Note (Initial Save = Create Activity)
        $actor = Entry::make()
            ->collection('actors')
            ->slug('test-auto-gen-actor-update')
            ->data(['title' => 'Test Actor', 'is_internal' => true]);
        $actor->save();

        $note = Entry::make()
            ->collection('notes')
            ->slug('test-auto-gen-note-update')
            ->data([
                'content' => 'Original Content',
                'actor' => $actor->id(),
                'is_internal' => true,
                'published' => true,
            ]);
        $note->save();

        // Clear only test activities from creation (those for test-auto-gen notes)
        Entry::query()
            ->where('collection', 'activities')
            ->where('slug', 'like', 'activity-%')
            ->get()
            ->filter(function ($entry) use ($note) {
                $object = $entry->get('object');
                if (!$object) {
                    return false;
                }
                // Handle both string and array formats
                if (is_string($object)) {
                    return $object === $note->id();
                }
                return is_array($object) && in_array($note->id(), $object);
            })
            ->each->delete();

        // Reload entry to clear 'is_new' supplement from previous save
        $note = Entry::find($note->id());

        // 2. Update the Note
        $note->set('content', 'Updated Content');
        $note->save();

        // 3. Assert Update Activity
        $activity = Entry::query()
            ->where('collection', 'activities')
            ->get()
            ->first(function ($entry) use ($note) {
                $object = $entry->get('object');
                if (!$object) {
                    return false;
                }
                // Handle both string and array formats
                if (is_string($object)) {
                    return $object === $note->id();
                }
                return is_array($object) && in_array($note->id(), $object);
            });

        $this->assertNotNull($activity, 'An Update activity should have been generated.');
        $this->assertEquals('Update', $activity->get('type'));
    }

    public function test_background_touch_does_not_generate_update_activity_nor_bump_dates()
    {
        // 1. Create Actor & Note with known original creation date
        $originalDate = now()->subDays(10)->startOfSecond();
        $actor = Entry::make()
            ->collection('actors')
            ->slug('test-auto-gen-actor-touch')
            ->data(['title' => 'Test Actor', 'is_internal' => true]);
        $actor->save();

        $note = Entry::make()
            ->collection('notes')
            ->slug('test-auto-gen-note-touch')
            ->date($originalDate)
            ->data([
                'content' => 'Original Untouched Content',
                'actor' => $actor->id(),
                'is_internal' => true,
                'published' => true,
                'date' => $originalDate->toIso8601String(),
            ]);
        $note->save();

        // Clear creation activities
        Entry::query()
            ->where('collection', 'activities')
            ->get()
            ->filter(function ($entry) use ($note) {
                $object = $entry->get('object');
                return $object && in_array($note->id(), (array) $object);
            })
            ->each->delete();

        // Reload entry
        $note = Entry::find($note->id());

        // 2. Perform a background touch: only modify metadata (reply_count, boost_count, search_tokens)
        // Content remains identical!
        $note->set('reply_count', 42);
        $note->set('boost_count', 7);
        $note->set('search_tokens', ['touch', 'test']);
        // Attempt an accidental date mutation
        $note->date(now());
        $note->set('date', now()->toIso8601String());
        $note->save();

        // 3. Assert NO Update activity was generated
        $activity = Entry::query()
            ->where('collection', 'activities')
            ->get()
            ->first(function ($entry) use ($note) {
                $object = $entry->get('object');
                return $object && in_array($note->id(), (array) $object);
            });

        $this->assertNull($activity, 'No ActivityPub Update activity should be emitted when content has not changed.');

        // 4. Assert Note date was guarded and restored to original creation date
        $reloaded = Entry::find($note->id());
        $this->assertEquals($originalDate->toIso8601String(), $reloaded->date()->toIso8601String(), 'Original creation date must remain immutable.');
        $this->assertEquals($originalDate->toIso8601String(), \Illuminate\Support\Carbon::parse($reloaded->get('date'))->toIso8601String());

        // 5. Assert updated field was NOT set
        $this->assertNull($reloaded->get('updated'), 'Updated timestamp must NOT be set on background touches without content changes.');
    }

    public function test_substantive_field_change_triggers_update_activity()
    {
        // 1. Register a custom type with substantive fields (like Question with options)
        \Ethernick\ActivityPubCore\Services\ActivityPubTypes::register(
            'TestQuestion',
            'Test Question',
            null,
            null,
            ['polls'],
            null,
            null,
            null,
            ['options', 'voters_count']
        );

        // Ensure settings exist in sandbox for polls
        File::put(
            \Ethernick\ActivityPubCore\Services\ActivityPubUtils::settingsPath(),
            YAML::dump([
                'actors' => ['enabled' => true, 'federated' => true, 'type' => 'Person'],
                'notes' => ['enabled' => true, 'federated' => true, 'type' => 'Note'],
                'activities' => ['enabled' => true, 'federated' => true, 'type' => 'Activity'],
                'polls' => ['enabled' => true, 'federated' => true, 'type' => 'Question'],
            ])
        );
        \Statamic\Facades\Blink::forget('activitypub-settings');

        $this->setupCollections(['actors', 'activities', 'polls']);

        $originalDate = now()->subDays(5)->startOfSecond();
        $actor = Entry::make()
            ->collection('actors')
            ->slug('test-poll-actor')
            ->data(['title' => 'Poll Actor', 'is_internal' => true]);
        $actor->save();

        $poll = Entry::make()
            ->collection('polls')
            ->slug('test-poll-entry')
            ->date($originalDate)
            ->data([
                'title' => 'Favorite Framework?',
                'content' => 'Favorite Framework?',
                'actor' => $actor->id(),
                'is_internal' => true,
                'published' => true,
                'date' => $originalDate->toIso8601String(),
                'options' => [
                    ['name' => 'Statamic', 'count' => 0],
                    ['name' => 'Laravel', 'count' => 0],
                ],
                'voters_count' => 0,
            ]);
        $poll->save();

        // Clear creation activities
        Entry::query()
            ->where('collection', 'activities')
            ->get()
            ->filter(function ($entry) use ($poll) {
                $object = $entry->get('object');
                return $object && in_array($poll->id(), (array) $object);
            })
            ->each->delete();

        // Reload poll
        $poll = Entry::find($poll->id());

        // 2. Simulate vote tally update: options counts change, but title and content are identical
        $poll->set('options', [
            ['name' => 'Statamic', 'count' => 1],
            ['name' => 'Laravel', 'count' => 0],
        ]);
        $poll->set('voters_count', 1);
        $poll->save();

        // 3. Assert Update activity WAS generated because 'options' is a registered substantive field!
        $activity = Entry::query()
            ->where('collection', 'activities')
            ->get()
            ->first(function ($entry) use ($poll) {
                $object = $entry->get('object');
                return $object && in_array($poll->id(), (array) $object);
            });

        $this->assertNotNull($activity, 'An Update activity must be generated when substantive fields change.');
        $this->assertEquals('Update', $activity->get('type'));

        // 4. Assert creation date remained frozen, and updated timestamp was set
        $reloaded = Entry::find($poll->id());
        $this->assertEquals($originalDate->toIso8601String(), $reloaded->date()->toIso8601String(), 'Creation date must remain immutable.');
        $this->assertNotNull($reloaded->get('updated'), 'Updated timestamp must be set on substantive field changes.');
    }

    public function test_broadcast_policy_and_supplement_can_suppress_activity()
    {
        $actor = Entry::make()
            ->collection('actors')
            ->slug('test-policy-actor')
            ->data(['title' => 'Policy Actor', 'is_internal' => true]);
        $actor->save();

        // 1. Test entry-level supplement suppression
        $suppressedNote = Entry::make()
            ->collection('notes')
            ->slug('test-suppressed-note')
            ->data([
                'content' => 'Do not broadcast this create',
                'actor' => $actor->id(),
                'is_internal' => true,
                'published' => true,
            ]);
        $suppressedNote->setSupplement('activitypub_suppress_broadcast', true);
        $suppressedNote->save();

        $activity = Entry::query()
            ->where('collection', 'activities')
            ->get()
            ->first(function ($entry) use ($suppressedNote) {
                $object = $entry->get('object');
                return $object && in_array($suppressedNote->id(), (array) $object);
            });

        $this->assertNull($activity, 'ActivityPub broadcast should be suppressed when activitypub_suppress_broadcast supplement is set.');

        // 2. Test policy hook suppression
        \Ethernick\ActivityPubCore\Services\ActivityPubTypes::registerBroadcastPolicy(function (string $type, $entry) {
            if ($entry->get('suppress_by_policy')) {
                return false;
            }
            return true;
        });

        $policyNote = Entry::make()
            ->collection('notes')
            ->slug('test-policy-suppressed-note')
            ->data([
                'content' => 'Policy says no',
                'actor' => $actor->id(),
                'is_internal' => true,
                'published' => true,
                'suppress_by_policy' => true,
            ]);
        $policyNote->save();

        $policyActivity = Entry::query()
            ->where('collection', 'activities')
            ->get()
            ->first(function ($entry) use ($policyNote) {
                $object = $entry->get('object');
                return $object && in_array($policyNote->id(), (array) $object);
            });

        $this->assertNull($policyActivity, 'ActivityPub broadcast should be suppressed when registered policy returns false.');
    }
}
