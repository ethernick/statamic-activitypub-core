<?php

namespace Ethernick\ActivityPubCore\Tests\Regression;

use Tests\TestCase;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Ethernick\ActivityPubCore\Tests\Concerns\ProvidesSandbox;
use Ethernick\ActivityPubCore\Services\ActivityPubUtils;

class FederationSecurityRegressionTest extends TestCase
{
    use ProvidesSandbox;

    protected $user;
    protected $myActor;
    protected $otherActor;

    public function setUp(): void
    {
        parent::setUp();

        // 1. Setup Sandbox Collections
        $this->setupCollections(['actors', 'notes', 'polls', 'activities']);

        // 2. Setup Settings
        file_put_contents(ActivityPubUtils::settingsPath(), "
notes:
  enabled: true
  type: Note
  federated: true
polls:
  enabled: true
  type: Question
  federated: true
activities:
  enabled: true
  type: Activity
  federated: true
");

        // 3. Setup User and Actors
        $this->user = User::make()
            ->id('test-user')
            ->email('test@example.com')
            ->makeSuper();
        $this->user->save();

        $this->myActor = Entry::make()
            ->collection('actors')
            ->slug('my-actor')
            ->data([
                'title' => 'My Actor',
                'is_internal' => true,
                'user' => $this->user->id()
            ]);
        $this->myActor->save();

        // Link actor to user (Statamic user 'actors' field)
        $this->user->set('actors', [$this->myActor->id()])->save();

        $this->otherActor = Entry::make()
            ->collection('actors')
            ->slug('other-actor')
            ->data([
                'title' => 'Other Actor',
                'is_internal' => true
            ]);
        $this->otherActor->save();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_prevents_posting_as_unauthorized_actor()
    {
        $this->actingAs($this->user);

        // Try to post as 'other-actor' which is NOT in the user's actors list
        $response = $this->postJson(cp_route('activitypub.inbox.store-note'), [
            'content' => 'Illegal post',
            'actor' => $this->otherActor->id(),
        ]);

        // Should fail because actor-ownership check in NoteStoreHandler should catch it
        $response->assertStatus(500); // Exception results in 500 if not caught in controller
        $this->assertStringContainsString('Not authorized to post as this actor', $response->json('message') ?? $response->getContent());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_uses_generic_store_route_correctly()
    {
        $this->actingAs($this->user);

        // Verify that the named route 'activitypub.inbox.store-poll' works
        // (This confirms our route.php change using ->defaults('type', 'Question') works)
        $response = $this->postJson(cp_route('activitypub.inbox.store-poll'), [
            'content' => 'My Poll',
            'actor' => $this->myActor->id(),
            'options' => ['Option A', 'Option B']
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('message', 'Question created');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_propagates_internal_flag_from_authorized_actor()
    {
        $this->actingAs($this->user);

        // 1. Create a Note via CP (StoreHandler)
        $response = $this->postJson(cp_route('activitypub.inbox.store-note'), [
            'content' => 'Internal Note',
            'actor' => $this->myActor->id(),
        ]);

        $response->assertOk();
        $noteId = $response->json('id');
        $note = Entry::find($noteId);

        $this->assertTrue($note->get('is_internal'), 'Note should be marked as internal');

        // 2. Verify Activity was generated and is internal
        $activity = Entry::query()
            ->where('collection', 'activities')
            ->where('type', 'Create')
            ->get()
            ->filter(fn($a) => in_array($noteId, (array)$a->get('object')))
            ->first();

        $this->assertNotNull($activity, 'Activity should have been generated');
        $this->assertTrue($activity->get('is_internal'), 'Activity should inherit internal status');
        $this->assertContains('outbox', $activity->get('activitypub_collections', []), 'Activity should be in outbox');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_correctly_resolves_internal_flag_even_if_missing_on_actor_at_rest()
    {
        $this->actingAs($this->user);

        // Simulate an actor that is ours but has is_internal missing (old data)
        $this->myActor->set('is_internal', null)->save();

        $response = $this->postJson(cp_route('activitypub.inbox.store-note'), [
            'content' => 'Note for legacy actor',
            'actor' => $this->myActor->id(),
        ]);

        $response->assertOk();
        $note = Entry::find($response->json('id'));

        // ActivityPubListener check:
        // if (!$isInternal) {
        //     $user = User::current();
        //     if ($user && $user->get('actors') && in_array($actor->id(), $user->get('actors'))) {
        //         $isInternal = true;
        //     }
        // }
        // This logic should catch it.

        $this->assertTrue($note->get('is_internal'), 'Note should be internal even if actor is missing flag but belongs to user');
    }
}
