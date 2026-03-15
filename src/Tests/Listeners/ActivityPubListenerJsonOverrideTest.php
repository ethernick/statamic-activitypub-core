<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Tests\Listeners;

use Tests\TestCase;
use Statamic\Facades\Entry;
use Statamic\Facades\Collection;
use Ethernick\ActivityPubCore\Tests\Concerns\BackupsFiles;
use PHPUnit\Framework\Attributes\Test;

class ActivityPubListenerJsonOverrideTest extends TestCase
{
    use BackupsFiles;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupFiles([]);

        // Ensure settings exist
        if (!file_exists(resource_path('settings'))) {
            mkdir(resource_path('settings'), 0755, true);
        }
        file_put_contents(
            resource_path('settings/activitypub.yaml'),
            "notes:\n  enabled: true\n  type: Note\n  federated: true\n" .
            "activities:\n  enabled: true\n  type: Activity\n  federated: true\n"
        );

        // Ensure collections exist
        if (!Collection::findByHandle('actors')) {
            Collection::make('actors')->save();
        }
        if (!Collection::findByHandle('notes')) {
            Collection::make('notes')->save();
        }
        if (!Collection::findByHandle('activities')) {
            Collection::make('activities')->save();
        }

        // Clear Blink cache
        \Statamic\Facades\Blink::forget('activitypub-settings');
    }

    protected function tearDown(): void
    {
        $this->restoreBackedUpFiles();
        parent::tearDown();
    }

    #[Test]
    public function it_respects_manual_json_override()
    {
        $actor = Entry::make()
            ->collection('actors')
            ->slug('test-actor')
            ->data(['activitypub_id' => 'https://test.com/users/test', 'is_internal' => true]);
        $actor->save();

        $manualJson = json_encode(['@context' => 'https://www.w3.org/ns/activitystreams', 'type' => 'Note', 'content' => 'Manual Override']);

        $note = Entry::make()
            ->collection('notes')
            ->slug('manual-note')
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

    #[Test]
    public function it_auto_generates_json_when_override_is_disabled()
    {
        $actor = Entry::make()
            ->collection('actors')
            ->slug('test-actor')
            ->data(['activitypub_id' => 'https://test.com/users/test', 'is_internal' => true]);
        $actor->save();

        $note = Entry::make()
            ->collection('notes')
            ->slug('auto-note')
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

    #[Test]
    public function it_propagates_manual_json_to_activities()
    {
        $actor = Entry::make()
            ->collection('actors')
            ->slug('test-actor')
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
            ->slug('manual-note')
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
