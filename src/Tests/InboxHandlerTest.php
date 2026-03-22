<?php

namespace Ethernick\ActivityPubCore\Tests;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Ethernick\ActivityPubCore\Jobs\InboxHandler;
use Illuminate\Support\Carbon;

class InboxHandlerTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        // Ensure collections exist in sandbox
        $this->setupCollections(['actors', 'activities', 'notes', 'polls']);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_fetches_missing_parent_note()
    {
        Http::fake([
            'https://example.com/parent-note' => Http::response([
                'id' => 'https://example.com/parent-note',
                'type' => 'Note',
                'content' => 'Parent Content',
                'attributedTo' => 'https://example.com/actor',
                'published' => now()->subHour()->toIso8601String(),
            ]),
            'https://example.com/actor' => Http::response([
                'id' => 'https://example.com/actor',
                'type' => 'Person',
                'preferredUsername' => 'actor',
            ])
        ]);

        $payload = [
            'id' => 'https://example.com/child-note',
            'type' => 'Note',
            'content' => 'Child Content',
            'attributedTo' => 'https://example.com/actor',
            'inReplyTo' => 'https://example.com/parent-note',
            'to' => ['https://www.w3.org/ns/activitystreams#Public'],
        ];

        // Ensure parent does not exist yet
        $this->assertNull(Entry::query()->where('activitypub_id', 'https://example.com/parent-note')->first());

        $handler = new \Ethernick\ActivityPubCore\Jobs\InboxHandler();
        $resolver = new \Ethernick\ActivityPubCore\Services\ActorResolver();
        $actor = $resolver->resolve('https://example.com/actor');

        // Mock Local Actor
        $localUser = User::make()->id('local-user')->save();
        $localActor = Entry::make()
            ->collection('actors')
            ->slug('local-actor')
            ->data(['user' => 'local-user', 'blocks' => [], 'activitypub_id' => 'https://example.com/local-actor']);
        $localActor->save();

        $activity = [
            'type' => 'Create',
            'id' => 'https://example.com/child-activity',
            'actor' => 'https://example.com/actor',
            'object' => [
                'type' => 'Note',
                'id' => 'https://example.com/child-note',
                'content' => 'Child Content',
                'attributedTo' => 'https://example.com/actor',
                'inReplyTo' => 'https://example.com/parent-note',
                'to' => ['https://www.w3.org/ns/activitystreams#Public', 'https://example.com/local-actor'],
            ]
        ];
        // $job = new \Ethernick\ActivityPubCore\Jobs\ProcessInbox($activity);
        // $job->handle(); 

        // Invoke handler directly
        // handle(array $payload, $localActor, $externalActor)
        // For a public inbox delivery, localActor might be null or specific user. 
        // We'll pass null as localActor (public inbox) and the sender as externalActor.
        $handler->handle($activity, $localActor, $actor);

        // Verify Child Created
        $child = Entry::query()->where('activitypub_id', 'https://example.com/child-note')->first();
        $this->assertNotNull($child);

        // Verify Parent Created via fetch
        // Verify Parent Created via fetch
        /** @var \Statamic\Entries\Entry|null $fetchedParentEntry */
        $fetchedParentEntry = Entry::query()->where('activitypub_id', 'https://example.com/parent-note')->first();

        $this->assertNotNull($fetchedParentEntry);
        if ($fetchedParentEntry) {
            $this->assertEquals('Parent Content', $fetchedParentEntry->get('content'));
        }

        // Verify child points to parent
        $this->assertEquals('https://example.com/parent-note', $child->get('in_reply_to'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_saves_activity_with_correct_date_from_payload()
    {
        $this->actingAs(User::make()->id('admin')->makeSuper()->save());

        // Create local actor
        $localActor = Entry::make()->collection('actors')->slug('me')->data(['title' => 'Me']);
        $localActor->save();

        // Create external actor
        $externalActor = Entry::make()->collection('actors')->slug('sender')->data(['title' => 'Sender', 'activitypub_id' => 'https://example.com/sender']);
        $externalActor->save();

        // Payload with a past date
        $pastDate = '2023-01-01T12:00:00Z';
        $payload = [
            'id' => 'https://example.com/activity/1',
            'type' => 'Create',
            'actor' => 'https://example.com/sender',
            'object' => 'https://example.com/note/1',
            'published' => $pastDate,
            'content' => 'Test Content',
        ];

        // Run Handler
        $handler = new InboxHandler();
        $handler->handle($payload, $localActor, $externalActor);

        // Check stored activity
        $slug = md5($payload['id']);
        $activity = Entry::query()->where('collection', 'activities')->where('slug', $slug)->first();

        $this->assertNotNull($activity);

        // This assertion is expected to FAIL currently because code uses now()
        $this->assertEquals(
            Carbon::parse($pastDate)->timestamp,
            $activity->date()->timestamp,
            'Activity date should match payload published date'
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_saves_msg_as_sensitive_if_specified()
    {
        $this->actingAs(User::make()->id('admin')->makeSuper()->save());

        $localActor = Entry::make()->collection('actors')->slug('me')->data(['title' => 'Me']);
        $localActor->save();

        $externalActor = Entry::make()->collection('actors')->slug('sender')->data(['title' => 'Sender', 'activitypub_id' => 'https://example.com/sender']);
        $externalActor->save();

        // Make local actor follow external actor
        $localActor->set('following_actors', [$externalActor->id()]);
        $localActor->save();

        $payload = [
            'id' => 'https://example.com/activity/sensitive',
            'type' => 'Create',
            'actor' => 'https://example.com/sender',
            'object' => [
                'id' => 'https://example.com/note/sensitive',
                'type' => 'Note',
                'content' => 'Secret Content',
                'sensitive' => true,
                'summary' => 'Content Warning',
                'attributedTo' => 'https://example.com/sender',
                'published' => now()->toIso8601String(),
            ],
            'published' => now()->toIso8601String(),
        ];

        $handler = new InboxHandler();
        $handler->handle($payload, $localActor, $externalActor);

        $note = Entry::query()->where('collection', 'notes')->where('activitypub_id', 'https://example.com/note/sensitive')->first();

        $this->assertNotNull($note);
        $this->assertTrue($note->get('sensitive'));
        $this->assertEquals('Content Warning', $note->get('summary'));
    }
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_defaults_sensitive_summary_if_missing()
    {
        $this->actingAs(User::make()->id('admin')->makeSuper()->save());

        $localActor = Entry::make()->collection('actors')->slug('me')->data(['title' => 'Me']);
        $localActor->save();

        $externalActor = Entry::make()->collection('actors')->slug('sender')->data(['title' => 'Sender', 'activitypub_id' => 'https://example.com/sender']);
        $externalActor->save();

        $localActor->set('following_actors', [$externalActor->id()]);
        $localActor->save();

        $payload = [
            'id' => 'https://example.com/activity/sensitive-default',
            'type' => 'Create',
            'actor' => 'https://example.com/sender',
            'object' => [
                'id' => 'https://example.com/note/sensitive-default',
                'type' => 'Note',
                'content' => 'Secret Content',
                'sensitive' => true,
                // 'summary' is intentionally missing
                'attributedTo' => 'https://example.com/sender',
                'published' => now()->toIso8601String(),
            ],
            'published' => now()->toIso8601String(),
        ];

        $handler = new InboxHandler();
        $handler->handle($payload, $localActor, $externalActor);

        $note = Entry::query()->where('collection', 'notes')->where('activitypub_id', 'https://example.com/note/sensitive-default')->first();

        $this->assertNotNull($note);
        $this->assertTrue($note->get('sensitive'));
        $this->assertEquals('Sensitive Content', $note->get('summary'));
    }
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_updates_original_note_on_announce()
    {
        $this->actingAs(User::make()->id('admin')->makeSuper()->save());

        $localActor = Entry::make()->collection('actors')->slug('me')->data(['title' => 'Me']);
        $localActor->save();

        $booster = Entry::make()->collection('actors')->slug('booster')->data(['title' => 'Booster', 'activitypub_id' => 'https://example.com/booster']);
        $booster->save();

        // 1. Create Original Note (Past Date)
        $originalDate = now()->subDays(2);
        $note = Entry::make()
            ->collection('notes')
            ->slug('original-note')
            ->data([
                'content' => 'Original Content',
                'activitypub_id' => 'https://example.com/note/1',
                'date' => $originalDate->toIso8601String(),
                'actor' => 'original_author_id' // simplified for test
            ]);
        $note->date($originalDate); // Set actual entry date
        $note->save();

        // 2. Announce Activity
        $boostDate = now();
        $payload = [
            'id' => 'https://example.com/announce/1',
            'type' => 'Announce',
            'actor' => 'https://example.com/booster',
            'object' => 'https://example.com/note/1',
            'published' => $boostDate->toIso8601String(),
        ];

        // 3. Handle Announce
        $handler = new InboxHandler();
        $handler->handle($payload, $localActor, $booster);

        // Should update date
        \Statamic\Facades\Stache::clear();
        $updatedNote = Entry::find($note->id());

        // Should update date (check content data)
        $savedDate = \Illuminate\Support\Carbon::parse($updatedNote->get('date'));
        $this->assertTrue($savedDate->diffInSeconds($boostDate) < 5, 'Note date (in data) should be updated to boost time');

        // Should have boosted_by array
        $boostedBy = $updatedNote->get('boosted_by');
        $this->assertIsArray($boostedBy);
        $this->assertCount(1, $boostedBy);
        $this->assertEquals($booster->id(), $boostedBy[0]);

        // 5. Test Second Boost (Different Actor)
        $booster2 = Entry::make()->collection('actors')->slug('booster2')->data(['title' => 'Booster 2', 'activitypub_id' => 'https://example.com/booster2']);
        $booster2->save();

        $boostDate2 = now()->addHour();
        $payload2 = [
            'id' => 'https://example.com/announce/2',
            'type' => 'Announce',
            'actor' => 'https://example.com/booster2',
            'object' => 'https://example.com/note/1',
            'published' => $boostDate2->toIso8601String(),
        ];

        $handler->handle($payload2, $localActor, $booster2);

        $updatedNote = Entry::find($note->id());
        $boostedBy = $updatedNote->get('boosted_by');

        $this->assertCount(2, $boostedBy);
        $this->assertEquals($booster2->id(), $boostedBy[1]);
        $savedDate2 = \Illuminate\Support\Carbon::parse($updatedNote->get('date'));
        $this->assertTrue($savedDate2->diffInSeconds($boostDate2) < 5, 'Note date should be updated to second boost time');

        // 6. Test Idempotency (Same Boost Reprocessed - though Handler usually blocks by activity ID, let's verify logic doesn't dup if it slipped through or if we just want to ensure stability)
        // Actually, the handler logic usually checks `activities` collection for the activity ID first?
        // Let's rely on the internal logic we're about to write.
        // But for this test, let's just assert that submitting the exact same payload doesn't add a duplicate to the array.

        $handler->handle($payload2, $localActor, $booster2);

        $updatedNote = Entry::find($note->id());
        $boostedBy = $updatedNote->get('boosted_by');
        $this->assertCount(2, $boostedBy, 'Should not duplicate booster in array on re-run');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_accepts_reply_to_local_uri()
    {
        $this->actingAs(User::make()->id('admin')->makeSuper()->save());
        $localActor = Entry::make()->collection('actors')->slug('me')->data(['title' => 'Me']);
        $localActor->save();
        $externalApp = Entry::make()->collection('actors')->slug('ext')->data(['title' => 'Ext', 'activitypub_id' => 'https://example.com/ext']);
        $externalApp->save();

        // Create a local note without activitypub_id (standard production case)
        $note = Entry::make()
            ->collection('notes')
            ->slug('my-test-note')
            ->data(['title' => 'Original Note', 'is_internal' => true]);
        $note->save();

        $baseUrl = \Statamic\Facades\Site::selected()->absoluteUrl();
        $noteUrl = $baseUrl . '/notes/' . $note->slug();

        $payload = [
            'id' => 'https://example.com/activity/reply',
            'type' => 'Create',
            'actor' => 'https://example.com/ext',
            'object' => [
                'id' => 'https://example.com/note/reply',
                'type' => 'Note',
                'content' => 'I agree!',
                'inReplyTo' => $noteUrl,
                'attributedTo' => 'https://example.com/ext',
            ]
        ];

        $handler = new InboxHandler();
        $handler->handle($payload, $localActor, $externalApp);

        // Verify the note was created (meaning it passed the 'isReplyToKnown' anti-spam gate)
        $savedNote = Entry::query()->where('collection', 'notes')->where('activitypub_id', 'https://example.com/note/reply')->first();
        $this->assertNotNull($savedNote, 'Note should be saved because it is a reply to a known local note URI');

    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_accepts_mention_to_local_actor_handle()
    {
        $this->actingAs(User::make()->id('admin')->makeSuper()->save());
        $localActor = Entry::make()->collection('actors')->slug('nick')->data(['title' => 'Nick']);
        $localActor->save();
        
        $externalApp = Entry::make()->collection('actors')->slug('ext')->data(['title' => 'Ext', 'activitypub_id' => 'https://example.com/ext']);
        $externalApp->save();

        // The handle URL used in Fediverse is usually https://domain.com/@nick
        $myHandleUrl = url('/@' . $localActor->slug());

        $payload = [
            'id' => 'https://example.com/activity/mention',
            'type' => 'Create',
            'actor' => 'https://example.com/ext',
            'object' => [
                'id' => 'https://example.com/note/mention',
                'type' => 'Note',
                'content' => 'Hello @nick',
                'to' => [$myHandleUrl],
                'attributedTo' => 'https://example.com/ext',
            ]
        ];

        $handler = new InboxHandler();
        $handler->handle($payload, $localActor, $externalApp);

        // Verify the note was created (meaning it passed the 'isMentioned' anti-spam gate)
        $note = Entry::query()->where('collection', 'notes')->where('activitypub_id', 'https://example.com/note/mention')->first();
        $this->assertNotNull($note, 'Note should be saved because it mentions the local actor via handle URL');
    }
}

