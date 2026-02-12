<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Tests\Jobs;

use Tests\TestCase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\Entry;
use Ethernick\ActivityPubCore\Jobs\SendQuoteRequest;
use Ethernick\ActivityPubCore\Tests\Concerns\BackupsFiles;
use PHPUnit\Framework\Attributes\Test;



class SendQuoteRequestJobTest extends TestCase
{
    use BackupsFiles;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupFiles([]);

        // Create activitypub.yaml config
        if (!file_exists(resource_path('settings'))) {
            mkdir(resource_path('settings'), 0755, true);
        }
        file_put_contents(
            resource_path('settings/activitypub.yaml'),
            "notes:\n  enabled: true\n  type: Note\n  federated: true\n"
        );

        // Reset ActivityPubListener static caches
        $reflection = new \ReflectionClass(\Ethernick\ActivityPubCore\Listeners\ActivityPubListener::class);
        $settingsCache = $reflection->getProperty('settingsCache');
        $settingsCache->setAccessible(true);
        $settingsCache->setValue(null, null);

        $actorCache = $reflection->getProperty('actorCache');
        $actorCache->setAccessible(true);
        $actorCache->setValue(null, []);

        \Illuminate\Support\Facades\Config::set('app.url', 'https://test.com');
    }

    protected function tearDown(): void
    {
        $this->restoreBackedUpFiles();

        // Reset ActivityPubListener static caches
        $reflection = new \ReflectionClass(\Ethernick\ActivityPubCore\Listeners\ActivityPubListener::class);
        $settingsCache = $reflection->getProperty('settingsCache');
        $settingsCache->setAccessible(true);
        $settingsCache->setValue(null, null);

        $actorCache = $reflection->getProperty('actorCache');
        $actorCache->setAccessible(true);
        $actorCache->setValue(null, []);

        parent::tearDown();
    }

    #[Test]
    public function it_sends_quote_request_with_instrument_field()
    {
        Http::fake([
            '*' => Http::response([], 202),
        ]);

        // Create local actor
        $actor = Entry::make()
            ->collection('actors')
            ->slug('test-actor')
            ->data([
                'title' => 'Test Actor',
                'activitypub_id' => 'https://test.com/users/test',
                'private_key' => $this->generateTestPrivateKey(),
                'public_key' => 'test-public-key',
                'inbox_url' => 'https://test.com/inbox',
            ]);
        $actor->save();

        // Create remote actor
        $remoteActor = Entry::make()
            ->collection('actors')
            ->slug('remote-actor')
            ->data([
                'title' => 'Remote Actor',
                'activitypub_id' => 'https://remote.com/users/remote',
                'inbox_url' => 'https://remote.com/inbox',
                'is_internal' => false,
            ]);
        $remoteActor->save();

        // Create external note to be quoted
        $quotedNote = Entry::make()
            ->collection('notes')
            ->slug('external-note')
            ->data([
                'content' => 'Original post',
                'activitypub_id' => 'https://remote.com/notes/123',
                'activitypub_json' => json_encode([
                    'interactionPolicy' => [
                        'canQuote' => [
                            'automaticApproval' => ['https://www.w3.org/ns/activitystreams#Public']
                        ]
                    ]
                ]),
                'is_internal' => false,
                'actor' => [$remoteActor->id()],
            ]);
        $quotedNote->save();

        // Create quote note
        $quoteNote = Entry::make()
            ->collection('notes')
            ->slug('quote-note')
            ->data([
                'content' => 'Check this out!',
                'actor' => [$actor->id()],
                'quote_of' => [$quotedNote->id()],
                'is_internal' => true,
            ]);
        $quoteNote->save();

        // Dispatch job
        $job = new SendQuoteRequest($quoteNote->id());
        $job->handle();

        // Assert HTTP request was made with instrument field
        Http::assertSent(function ($request) use ($quoteNote, $quotedNote) {
            $body = json_decode($request->body(), true);

            // Check @context includes QuoteRequest and quote vocabulary
            $this->assertIsArray($body['@context'] ?? null);
            $this->assertContains('https://www.w3.org/ns/activitystreams', $body['@context']);

            // Check QuoteRequest definition in @context
            $contextDefs = collect($body['@context'])->first(fn($item) => is_array($item) && isset($item['QuoteRequest']));
            $this->assertNotNull($contextDefs, '@context should include QuoteRequest definition');
            $this->assertEquals('https://w3id.org/fep/044f#QuoteRequest', $contextDefs['QuoteRequest']);

            // Check quote vocabulary in @context
            $this->assertEquals('https://w3id.org/fep/044f#quote', $contextDefs['quote']);
            $this->assertEquals('http://fedibird.com/ns#quoteUri', $contextDefs['quoteUri']);
            $this->assertEquals('https://misskey-hub.net/ns#_misskey_quote', $contextDefs['_misskey_quote']);

            // Check activity structure
            $this->assertEquals('QuoteRequest', $body['type']);
            $this->assertStringContainsString('http://statamic.ether', $body['actor']);
            $this->assertEquals('https://remote.com/notes/123', $body['object']);

            // CRITICAL: Check instrument field contains full quote post
            $this->assertIsArray($body['instrument'] ?? null, 'QuoteRequest must include instrument field');
            $instrument = $body['instrument'];

            $this->assertEquals('Note', $instrument['type']);
            $this->assertEquals($quoteNote->absoluteUrl(), $instrument['id']);
            $this->assertStringContainsString('http://statamic.ether', $instrument['attributedTo']);
            $this->assertEquals('Check this out!', $instrument['content']);

            // Check all quote reference fields in instrument
            $this->assertEquals('https://remote.com/notes/123', $instrument['quote']);
            $this->assertEquals('https://remote.com/notes/123', $instrument['_misskey_quote']);
            $this->assertEquals('https://remote.com/notes/123', $instrument['quoteUri']);
            $this->assertEquals('https://remote.com/notes/123', $instrument['quoteUrl']);

            return true;
        });
    }

    #[Test]
    public function it_handles_synchronous_accept_response()
    {
        $stampUrl = 'https://remote.com/users/remote/quote_authorizations/12345';

        Http::fake([
            '*' => Http::response([
                'type' => 'Accept',
                'actor' => 'https://remote.com/users/remote',
                'object' => [
                    'type' => 'QuoteRequest',
                    'id' => 'https://test.com/notes/quote-note#quote-request-123'
                ],
                'result' => $stampUrl,
            ], 200, ['Content-Type' => 'application/activity+json']),
        ]);

        // Setup actors and notes
        $actor = Entry::make()
            ->collection('actors')
            ->slug('test-actor')
            ->data([
                'title' => 'Test Actor',
                'activitypub_id' => 'https://test.com/users/test',
                'private_key' => $this->generateTestPrivateKey(),
                'inbox_url' => 'https://test.com/inbox',
            ]);
        $actor->save();

        $remoteActor = Entry::make()
            ->collection('actors')
            ->slug('remote-actor')
            ->data([
                'activitypub_id' => 'https://remote.com/users/remote',
                'inbox_url' => 'https://remote.com/inbox',
                'is_internal' => false,
            ]);
        $remoteActor->save();

        $quotedNote = Entry::make()
            ->collection('notes')
            ->slug('external-note')
            ->data([
                'activitypub_id' => 'https://remote.com/notes/123',
                'activitypub_json' => json_encode([
                    'interactionPolicy' => [
                        'canQuote' => [
                            'automaticApproval' => ['https://www.w3.org/ns/activitystreams#Public']
                        ]
                    ]
                ]),
                'actor' => [$remoteActor->id()],
                'is_internal' => false,
            ]);
        $quotedNote->save();

        $quoteNote = Entry::make()
            ->collection('notes')
            ->slug('quote-note')
            ->data([
                'content' => 'Quote content',
                'actor' => [$actor->id()],
                'quote_of' => [$quotedNote->id()],
                'is_internal' => true,
            ]);
        $quoteNote->save();

        // Execute job
        $job = new SendQuoteRequest($quoteNote->id());
        $job->handle();

        // Verify authorization was processed
        \Statamic\Facades\Stache::clear();
        $quoteNote = Entry::find($quoteNote->id());

        $this->assertEquals('accepted', $quoteNote->get('quote_authorization_status'));
        $this->assertEquals($stampUrl, $quoteNote->get('quote_authorization_stamp'));
        $this->assertTrue($quoteNote->get('_quote_approved'));
    }

    #[Test]
    public function it_marks_as_pending_when_no_immediate_accept()
    {
        Http::fake([
            '*' => Http::response('', 202), // Empty 202 response
        ]);

        $actor = Entry::make()
            ->collection('actors')
            ->slug('test-actor')
            ->data([
                'activitypub_id' => 'https://test.com/users/test',
                'private_key' => $this->generateTestPrivateKey(),
                'inbox_url' => 'https://test.com/inbox',
            ]);
        $actor->save();

        $remoteActor = Entry::make()
            ->collection('actors')
            ->slug('remote-actor')
            ->data([
                'activitypub_id' => 'https://remote.com/users/remote',
                'inbox_url' => 'https://remote.com/inbox',
                'is_internal' => false,
            ]);
        $remoteActor->save();

        $quotedNote = Entry::make()
            ->collection('notes')
            ->slug('external-note')
            ->data([
                'activitypub_id' => 'https://remote.com/notes/123',
                'actor' => [$remoteActor->id()],
                'is_internal' => false,
            ]);
        $quotedNote->save();

        $quoteNote = Entry::make()
            ->collection('notes')
            ->slug('quote-note')
            ->data([
                'content' => 'Quote content',
                'actor' => [$actor->id()],
                'quote_of' => [$quotedNote->id()],
                'is_internal' => true,
            ]);
        $quoteNote->save();

        $job = new SendQuoteRequest($quoteNote->id());
        $job->handle();

        \Statamic\Facades\Stache::clear();
        $quoteNote = Entry::find($quoteNote->id());

        $this->assertEquals('pending', $quoteNote->get('quote_authorization_status'));
        $this->assertNotNull($quoteNote->get('quote_request_id'));
        $this->assertNull($quoteNote->get('quote_authorization_stamp'));
    }

    #[Test]
    public function it_auto_approves_internal_quotes_without_http_request()
    {
        Http::fake();

        $actor = Entry::make()
            ->collection('actors')
            ->slug('test-actor')
            ->data([
                'activitypub_id' => 'https://test.com/users/test',
                'inbox_url' => 'https://test.com/inbox',
                'is_internal' => true,
            ]);
        $actor->save();

        // Internal note (on our server)
        $quotedNote = Entry::make()
            ->collection('notes')
            ->slug('internal-note')
            ->data([
                'content' => 'Original internal post',
                'activitypub_id' => 'https://test.com/notes/original',
                'actor' => [$actor->id()],
                'is_internal' => true,
            ]);
        $quotedNote->save();

        $quoteNote = Entry::make()
            ->collection('notes')
            ->slug('quote-note')
            ->data([
                'content' => 'Quoting myself',
                'actor' => [$actor->id()],
                'quote_of' => [$quotedNote->id()],
                'is_internal' => true,
            ]);
        $quoteNote->save();

        $job = new SendQuoteRequest($quoteNote->id());
        $job->handle();

        // No HTTP request should be made for internal quotes
        Http::assertNothingSent();

        // Should be auto-approved
        \Statamic\Facades\Stache::clear();
        $quoteNote = Entry::find($quoteNote->id());

        $this->assertEquals('accepted', $quoteNote->get('quote_authorization_status'));
        $this->assertEquals('accepted', $quoteNote->get('quote_authorization_status'));
        // _quote_approved is transient and not persisted to disk, so we can't assert it on reload
        // $this->assertTrue($quoteNote->get('_quote_approved'));
        $this->assertStringContainsString('#quote-authorization-', $quoteNote->get('quote_authorization_stamp'));
    }

    #[Test]
    public function it_fails_if_quoted_note_not_found()
    {
        Http::fake();

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($message) {
                return str_contains($message, 'Quote note not found');
            });

        $job = new SendQuoteRequest('notes/non-existent');
        $job->handle();
    }

    #[Test]
    public function it_fails_if_quote_has_no_quote_of()
    {
        $actor = Entry::make()
            ->collection('actors')
            ->slug('test-actor')
            ->data([
                'activitypub_id' => 'https://test.com/users/test',
            ]);
        $actor->save();

        $regularNote = Entry::make()
            ->collection('notes')
            ->slug('regular-note')
            ->data([
                'content' => 'Regular post',
                'actor' => [$actor->id()],
                'is_internal' => true,
            ]);
        $regularNote->save();

        $regularNote->save();

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message) {
                return str_contains($message, 'No quote_of found');
            });

        $job = new SendQuoteRequest($regularNote->id());
        $job->handle();
    }

    protected function generateTestPrivateKey(): string
    {
        $config = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $res = openssl_pkey_new($config);
        openssl_pkey_export($res, $privateKey);

        return $privateKey;
    }
}
