<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Tests\Integration;

use Tests\TestCase;
use Statamic\Facades\Entry;
use Ethernick\ActivityPubCore\Tests\Concerns\BackupsFiles;
use PHPUnit\Framework\Attributes\Test;

class QuoteJsonOutputTest extends TestCase
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
    public function it_includes_quote_fields_in_activitypub_json()
    {
        // Create actors
        $localActor = Entry::make()
            ->collection('actors')
            ->slug('local-actor')
            ->data([
                'title' => 'Local Actor',
                'activitypub_id' => 'https://test.com/users/local',
                'handle' => '@local@test.com',
                'is_internal' => true,
            ]);
        $localActor->save();

        $remoteActor = Entry::make()
            ->collection('actors')
            ->slug('remote-actor')
            ->data([
                'activitypub_id' => 'https://remote.com/users/remote',
                'is_internal' => false,
            ]);
        $remoteActor->save();

        // Create quoted note
        $quotedNote = Entry::make()
            ->collection('notes')
            ->slug('quoted-note')
            ->data([
                'content' => 'Original post',
                'activitypub_id' => 'https://remote.com/notes/original',
                'actor' => [$remoteActor->id()],
                'is_internal' => false,
            ]);
        $quotedNote->save();

        // Create quote with authorization
        $authStamp = 'https://remote.com/users/remote/quote_authorizations/12345';
        $quote = Entry::make()
            ->collection('notes')
            ->slug('quote-note')
            ->data([
                'content' => 'My thoughts on this',
                'actor' => [$localActor->id()],
                'quote_of' => [$quotedNote->id()],
                'quote_authorization_status' => 'accepted',
                'quote_authorization_stamp' => $authStamp,
                'is_internal' => true,
                'published' => true,
            ]);
        $quote->save();

        // Retrieve generated JSON
        \Statamic\Facades\Stache::clear();
        $quote = Entry::find($quote->id());
        $json = $quote->get('activitypub_json');

        $this->assertNotNull($json, 'ActivityPub JSON should be generated');

        $data = json_decode($json, true);
        $this->assertIsArray($data, 'JSON should be valid');

        // Verify @context includes quote vocabulary
        $this->assertIsArray($data['@context']);
        $contextArray = is_array($data['@context'][1] ?? null) ? $data['@context'][1] : [];

        $this->assertArrayHasKey('quote', $contextArray);
        $this->assertEquals('https://w3id.org/fep/044f#quote', $contextArray['quote']);

        $this->assertArrayHasKey('quoteUri', $contextArray);
        $this->assertEquals('http://fedibird.com/ns#quoteUri', $contextArray['quoteUri']);

        $this->assertArrayHasKey('_misskey_quote', $contextArray);
        $this->assertEquals('https://misskey-hub.net/ns#_misskey_quote', $contextArray['_misskey_quote']);

        $this->assertArrayHasKey('quoteAuthorization', $contextArray);
        $this->assertEquals('https://w3id.org/fep/044f#quoteAuthorization', $contextArray['quoteAuthorization']['@id']);
        $this->assertEquals('@id', $contextArray['quoteAuthorization']['@type']);

        // Verify quote fields in object
        $this->assertEquals('https://remote.com/notes/original', $data['quote']);
        $this->assertEquals('https://remote.com/notes/original', $data['_misskey_quote']);
        $this->assertEquals('https://remote.com/notes/original', $data['quoteUrl']);
        $this->assertEquals('https://remote.com/notes/original', $data['quoteUri']);
        $this->assertEquals($authStamp, $data['quoteAuthorization']);
    }

    #[Test]
    public function it_omits_quote_authorization_if_not_present()
    {
        $actor = Entry::make()
            ->collection('actors')
            ->slug('actor')
            ->data([
                'activitypub_id' => 'https://test.com/users/test',
                'handle' => '@test@test.com',
                'is_internal' => true,
            ]);
        $actor->save();

        $quotedNote = Entry::make()
            ->collection('notes')
            ->slug('quoted')
            ->data([
                'activitypub_id' => 'https://remote.com/notes/123',
                'is_internal' => false,
            ]);
        $quotedNote->save();

        // Quote without authorization stamp
        $quote = Entry::make()
            ->collection('notes')
            ->slug('quote')
            ->data([
                'content' => 'Quote without stamp',
                'actor' => [$actor->id()],
                'quote_of' => [$quotedNote->id()],
                'is_internal' => true,
                'published' => true,
            ]);
        $quote->save();

        \Statamic\Facades\Stache::clear();
        $quote = Entry::find($quote->id());
        $json = $quote->get('activitypub_json');
        $data = json_decode($json, true);

        // Should have quote fields
        $this->assertArrayHasKey('quote', $data);
        $this->assertArrayHasKey('quoteUrl', $data);

        // But not quoteAuthorization
        $this->assertArrayNotHasKey('quoteAuthorization', $data);
    }

    #[Test]
    public function it_includes_interaction_policy_with_quote_vocabulary()
    {
        // Configure settings to allow quotes
        $settingsPath = resource_path('settings/activitypub.yaml');
        $settingsDir = dirname($settingsPath);

        if (!file_exists($settingsDir)) {
            mkdir($settingsDir, 0755, true);
        }

        // Must include base config
        file_put_contents($settingsPath, "notes:\n  enabled: true\n  type: Note\n  federated: true\nallow_quotes: true\n");

        $actor = Entry::make()
            ->collection('actors')
            ->slug('actor')
            ->data([
                'activitypub_id' => 'https://test.com/users/test',
                'handle' => '@test@test.com',
                'is_internal' => true,
            ]);
        $actor->save();

        $note = Entry::make()
            ->collection('notes')
            ->slug('note')
            ->data([
                'content' => 'Regular post',
                'actor' => [$actor->id()],
                'is_internal' => true,
                'published' => true,
            ]);
        $note->save();

        \Statamic\Facades\Stache::clear();
        $note = Entry::find($note->id());
        $json = $note->get('activitypub_json');
        $data = json_decode($json, true);

        // Should have interactionPolicy
        $this->assertArrayHasKey('interactionPolicy', $data);
        $this->assertArrayHasKey('canQuote', $data['interactionPolicy']);
        $this->assertArrayHasKey('automaticApproval', $data['interactionPolicy']['canQuote']);
        $this->assertContains(
            'https://www.w3.org/ns/activitystreams#Public',
            $data['interactionPolicy']['canQuote']['automaticApproval']
        );

        // Verify @context includes gts vocabulary
        $contextArray = is_array($data['@context'][1] ?? null) ? $data['@context'][1] : [];
        $this->assertArrayHasKey('gts', $contextArray);
        $this->assertEquals('https://gotosocial.org/ns#', $contextArray['gts']);
    }

    #[Test]
    public function it_omits_quote_fields_for_non_quote_posts()
    {
        $actor = Entry::make()
            ->collection('actors')
            ->slug('actor')
            ->data([
                'activitypub_id' => 'https://test.com/users/test',
                'handle' => '@test@test.com',
                'is_internal' => true,
            ]);
        $actor->save();

        // Regular note without quote_of
        $note = Entry::make()
            ->collection('notes')
            ->slug('regular-note')
            ->data([
                'content' => 'Regular post',
                'actor' => [$actor->id()],
                'is_internal' => true,
                'published' => true,
            ]);
        $note->save();

        \Statamic\Facades\Stache::clear();
        $note = Entry::find($note->id());
        $json = $note->get('activitypub_json');

        // Even for regular posts, we might have basic json. 
        // But the test expects quote fields to be absent.
        $this->assertNotNull($json, 'ActivityPub JSON should be generated for internal note');

        $data = json_decode($json, true);

        // Should NOT have quote fields
        $this->assertArrayNotHasKey('quote', $data);
        $this->assertArrayNotHasKey('quoteUrl', $data);
        $this->assertArrayNotHasKey('quoteUri', $data);
        $this->assertArrayNotHasKey('_misskey_quote', $data);
        $this->assertArrayNotHasKey('quoteAuthorization', $data);
    }

    #[Test]
    public function it_uses_correct_context_vocabulary_types()
    {
        $actor = Entry::make()
            ->collection('actors')
            ->slug('actor')
            ->data([
                'activitypub_id' => 'https://test.com/users/test',
                'handle' => '@test@test.com',
                'is_internal' => true,
            ]);
        $actor->save();

        $note = Entry::make()
            ->collection('notes')
            ->slug('note')
            ->data([
                'content' => 'Test',
                'actor' => [$actor->id()],
                'is_internal' => true,
                'published' => true,
            ]);
        $note->save();

        \Statamic\Facades\Stache::clear();
        $note = Entry::find($note->id());
        $json = $note->get('activitypub_json');
        $data = json_decode($json, true);

        $contextArray = is_array($data['@context'][1] ?? null) ? $data['@context'][1] : [];

        // Check quoteAuthorization has correct @type
        $this->assertArrayHasKey('quoteAuthorization', $contextArray);
        $this->assertIsArray($contextArray['quoteAuthorization']);
        $this->assertEquals('@id', $contextArray['quoteAuthorization']['@type']);

        // Check interactionPolicy has @id type
        $this->assertArrayHasKey('interactionPolicy', $contextArray);
        $this->assertIsArray($contextArray['interactionPolicy']);
        $this->assertEquals('gts:interactionPolicy', $contextArray['interactionPolicy']['@id']);
        $this->assertEquals('@id', $contextArray['interactionPolicy']['@type']);
    }
}
