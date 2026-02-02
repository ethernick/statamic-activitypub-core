<?php

namespace Ethernick\ActivityPubCore\Tests;

use Ethernick\ActivityPubCore\Http\Controllers\AnnounceController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Tests\TestCase;

class AnnounceControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $actor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['statamic.editions.pro' => true]);

        // Create a user and an actor profile
        $this->user = User::make()->email('test@example.com')->makeSuper();
        $this->user->save();

        $this->actor = Entry::make()
            ->collection('actors')
            ->slug('testuser')
            ->data(['title' => 'Test User']);
        $this->actor->save();

        $this->user->set('actors', [$this->actor->id()])->save();
        $this->actingAs($this->user);
    }

    #[Test]
    public function it_can_announce_a_url()
    {
        $objectUrl = 'https://example.com/notes/123';

        $response = $this->postJson(route('statamic.cp.activitypub.announce'), [
            'object_url' => $objectUrl,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'success']);

        // Check if Announce activity was created
        $activity = Entry::query()
            ->where('collection', 'activities')
            ->get()
            ->filter(function ($entry) use ($objectUrl) {
                return $entry->get('type') === 'Announce' && $entry->get('object') === $objectUrl;
            })
            ->first();

        $this->assertNotNull($activity);
        $this->assertEquals([$this->actor->id()], $activity->get('actor'));
    }

    #[Test]
    public function it_ignores_duplicate_announce()
    {
        $objectUrl = 'https://example.com/notes/duplicate';

        // announce once
        $this->postJson(route('statamic.cp.activitypub.announce'), ['object_url' => $objectUrl]);

        // announce again
        $response = $this->postJson(route('statamic.cp.activitypub.announce'), ['object_url' => $objectUrl]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'ignored']);

        // Check count is 1
        $count = Entry::query()
            ->where('collection', 'activities')
            ->get()
            ->filter(function ($entry) use ($objectUrl) {
                return $entry->get('type') === 'Announce' && $entry->get('object') === $objectUrl;
            })
            ->count();

        $this->assertEquals(1, $count);
    }

    #[Test]
    public function it_can_undo_announce()
    {
        $objectUrl = 'https://example.com/notes/undo';

        // announce first
        $this->postJson(route('statamic.cp.activitypub.announce'), ['object_url' => $objectUrl]);

        // undo
        $response = $this->postJson(route('statamic.cp.activitypub.undo-announce'), ['object_url' => $objectUrl]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'success']);

        // Check Undo activity exists
        $undo = Entry::query()
            ->where('collection', 'activities')
            ->get()
            ->filter(function ($entry) use ($objectUrl) {
                return $entry->get('type') === 'Undo'
                    && str_contains($entry->get('content'), 'Undid Announce');
            })
            ->first();

        $this->assertNotNull($undo);
        // The object of the Undo should be the ID of the original Announce.
        // But in our controller we just check if an Undo activity was created.
        // We can verify the object matches if we want, but simple existence is enough for this test level.
    }
}
