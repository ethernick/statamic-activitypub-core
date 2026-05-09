<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Tests;

use Tests\TestCase;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Statamic\Facades\Blink;
use Ethernick\ActivityPubCore\Transformers\ActivityPubObjectTransformer;

class ReplyJsonTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['statamic.editions.pro' => true]);
        
        // Ensure collections exist
        $this->setupCollections(['notes', 'activities', 'actors']);
        
        Blink::forget('activitypub-settings');
    }

    public function test_reply_to_note_generates_in_reply_to_and_mentions(): void
    {
        $actorA = $this->createActor('actor-a', 'Actor A');
        $actorB = $this->createActor('actor-b', 'Actor B');

        // Original note by Actor A
        $original = Entry::make()
            ->collection('notes')
            ->slug('test-original-note')
            ->data([
                'content' => 'Original content',
                'actor' => [$actorA->id()],
                'activitypub_id' => 'https://remote.test/notes/123',
                'is_internal' => true,
            ]);
        $original->save();

        // Reply by Actor B to Actor A's note
        $reply = Entry::make()
            ->collection('notes')
            ->slug('test-reply-note')
            ->data([
                'content' => 'This is a reply',
                'actor' => [$actorB->id()],
                'in_reply_to' => $original->id(),
                'is_internal' => true,
            ]);
        $reply->save();

        // Transform the reply
        $transformer = app(ActivityPubObjectTransformer::class);
        $data = $transformer->transform($reply);

        // Assertions
        $this->assertArrayHasKey('inReplyTo', $data);
        $this->assertEquals('https://remote.test/notes/123', $data['inReplyTo']);
        
        // Assert addressing (Actor A should be in CC)
        $this->assertArrayHasKey('cc', $data);
        $actorAUrl = url('@actor-a');
        $this->assertContains($actorAUrl, $data['cc']);
        
        // Assert Mention tag for Actor A
        $this->assertArrayHasKey('tag', $data);
        $mentions = collect($data['tag'])->where('type', 'Mention')->pluck('href')->toArray();
        $this->assertContains($actorAUrl, $mentions);
    }

    public function test_reply_to_activity_resolves_to_object_id(): void
    {
        $actorA = $this->createActor('actor-a', 'Actor A');
        $actorB = $this->createActor('actor-b', 'Actor B');

        // External activity notification for a Question/Poll
        $activity = Entry::make()
            ->collection('activities')
            ->slug('test-activity-question')
            ->data([
                'type' => 'Create',
                'actor' => [$actorA->id()],
                'activitypub_id' => 'https://remote.test/activities/456',
                'object' => [
                    'id' => 'https://remote.test/questions/789',
                    'type' => 'Question'
                ],
                'is_internal' => false,
            ]);
        $activity->save();

        // Reply to the ACTIVITY (common when clicking reply in the inbox notification)
        $reply = Entry::make()
            ->collection('notes')
            ->slug('test-reply-to-activity')
            ->data([
                'content' => 'Replying to the poll',
                'actor' => [$actorB->id()],
                'in_reply_to' => $activity->id(),
                'is_internal' => true,
            ]);
        $reply->save();

        // Transform the reply
        $transformer = app(ActivityPubObjectTransformer::class);
        $data = $transformer->transform($reply);

        // Assertions: Should reply to the QUESTION id, not the ACTIVITY id
        $this->assertArrayHasKey('inReplyTo', $data);
        $this->assertEquals('https://remote.test/questions/789', $data['inReplyTo']);
    }

    protected function createActor(string $slug, string $title): \Statamic\Contracts\Entries\Entry
    {
        $actor = Entry::make()
            ->collection('actors')
            ->slug($slug)
            ->data([
                'title' => $title,
                'is_internal' => true,
                'activitypub_id' => url('@' . $slug),
            ]);
        $actor->save();
        return $actor;
    }
}
