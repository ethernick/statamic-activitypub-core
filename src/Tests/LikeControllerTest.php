<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Tests;

use Tests\TestCase;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class LikeControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['statamic.editions.pro' => true]);
        // Cleanup test data on setup too
        $this->cleanupTestData();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }

    protected function cleanupTestData(): void
    {
        // Cleanup only test-created entries - preserve real user data
        Entry::query()->where('collection', 'activities')->get()
            ->filter(fn($e) => str_contains($e->get('object_url') ?? '', 'example.com/notes/'))
            ->each->delete();
        Entry::query()->where('collection', 'actors')->get()
            ->filter(fn($e) => str_starts_with($e->slug(), 'test-'))
            ->each->delete();
        // Only delete test users
        foreach (User::all() as $user) {
            if (str_starts_with($user->id() ?? '', 'test-') || str_contains($user->email() ?? '', 'test@example.com')) {
                $user->delete();
            }
        }
    }

    public function test_user_can_like_entry()
    {
        $user = User::make()->id('test-user')->email('test@example.com')->makeSuper();
        $user->save();
        $actor = Entry::make()->collection('actors')->slug('test-user')->data(['title' => 'Test User']);
        $actor->save();
        $user->set('actors', [$actor->id()]);
        $user->save();

        $this->actingAs($user);

        $objectUrl = 'https://example.com/notes/123';

        $response = $this->post('/cp/activitypub/like', [
            'object_url' => $objectUrl
        ]);

        $response->assertStatus(200);
        $this->assertEquals('success', $response->json('status'));

        // Verify activity created
        $activity = Entry::query()
            ->where('collection', 'activities')
            ->get()
            ->first(function ($entry) use ($objectUrl, $actor) {
                return $entry->get('type') === 'Like' &&
                    $entry->get('object_url') === $objectUrl &&
                    $entry->get('actor')[0] === $actor->id();
            });

        $this->assertNotNull($activity);
        $this->assertEquals(['outbox'], $activity->get('activitypub_collections'));
    }

    public function test_user_can_unlike_entry()
    {
        $user = User::make()->id('test-user')->email('test@example.com')->makeSuper();
        $user->save();
        $actor = Entry::make()->collection('actors')->slug('test-user')->data(['title' => 'Test User']);
        $actor->save();
        $user->set('actors', [$actor->id()]);
        $user->save();

        $this->actingAs($user);
        $objectUrl = 'https://example.com/notes/456';

        // LIKE first
        $this->post('/cp/activitypub/like', ['object_url' => $objectUrl]);

        // Confirm Exists - only count Likes for this specific object URL
        $count = Entry::query()
            ->where('collection', 'activities')
            ->get()
            ->filter(function ($e) use ($objectUrl) {
                return $e->get('type') === 'Like' && $e->get('object_url') === $objectUrl;
            })
            ->count();
        $this->assertEquals(1, $count);

        // UNLIKE
        $response = $this->post('/cp/activitypub/unlike', [
            'object_url' => $objectUrl
        ]);

        $response->assertStatus(200);

        // Verify UNDO activity created
        $undo = Entry::query()
            ->where('collection', 'activities')
            ->get()
            ->first(function ($e) {
                return $e->get('type') === 'Undo';
            });

        $this->assertNotNull($undo);
        // The Object of the Undo should be an ID, which matches the Like activity ID
        // Simplified check:
        $this->assertStringContainsString('Undo Like', $undo->get('title'));
    }

    public function test_guest_cannot_like()
    {
        $response = $this->postJson('/cp/activitypub/like', [
            'object_url' => 'https://example.com/stuff'
        ]);

        $response->assertStatus(401);
    }
}
