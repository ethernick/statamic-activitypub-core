<?php

namespace Ethernick\ActivityPubCore\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Tests\TestCase;

class SendLikeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure we have a user
        $user = User::findByEmail('test@example.com');
        if (!$user) {
            $user = User::make()->email('test@example.com')->data(['name' => 'Test User']);
            $user->save();
        }

        // Ensure we have an actor
        $actor = Entry::query()->where('collection', 'actors')->where('slug', 'testactor')->first();
        if (!$actor) {
            $actor = Entry::make()
                ->collection('actors')
                ->slug('testactor')
                ->data(['title' => 'Test Actor', 'is_internal' => true])
                ->published(true);
            $actor->save();
        }

        // Ensure link exists
        $actors = $user->get('actors', []);
        if (!in_array($actor->id(), $actors)) {
            $user->set('actors', array_merge($actors, [$actor->id()]))->save();
        }
    }

    protected function tearDown(): void
    {
        // cleanup
        $activity = Entry::query()->where('collection', 'activities')->where('slug', 'like-test')->first();
        if ($activity)
            $activity->delete();

        parent::tearDown();
    }

    public function test_send_like_creates_activity()
    {
        $user = User::findByEmail('test@example.com');
        $url = 'https://external.com/posts/' . \Illuminate\Support\Str::random(16);

        $response = $this->actingAs($user)
            ->withoutExceptionHandling()
            ->postJson('/activitypub/like', [
                'object_url' => $url
            ]);

        $response->assertStatus(200);

        // Verify entry created
        $activity = Entry::query()
            ->where('collection', 'activities')
            ->where('type', '=', 'Like')
            ->get()
            ->filter(function ($entry) use ($url) {
                return $entry->get('object_url') === $url;
            })
            ->first();

        $this->assertNotNull($activity);
        $this->assertEquals('Like', $activity->get('type'));

        $json = json_decode($activity->get('activitypub_json'), true);
        dump('Activity activitypub_json:', $activity->get('activitypub_json'));
        dump('Decoded JSON:', $json);
        dump('Expected URL:', $url);
        $this->assertNotNull($json);
        $this->assertEquals('Like', $json['type']);
        $this->assertEquals($url, $json['object']);

        $activity->delete();
    }

    public function test_send_unlike_creates_undo_activity()
    {
        $user = User::findByEmail('test@example.com');
        $actorId = $user->get('actors')[0];
        $url = 'https://external.com/posts/' . uniqid();

        // Create initial Like
        $like = Entry::make()
            ->collection('activities')
            ->slug('like-' . uniqid())
            ->data([
                'type' => 'Like',
                'object_url' => $url,
                'actor' => [$actorId],
                'is_internal' => true,
                'activitypub_collections' => ['outbox']
            ]);
        $like->save();

        $response = $this->actingAs($user)
            ->withoutExceptionHandling()
            ->postJson('/activitypub/unlike', [
                'object_url' => $url
            ]);

        $response->assertStatus(200);

        // Verify Undo entry created
        $undo = Entry::query()
            ->where('collection', 'activities')
            ->where('type', 'Undo')
            ->get()
            ->filter(function ($entry) use ($like) {
                // Check if object refers to our like
                $obj = $entry->get('object');
                if (is_array($obj))
                    $obj = $obj[0];
                return $obj === $like->id();
            })
            ->first();

        $this->assertNotNull($undo);
        // The object of Undo should be the Like activity ID
        $this->assertEquals([$like->id()], $undo->get('object'));

        // Verify Undo JSON
        $json = json_decode($undo->get('activitypub_json'), true);
        $this->assertNotNull($json);
        $this->assertEquals('Undo', $json['type']);

        $this->assertIsArray($json['object']);
        $this->assertEquals('Like', $json['object']['type']);
        $this->assertEquals($url, $json['object']['object']);

        // Cleanup
        $like->delete();
        $undo->delete();
    }
}
