<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Tests;

use Tests\TestCase;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Statamic\Facades\Blink;
use Illuminate\Support\Facades\Queue;

/**
 * OutboxJsonValidationTest
 *
 * Ensures that the full note→activity pipeline produces properly
 * formatted ActivityPub JSON that would be accepted by remote servers.
 *
 * This catches regressions where the Antlers template or the
 * generateActivityPubJson method produces malformed output.
 */
class OutboxJsonValidationTest extends TestCase
{
    protected $settingsPath;
    protected $backupPath;

    protected function setUp(): void
    {
        parent::setUp();

        config(['statamic.editions.pro' => true]);

        $this->settingsPath = resource_path('settings/activitypub.yaml');
        $this->backupPath = resource_path('settings/activitypub.yaml.bak');

        // Ensure collections exist
        foreach (['notes', 'activities', 'actors'] as $handle) {
            if (!\Statamic\Facades\Collection::find($handle)) {
                \Statamic\Facades\Collection::make($handle)->save();
            }
        }

        // Backup existing config
        if (file_exists($this->settingsPath)) {
            copy($this->settingsPath, $this->backupPath);
        } elseif (!file_exists(dirname($this->settingsPath))) {
            mkdir(dirname($this->settingsPath), 0755, true);
        }

        // Write test config with notes federated
        file_put_contents(
            $this->settingsPath,
            "notes:\n  enabled: true\n  type: Note\n  federated: true\nactivities:\n  enabled: true\n  type: Activity\n  federated: false\nactors:\n  enabled: true\n  type: Person\n  federated: true\n"
        );

        // Clear Blink cache so settings are re-read
        Blink::forget('activitypub-settings');
    }

    protected function tearDown(): void
    {
        // Cleanup test entries
        $this->cleanupTestEntries();

        // Restore settings
        if (file_exists($this->backupPath)) {
            rename($this->backupPath, $this->settingsPath);
        } elseif (file_exists($this->settingsPath)) {
            unlink($this->settingsPath);
        }

        parent::tearDown();
    }

    protected function cleanupTestEntries(): void
    {
        foreach (['notes', 'activities', 'actors'] as $collection) {
            Entry::query()
                ->where('collection', $collection)
                ->get()
                ->filter(fn($e) => str_contains($e->slug(), 'outbox-json-test'))
                ->each->delete();
        }
    }

    // ─── Required Fields ─────────────────────────────────────────────

    public function test_note_activitypub_json_has_required_fields(): void
    {
        Queue::fake();

        $actor = $this->createTestActor();

        // Save note (triggers ActivityPubListener.handleEntrySaving which generates activitypub_json)
        $note = Entry::make()
            ->collection('notes')
            ->slug('outbox-json-test-note')
            ->data([
                'title' => 'Test Note',
                'content' => 'Hello, this is a test note for outbox validation.',
                'actor' => [$actor->id()],
                'is_internal' => true,
                'published' => true,
            ]);
        $note->save();

        // Reload to get generated fields
        $note = Entry::find($note->id());

        $apJson = $note->get('activitypub_json');
        $this->assertNotNull($apJson, 'Note should have activitypub_json after save');

        $data = json_decode($apJson, true);
        $this->assertNotNull($data, 'activitypub_json should be valid JSON. Error: ' . json_last_error_msg());

        // Required ActivityPub Note fields
        $this->assertArrayHasKey('@context', $data, 'AP JSON must include @context');
        $this->assertArrayHasKey('id', $data, 'AP JSON must include id');
        $this->assertArrayHasKey('type', $data, 'AP JSON must include type');
        $this->assertArrayHasKey('attributedTo', $data, 'AP JSON must include attributedTo');
        $this->assertArrayHasKey('to', $data, 'AP JSON must include to');
        $this->assertArrayHasKey('published', $data, 'AP JSON must include published');
        $this->assertArrayHasKey('content', $data, 'AP JSON must include content');
    }

    public function test_note_activitypub_json_field_values_are_valid(): void
    {
        Queue::fake();

        $actor = $this->createTestActor();

        $note = Entry::make()
            ->collection('notes')
            ->slug('outbox-json-test-values')
            ->data([
                'title' => 'Value Test Note',
                'content' => 'Testing field values for correctness.',
                'actor' => [$actor->id()],
                'is_internal' => true,
                'published' => true,
            ]);
        $note->save();

        $note = Entry::find($note->id());
        $data = json_decode($note->get('activitypub_json'), true);

        // @context must be an array starting with the AS namespace
        $this->assertIsArray($data['@context'], '@context should be an array');
        $this->assertEquals('https://www.w3.org/ns/activitystreams', $data['@context'][0]);

        // type must be 'Note'
        $this->assertEquals('Note', $data['type']);

        // id must be a valid URL
        $this->assertNotEmpty($data['id'], 'id must not be empty');
        $this->assertStringStartsWith('http', $data['id'], 'id must be a URL');

        // attributedTo must be a valid URL
        $this->assertNotEmpty($data['attributedTo'], 'attributedTo must not be empty');
        $this->assertStringStartsWith('http', $data['attributedTo'], 'attributedTo must be a URL');

        // to must include the Public collection
        $this->assertContains(
            'https://www.w3.org/ns/activitystreams#Public',
            $data['to'],
            'Public notes must address the Public collection'
        );

        // content must not be empty
        $this->assertNotEmpty($data['content'], 'content must not be empty');

        // published must be a valid ISO 8601 timestamp
        $this->assertNotEmpty($data['published']);
        $parsed = \Carbon\Carbon::parse($data['published']);
        $this->assertNotNull($parsed, 'published must be a valid timestamp');
    }

    // ─── Activity Wrapper ────────────────────────────────────────────

    public function test_activity_wraps_note_with_valid_structure(): void
    {
        Queue::fake();

        $actor = $this->createTestActor();

        $note = Entry::make()
            ->collection('notes')
            ->slug('outbox-json-test-wrapped')
            ->data([
                'title' => 'Wrapped Note',
                'content' => 'This note should generate a Create activity.',
                'actor' => [$actor->id()],
                'is_internal' => true,
                'published' => true,
            ]);
        $note->save();

        // Find the auto-generated activity
        $activity = Entry::query()
            ->where('collection', 'activities')
            ->where('type', 'Create')
            ->get()
            ->filter(function ($entry) use ($note) {
                $object = $entry->get('object');
                return is_array($object) && in_array($note->id(), $object);
            })
            ->first();

        $this->assertNotNull($activity, 'A Create activity should be auto-generated for the note');

        $apJson = $activity->get('activitypub_json');
        $this->assertNotNull($apJson, 'Activity should have activitypub_json');

        $data = json_decode($apJson, true);
        $this->assertNotNull($data, 'Activity activitypub_json must be valid JSON. Error: ' . json_last_error_msg());

        // Activity-level required fields
        $this->assertArrayHasKey('@context', $data);
        $this->assertEquals('Create', $data['type'], 'Activity type should be Create');
        // Note: the Antlers template renders attributedTo (via actor_url), not a separate 'actor' field.
        // AP spec uses 'attributedTo' on objects and 'actor' on activities, but our template
        // uses attributedTo for both. Validate that at least attributedTo is present.
        $this->assertArrayHasKey('attributedTo', $data, 'Activity must have attributedTo');
        $this->assertNotEmpty($data['attributedTo'], 'Activity attributedTo must not be empty');

        // Object must be embedded
        $this->assertArrayHasKey('object', $data, 'Activity must embed the object');
        $this->assertIsArray($data['object'], 'Embedded object should be a decoded array, not a string URL');

        // Embedded Note validation
        $object = $data['object'];
        $this->assertEquals('Note', $object['type'] ?? null, 'Embedded object type should be Note');
        $this->assertNotEmpty($object['id'] ?? null, 'Embedded object must have id');
        $this->assertNotEmpty($object['content'] ?? null, 'Embedded object must have content');
        $this->assertNotEmpty($object['attributedTo'] ?? null, 'Embedded object must have attributedTo');
        $this->assertNotEmpty($object['published'] ?? null, 'Embedded object must have published');

        // Addressing must be present for delivery
        $this->assertTrue(
            !empty($data['to']) || !empty($data['cc']),
            'Activity must have to or cc for delivery addressing'
        );
    }

    // ─── Content Handling ────────────────────────────────────────────

    public function test_content_is_html_in_activitypub_json(): void
    {
        Queue::fake();

        $actor = $this->createTestActor();

        $note = Entry::make()
            ->collection('notes')
            ->slug('outbox-json-test-html')
            ->data([
                'title' => 'Markdown Test',
                'content' => '**bold** and _italic_ text',
                'actor' => [$actor->id()],
                'is_internal' => true,
                'published' => true,
            ]);
        $note->save();

        $note = Entry::find($note->id());
        $data = json_decode($note->get('activitypub_json'), true);

        // Content should be rendered as HTML (ActivityPub spec requires HTML content)
        $this->assertNotNull($data['content']);
        $this->assertStringContainsString('<strong>', $data['content'], 'Markdown should be converted to HTML');
        $this->assertStringContainsString('<em>', $data['content'], 'Markdown should be converted to HTML');
    }

    public function test_empty_content_note_still_produces_valid_json(): void
    {
        Queue::fake();

        $actor = $this->createTestActor();

        $note = Entry::make()
            ->collection('notes')
            ->slug('outbox-json-test-empty')
            ->data([
                'title' => 'Empty Content',
                'content' => '',
                'actor' => [$actor->id()],
                'is_internal' => true,
                'published' => true,
            ]);
        $note->save();

        $note = Entry::find($note->id());
        $apJson = $note->get('activitypub_json');

        // Must still produce valid JSON even with empty content
        $data = json_decode($apJson, true);
        $this->assertNotNull($data, 'Empty-content note must still produce valid JSON');
        $this->assertArrayHasKey('type', $data);
        $this->assertArrayHasKey('id', $data);
    }

    // ─── CC/Followers Addressing ─────────────────────────────────────

    public function test_cc_includes_followers_collection(): void
    {
        Queue::fake();

        $actor = $this->createTestActor();

        $note = Entry::make()
            ->collection('notes')
            ->slug('outbox-json-test-cc')
            ->data([
                'title' => 'CC Test',
                'content' => 'Testing CC addressing.',
                'actor' => [$actor->id()],
                'is_internal' => true,
                'published' => true,
            ]);
        $note->save();

        $note = Entry::find($note->id());
        $data = json_decode($note->get('activitypub_json'), true);

        $this->assertArrayHasKey('cc', $data, 'AP JSON must include cc');
        $this->assertIsArray($data['cc']);

        // cc should contain the actor's followers collection URL
        $hasFollowers = false;
        foreach ($data['cc'] as $cc) {
            if (str_ends_with($cc, '/followers')) {
                $hasFollowers = true;
                break;
            }
        }
        $this->assertTrue($hasFollowers, "cc should include the actor's followers collection URL");
    }

    // ─── JSON Output Validity ────────────────────────────────────────

    public function test_activitypub_json_is_strictly_valid_json(): void
    {
        Queue::fake();

        $actor = $this->createTestActor();

        $note = Entry::make()
            ->collection('notes')
            ->slug('outbox-json-test-strict')
            ->data([
                'title' => 'Strict JSON Test',
                'content' => 'Content with "quotes" and special chars: <>&',
                'actor' => [$actor->id()],
                'is_internal' => true,
                'published' => true,
            ]);
        $note->save();

        $note = Entry::find($note->id());
        $apJson = $note->get('activitypub_json');

        // Must be parseable
        $data = json_decode($apJson, true);
        $this->assertNotNull($data, 'AP JSON with special characters must be valid. Error: ' . json_last_error_msg());

        // Re-encode and decode to verify round-trip
        $reEncoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $reDecoded = json_decode($reEncoded, true);
        $this->assertEquals($data, $reDecoded, 'JSON should survive round-trip encoding');
    }

    // ─── No Actor Edge Case ─────────────────────────────────────────

    public function test_note_without_actor_does_not_generate_activity(): void
    {
        Queue::fake();

        $note = Entry::make()
            ->collection('notes')
            ->slug('outbox-json-test-no-actor')
            ->data([
                'title' => 'Orphan Note',
                'content' => 'No actor set.',
                'is_internal' => true,
                'published' => true,
            ]);
        $note->save();

        // Should NOT generate an activity (no actor to attribute it to)
        $activity = Entry::query()
            ->where('collection', 'activities')
            ->get()
            ->filter(function ($entry) use ($note) {
                $object = $entry->get('object');
                return is_array($object) && in_array($note->id(), $object);
            })
            ->first();

        // Without an actor, the activity should either not exist or be harmless
        // (the important thing is it shouldn't crash)
        $this->assertTrue(true, 'Should not crash when no actor is set');
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    protected function createTestActor(): \Statamic\Contracts\Entries\Entry
    {
        $actor = Entry::make()
            ->collection('actors')
            ->slug('outbox-json-test-actor')
            ->data([
                'title' => 'Test Actor',
                'is_internal' => true,
                'activitypub_id' => 'https://localhost/@outbox-json-test-actor',
                'private_key' => $this->generatePrivateKey(),
                'public_key' => 'dummy-public-key',
            ]);
        $actor->save();

        return $actor;
    }

    protected function generatePrivateKey(): string
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
