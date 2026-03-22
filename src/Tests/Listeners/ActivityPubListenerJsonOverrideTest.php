<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Tests\Listeners;

use Tests\TestCase;
use Statamic\Facades\Entry;
use Statamic\Facades\Collection;
use PHPUnit\Framework\Attributes\Test;

class ActivityPubListenerJsonOverrideTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure settings exist in sandbox
        file_put_contents(
            \Ethernick\ActivityPubCore\Services\ActivityPubUtils::settingsPath(),
            "notes:\n  enabled: true\n  type: Note\n  federated: true\n" .
            "activities:\n  enabled: true\n  type: Activity\n  federated: true\n"
        );

        // Ensure collections exist in sandbox
        foreach (['actors', 'notes', 'activities'] as $col) {
            if (!Collection::findByHandle($col)) {
                Collection::make($col)->save();
            }
        }

        // Clear Blink cache
        \Statamic\Facades\Blink::forget('activitypub-settings');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_respects_manual_json_override()
    {
        $actor = Entry::make()
            ->collection('actors')
            ->slug('test-json-actor')
            ->data(['activitypub_id' => 'https://test.com/users/test', 'is_internal' => true]);
        $actor->save();

        $manualJson = json_encode(['@context' => 'https://www.w3.org/ns/activitystreams', 'type' => 'Note', 'content' => 'Manual Override']);

        $note = Entry::make()
            ->collection('notes')
            ->slug('test-manual-note')
            ->data([
                'content' => 'Auto Content',
                'actor' => [$actor->id()],
                'is_internal' => true,
                'activitypub_json_manual' => true,
                'activitypub_json' => $manualJson,
            ]);

        // This should NOT overwrite activitypub_json
        $note->save();

        $this->assertEquals($manualJson, $note->get('activitypub_json'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_auto_generates_json_when_override_is_disabled()
    {
        $actor = Entry::make()
            ->collection('actors')
            ->slug('test-json-actor')
            ->data(['activitypub_id' => 'https://test.com/users/test', 'is_internal' => true]);
        $actor->save();

        $note = Entry::make()
            ->collection('notes')
            ->slug('test-auto-note')
            ->data([
                'content' => 'Auto Content',
                'actor' => [$actor->id()],
                'is_internal' => true,
                'activitypub_json_manual' => false,
            ]);

        // This should generate activitypub_json
        $note->save();

        $json = $note->get('activitypub_json');
        $this->assertNotNull($json);
        $decoded = json_decode((string) $json, true);
        $this->assertEquals('Note', $decoded['type']);
        $this->assertStringContainsString('Auto Content', $decoded['content']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_propagates_manual_json_to_activities()
    {
        $actor = Entry::make()
            ->collection('actors')
            ->slug('test-json-actor')
            ->data(['activitypub_id' => 'https://test.com/users/test', 'is_internal' => true]);
        $actor->save();

        $manualJson = json_encode([
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'type' => 'Note',
            'content' => 'Manual Override',
            'custom_prop' => 'custom_value'
        ]);

        $note = Entry::make()
            ->collection('notes')
            ->slug('test-manual-note')
            ->data([
                'content' => 'Auto Content',
                'actor' => [$actor->id()],
                'is_internal' => true,
                'activitypub_json_manual' => true,
                'activitypub_json' => $manualJson,
            ]);
        $note->save();

        $activity = Entry::make()
            ->collection('activities')
            ->slug('create-manual-note')
            ->data([
                'type' => 'Create',
                'actor' => [$actor->id()],
                'object' => [$note->id()],
                'is_internal' => true,
            ]);
        $activity->save();

        $activityJson = $activity->get('activitypub_json');
        $this->assertNotNull($activityJson);
        $decoded = json_decode((string) $activityJson, true);

        $this->assertEquals('Create', $decoded['type']);
        $this->assertEquals('custom_value', $decoded['object']['custom_prop']);
        $this->assertEquals('Manual Override', $decoded['object']['content']);
        $this->assertArrayNotHasKey('@context', $decoded['object']);
    }
}
