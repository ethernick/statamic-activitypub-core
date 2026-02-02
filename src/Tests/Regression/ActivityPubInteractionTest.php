<?php

namespace Ethernick\ActivityPubCore\Tests\Regression;

use Tests\TestCase;
use Ethernick\ActivityPubCore\Tests\Concerns\BackupsFiles;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Ethernick\ActivityPubCore\Http\Controllers\ActorController;
use Illuminate\Support\Facades\Log;

class ActivityPubInteractionTest extends TestCase
{
    use BackupsFiles;

    protected $localActor;
    protected $localUser;
    protected $externalActor;

    public function setUp(): void
    {
        parent::setUp();

        // Backup settings file before modifying it
        $this->backupFile('resources/settings/activitypub.yaml');

        // 1. Cleanup test data only - preserve real user data
        Entry::query()->whereIn('collection', ['activities', 'notes'])->get()
            ->filter(function ($e) {
                $apId = $e->get('activitypub_id') ?? '';
                return str_contains($apId, 'external.com') || str_contains($apId, 'example.com');
            })
            ->each->delete();
        Entry::query()->where('collection', 'actors')->get()
            ->filter(function ($e) {
                $slug = $e->slug() ?? '';
                $apId = $e->get('activitypub_id') ?? '';
                return $slug === 'me'
                    || $slug === 'external-user-at-external-dot-com'
                    || str_contains($apId, 'external.com');
            })
            ->each->delete();
        \Statamic\Facades\Stache::clear();

        // Create activitypub.yaml config with federated: true
        if (!file_exists(resource_path('settings'))) {
            mkdir(resource_path('settings'), 0755, true);
        }
        file_put_contents(
            resource_path('settings/activitypub.yaml'),
            "notes:\n  enabled: true\n  type: Note\n  federated: true\npolls:\n  enabled: true\n  type: Question\n  federated: true\nactivities:\n  enabled: true\n  type: Activity\n"
        );

        // 2. Setup Local Actor
        $this->localUser = User::make()
            ->id('local-user')
            ->email('local@example.com')
            ->makeSuper()
            ->set('activitypub_handle', 'me');
        $this->localUser->save();

        $this->localActor = Entry::make()
            ->collection('actors')
            ->slug('me')
            ->data(['title' => 'Me', 'user' => 'local-user', 'is_internal' => true]);
        $this->localActor->save();

        // 3. Setup External Actor (needed for logic)
        $this->externalActor = Entry::make()
            ->collection('actors')
            ->slug('stranger')
            ->data(['activitypub_id' => 'https://example.com/users/stranger', 'title' => 'Stranger']);
        $this->externalActor->save();

        // 4. Setup Taxonomy (Critical for Outbox route)
        if (!\Statamic\Facades\Taxonomy::find('activitypub_collections')) {
            \Statamic\Facades\Taxonomy::make('activitypub_collections')->title('ActivityPub Collections')->save();
        }
        if (!\Statamic\Facades\Term::query()->where('taxonomy', 'activitypub_collections')->where('slug', 'outbox')->first()) {
            \Statamic\Facades\Term::make()->taxonomy('activitypub_collections')->slug('outbox')->data(['title' => 'Outbox'])->save();
        }

        // 5. Disable Signature Verification for Tests
        ActorController::$shouldSkipSignatureVerificationInTests = true;
    }

    public function tearDown(): void
    {
        ActorController::$shouldSkipSignatureVerificationInTests = false; // Reset

        // Restore activitypub.yaml from git to prevent test pollution
        // Restore backed up files
        $this->restoreBackedUpFiles();

        parent::tearDown();
    }

    protected function postInbox($handle, $payload)
    {
        return $this->postJson("/@{$handle}/inbox", $payload, [
            'Content-Type' => 'application/activity+json',
            'Accept' => 'application/activity+json',
        ]);
    }

    #[Test]
    public function external_user_can_create_note()
    {
        $this->withoutExceptionHandling();

        // Make Local Actor FOLLOW External Actor so we accept the note
        $this->localActor->set('following_actors', [$this->externalActor->id()]);
        $this->localActor->save();

        $noteId = 'https://example.com/notes/req-1';
        $payload = [
            'type' => 'Create',
            'id' => 'https://example.com/activities/create-1',
            'actor' => $this->externalActor->get('activitypub_id'),
            'object' => [
                'type' => 'Note',
                'id' => $noteId,
                'attributedTo' => $this->externalActor->get('activitypub_id'),
                'content' => 'Hello World Regression',
                'to' => ['https://www.w3.org/ns/activitystreams#Public'],
                'published' => now()->toIso8601String(),
            ],
            'published' => now()->toIso8601String(),
        ];

        // 1. Send Request
        $response = $this->postInbox('me', $payload);
        $response->assertStatus(202);

        // 2. Process
        $job = new \Ethernick\ActivityPubCore\Jobs\InboxHandler();
        // Be sure to pass fresh instances if needed, or the ones we have.
        // The Handler checks relationship on the local actor instance passed to it.
        $job->handle($payload, $this->localActor, $this->externalActor);

        // 3. Verify
        $note = Entry::query()->where('collection', 'notes')->where('activitypub_id', $noteId)->first();
        $this->assertNotNull($note, 'Note was not created.');
        $this->assertEquals('Hello World Regression', $note->get('content'));
    }

    #[Test]
    public function external_user_can_update_note()
    {
        $this->withoutExceptionHandling();

        // Setup: Follow & Create Note First
        $this->localActor->set('following_actors', [$this->externalActor->id()]);
        $this->localActor->save();

        // Create the valid Note
        $noteId = 'https://example.com/notes/req-2';
        $note = Entry::make()->collection('notes')->slug('req-2')->data([
            'activitypub_id' => $noteId,
            'content' => 'Original Content',
            'actor' => $this->externalActor->id()
        ]);
        $note->save();

        // Update Payload
        $payload = [
            'type' => 'Update',
            'id' => 'https://example.com/activities/update-1',
            'actor' => $this->externalActor->get('activitypub_id'),
            'object' => [
                'type' => 'Note',
                'id' => $noteId,
                'content' => 'Updated Content',
                'summary' => 'New Summary',
                'updated' => now()->toIso8601String(),
            ],
        ];

        // 1. Send Request
        $response = $this->postInbox('me', $payload);
        $response->assertStatus(202);

        // 2. Process
        $job = new \Ethernick\ActivityPubCore\Jobs\InboxHandler();
        $job->handle($payload, $this->localActor, $this->externalActor);

        // 3. Verify
        $updatedNote = $note->fresh();
        $this->assertEquals('Updated Content', $updatedNote->get('content'));
        $this->assertEquals('New Summary', $updatedNote->get('summary'));
    }

    #[Test]
    public function external_user_can_delete_note()
    {
        $this->withoutExceptionHandling();

        // Setup
        $this->localActor->set('following_actors', [$this->externalActor->id()]);
        $this->localActor->save();

        $noteId = 'https://example.com/notes/req-3';
        $note = Entry::make()->collection('notes')->slug('req-3')->data([
            'activitypub_id' => $noteId,
            'content' => 'To Be Deleted',
            'actor' => $this->externalActor->id()
        ]);
        $note->save();

        // Delete Payload
        $payload = [
            'type' => 'Delete',
            'id' => 'https://example.com/activities/delete-1',
            'actor' => $this->externalActor->get('activitypub_id'),
            'object' => [
                'type' => 'Note',
                'id' => $noteId
            ]
        ];

        // 1. Send Request
        $response = $this->postInbox('me', $payload);
        $response->assertStatus(202);

        // 2. Process
        $job = new \Ethernick\ActivityPubCore\Jobs\InboxHandler();
        $job->handle($payload, $this->localActor, $this->externalActor);

        // 3. Verify tombstone or deletion
        // Statamic delete() removes it.
        $deleted = Entry::find($note->id());
        $this->assertNull($deleted, 'Note should be deleted');
    }

    #[Test]
    public function local_user_outbox_contains_created_note()
    {
        $this->withoutExceptionHandling();
        $this->actingAs($this->localUser);

        // Ensure activities collection exists and has taxonomy
        $activities = \Statamic\Facades\Collection::findByHandle('activities')
            ?? \Statamic\Facades\Collection::make('activities')->title('Activities');
        $activities->taxonomies(['activitypub_collections'])->save();

        // 1. Create a Note locally
        // This simulates a user creating a post in the CP
        $note = Entry::make()
            ->collection('notes')
            ->slug('my-local-note-1')
            ->data([
                    'content' => 'Local Note Content 123',
                    'actor' => $this->localActor->id(),
                    'is_internal' => true,
                    'published' => true
                ]);
        $note->save();

        // Capture the actor ID before any operations that might affect it
        $actorId = $this->localActor->id();

        // Manually create the "Create" activity that the listener would normally generate
        // (Ensuring explicit outbox population for this test)
        $activity = Entry::make()
            ->collection('activities')
            ->slug('create-' . $note->slug())
            ->data([
                    'actor' => $actorId,
                    'object' => $note->id(),
                    'type' => 'Create',
                    'is_internal' => true,
                    'published' => \Illuminate\Support\Carbon::now()->toIso8601String(),
                    'activitypub_json' => json_encode([
                        '@context' => 'https://www.w3.org/ns/activitystreams',
                        'type' => 'Create',
                        'id' => 'http://test/activity/1',
                        'actor' => 'http://test/actor/1', // Simplified
                        'object' => [
                            'type' => 'Note',
                            'content' => 'Local Note Content 123'
                        ]
                    ])
                ]);
        $activity->save();

        // Tag the activity with 'outbox'
        // Use just the slug, not the full term ID
        $activity->set('activitypub_collections', ['outbox'])->save();

        // Clear Stache to ensure fresh data
        \Statamic\Facades\Stache::clear();

        // 2. Fetch Outbox
        // Validating the endpoint responds and contains the activity
        $response = $this->getJson('/@me/outbox');
        $response->assertOk();

        // 3. Verify
        $data = $response->json();
        $this->assertArrayHasKey('orderedItems', $data);

        // Find our note in the items (It should be a Create activity wrapping the note)
        $found = collect($data['orderedItems'])->first(function ($item) {
            if (isset($item['type']) && $item['type'] === 'Create') {
                $object = $item['object'] ?? [];
                // Item might be dehydrated or hydrated.
                // If object is an array it's hydrated.
                if (is_array($object)) {
                    $content = $object['content'] ?? '';
                    // Content might have HTML tags, so use str_contains
                    return str_contains($content, 'Local Note Content 123');
                }
            }
            return false;
        });

        $this->assertNotNull($found, 'Newly created note activity not found in outbox');
    }
}
