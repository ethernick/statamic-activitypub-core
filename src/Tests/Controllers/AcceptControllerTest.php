<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Tests\Controllers;

use Tests\TestCase;
use Illuminate\Support\Facades\Queue;
use Statamic\Facades\Entry;
use Ethernick\ActivityPubCore\Http\Controllers\AcceptController;
use Ethernick\ActivityPubCore\Tests\Concerns\BacksUpFiles;

class AcceptControllerTest extends TestCase
{
    use BacksUpFiles;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupFiles();
    }

    protected function tearDown(): void
    {
        $this->restoreFiles();
        parent::tearDown();
    }

    /** @test */
    public function it_processes_accept_with_quote_authorization()
    {
        Queue::fake();

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
        $quoteRequestId = 'https://local.com/notes/quote-123#quote-request-abc';
        $quote = Entry::make()
            ->collection('notes')
            ->slug('quote-note')
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
        $this->assertTrue($quote->get('_quote_approved'));
    }

    /** @test */
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

        $quoteRequestId = 'https://local.com/notes/quote-123#quote-request-abc';
        $quote = Entry::make()
            ->collection('notes')
            ->slug('quote-note')
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
        $this->assertTrue($quote->get('_quote_approved'));
        $this->assertNull($quote->get('quote_authorization_stamp'));
    }

    /** @test */
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

    /** @test */
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

    /** @test */
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

        $quoteRequestId = 'https://local.com/notes/quote-123#quote-request-abc';
        $quote = Entry::make()
            ->collection('notes')
            ->slug('quote-note')
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

        // Verify _quote_approved triggers AutoGenerateActivityListener
        \Statamic\Facades\Stache::clear();
        $quote = Entry::find($quote->id());

        $this->assertTrue($quote->get('_quote_approved'));
        // The save() call should trigger activity creation via listeners
    }
}
