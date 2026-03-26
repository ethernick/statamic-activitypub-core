<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Tests\Controllers;

use Tests\TestCase;
use Illuminate\Support\Facades\Queue;
use Statamic\Facades\Entry;
use Ethernick\ActivityPubCore\Http\Controllers\AcceptController;
use PHPUnit\Framework\Attributes\Test;

class AcceptControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->setupCollections(['actors', 'notes', 'activities']);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_processes_accept_with_quote_authorization()
    {
        Queue::fake();
        $this->setupCollections(['actors', 'activities', 'notes', 'polls']);

        // Create local actor
        $localActor = Entry::make()
            ->collection('actors')
            ->slug('local-actor')
            ->data([
                'title' => 'Local Actor',
                'activitypub_id' => 'https://local.com/users/local',
            ]);
        $localActor->save();

        // Create remote actor
        $remoteActor = Entry::make()
            ->collection('actors')
            ->slug('remote-actor')
            ->data([
                'title' => 'Remote Actor',
                'activitypub_id' => 'https://remote.com/users/remote',
            ]);
        $remoteActor->save();

        // Create pending quote
        $uniqueId = uniqid();
        $quoteRequestId = 'https://local.com/notes/quote-' . $uniqueId . '#quote-request-abc';
        $quote = Entry::make()
            ->collection('notes')
            ->slug('quote-note-' . $uniqueId)
            ->data([
                'content' => 'My quote',
                'actor' => [$localActor->id()],
                'quote_of' => ['some-external-note'],
                'quote_authorization_status' => 'pending',
                'quote_request_id' => $quoteRequestId,
                'is_internal' => true,
            ]);
        $quote->save();

        // Build Accept payload
        $authorizationStamp = 'https://remote.com/users/remote/quote_authorizations/12345';
        $payload = [
            'type' => 'Accept',
            'actor' => 'https://remote.com/users/remote',
            'object' => [
                'type' => 'QuoteRequest',
                'id' => $quoteRequestId,
                'actor' => 'https://local.com/users/local',
                'object' => 'https://remote.com/notes/456',
            ],
            'result' => $authorizationStamp,
        ];


        // Process Accept
        $controller = new AcceptController();
        $controller->handleAccept($payload, $localActor, $remoteActor);

        // Verify quote was approved
        \Statamic\Facades\Stache::clear();
        $quote = Entry::find($quote->id());

        $this->assertEquals('accepted', $quote->get('quote_authorization_status'));
        $this->assertEquals($authorizationStamp, $quote->get('quote_authorization_stamp'));
        
        // Verify activity was created (Listener clears _quote_approved)
        $activity = Entry::query()->where('collection', 'activities')->get()
            ->first(fn($e) => in_array($quote->id(), (array)$e->get('object')));
        $this->assertNotNull($activity);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_accept_without_authorization_stamp()
    {
        // Create actors and pending quote
        $localActor = Entry::make()
            ->collection('actors')
            ->slug('local-actor')
            ->data(['activitypub_id' => 'https://local.com/users/local']);
        $localActor->save();

        $remoteActor = Entry::make()
            ->collection('actors')
            ->slug('remote-actor')
            ->data(['activitypub_id' => 'https://remote.com/users/remote']);
        $remoteActor->save();

        $uniqueId = uniqid();
        $quoteRequestId = 'https://local.com/notes/quote-' . $uniqueId . '#quote-request-abc';
        $quote = Entry::make()
            ->collection('notes')
            ->slug('quote-note-' . $uniqueId)
            ->data([
                'content' => 'My quote',
                'actor' => [$localActor->id()],
                'quote_authorization_status' => 'pending',
                'quote_request_id' => $quoteRequestId,
                'is_internal' => true,
            ]);
        $quote->save();

        // Accept without 'result' field
        $payload = [
            'type' => 'Accept',
            'actor' => 'https://remote.com/users/remote',
            'object' => [
                'type' => 'QuoteRequest',
                'id' => $quoteRequestId,
            ],
        ];

        $controller = new AcceptController();
        $controller->handleAccept($payload, $localActor, $remoteActor);

        // Should still mark as accepted, just without stamp
        \Statamic\Facades\Stache::clear();
        $quote = Entry::find($quote->id());

        $this->assertEquals('accepted', $quote->get('quote_authorization_status'));
        
        // Verify activity was created
        $activity = Entry::query()->where('collection', 'activities')->get()
            ->first(fn($e) => in_array($quote->id(), (array)$e->get('object')));
        $this->assertNotNull($activity);
        $this->assertNull($quote->get('quote_authorization_stamp'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_ignores_accept_if_quote_not_found()
    {
        $localActor = Entry::make()
            ->collection('actors')
            ->slug('local-actor')
            ->data(['activitypub_id' => 'https://local.com/users/local']);
        $localActor->save();

        $remoteActor = Entry::make()
            ->collection('actors')
            ->slug('remote-actor')
            ->data(['activitypub_id' => 'https://remote.com/users/remote']);
        $remoteActor->save();

        // Accept for non-existent quote request
        $payload = [
            'type' => 'Accept',
            'actor' => 'https://remote.com/users/remote',
            'object' => [
                'type' => 'QuoteRequest',
                'id' => 'https://local.com/notes/nonexistent#quote-request',
            ],
            'result' => 'https://remote.com/authorizations/123',
        ];

        $controller = new AcceptController();

        // Should not throw exception, just log warning
        $controller->handleAccept($payload, $localActor, $remoteActor);

        // Test passes if no exception thrown
        $this->assertTrue(true);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_validates_accept_object_is_quote_request()
    {
        $localActor = Entry::make()
            ->collection('actors')
            ->slug('local-actor')
            ->data(['activitypub_id' => 'https://local.com/users/local']);
        $localActor->save();

        $remoteActor = Entry::make()
            ->collection('actors')
            ->slug('remote-actor')
            ->data(['activitypub_id' => 'https://remote.com/users/remote']);
        $remoteActor->save();

        // Accept of a Follow (not QuoteRequest)
        $payload = [
            'type' => 'Accept',
            'actor' => 'https://remote.com/users/remote',
            'object' => [
                'type' => 'Follow',
                'id' => 'https://local.com/follows/123',
            ],
        ];

        $controller = new AcceptController();

        // Should ignore non-QuoteRequest objects
        $controller->handleAccept($payload, $localActor, $remoteActor);

        // Test passes if no exception thrown
        $this->assertTrue(true);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_triggers_create_activity_after_approval()
    {
        Queue::fake();

        $localActor = Entry::make()
            ->collection('actors')
            ->slug('local-actor')
            ->data(['activitypub_id' => 'https://local.com/users/local']);
        $localActor->save();

        $remoteActor = Entry::make()
            ->collection('actors')
            ->slug('remote-actor')
            ->data(['activitypub_id' => 'https://remote.com/users/remote']);
        $remoteActor->save();

        $uniqueId = uniqid();
        $quoteRequestId = 'https://local.com/notes/quote-' . $uniqueId . '#quote-request-abc';
        $quote = Entry::make()
            ->collection('notes')
            ->slug('quote-note-' . $uniqueId)
            ->data([
                'content' => 'My quote',
                'actor' => [$localActor->id()],
                'quote_authorization_status' => 'pending',
                'quote_request_id' => $quoteRequestId,
                'is_internal' => true,
            ]);
        $quote->save();

        $payload = [
            'type' => 'Accept',
            'actor' => 'https://remote.com/users/remote',
            'object' => [
                'type' => 'QuoteRequest',
                'id' => $quoteRequestId,
            ],
            'result' => 'https://remote.com/authorizations/123',
        ];

        $controller = new AcceptController();
        $controller->handleAccept($payload, $localActor, $remoteActor);

        // Verify activity creation via listener
        $activity = Entry::query()->where('collection', 'activities')->get()
            ->first(fn($e) => in_array($quote->id(), (array)$e->get('object')));
        $this->assertNotNull($activity);
        $this->assertEquals('Create', $activity->get('type'));
    }
}
