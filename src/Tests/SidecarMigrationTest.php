<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Tests;

use Tests\TestCase;
use Statamic\Facades\Entry;
use Statamic\Facades\File;
use Statamic\Facades\YAML;
use Ethernick\ActivityPubCore\Tests\Concerns\ProvidesSandbox;
use Ethernick\ActivityPubCore\Storage\ActivityPubPayload;
use Ethernick\ActivityPubCore\Middleware\NegotiateActivityPubResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SidecarMigrationTest extends TestCase
{
    use ProvidesSandbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupSandbox();

        // Ensure Pro is on for collection features
        config(['statamic.editions.pro' => true]);

        // Setup collections we need
        $this->setupCollections(['actors', 'notes', 'activities']);

        // Write settings
        File::put(
            \Ethernick\ActivityPubCore\Services\ActivityPubUtils::settingsPath(),
            YAML::dump([
                'actors' => ['enabled' => true, 'federated' => true, 'type' => 'Person'],
                'notes' => ['enabled' => true, 'federated' => true, 'type' => 'Note'],
                'activities' => ['enabled' => true, 'federated' => true, 'type' => 'Activity'],
            ])
        );

        \Statamic\Facades\Blink::forget('activitypub-settings');
        \Statamic\Facades\Stache::clear();
    }

    public function test_saving_entry_creates_sidecar_and_clears_inline_data()
    {
        // 1. Create Actor
        $actor = Entry::make()
            ->collection('actors')
            ->slug('test-actor')
            ->data(['title' => 'Test Actor', 'is_internal' => true]);
        $actor->save();

        // 2. Create Note with supplement (simulating standard AP write pipeline)
        $note = Entry::make()
            ->collection('notes')
            ->slug('test-note')
            ->data([
                'content' => 'Hello federated world!',
                'actor' => $actor->id(),
                'is_internal' => true,
            ]);

        $jsonPayload = json_encode([
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'type' => 'Note',
            'content' => 'Hello federated world!',
        ], JSON_UNESCAPED_SLASHES);

        $note->setSupplement('activitypub_json', $jsonPayload);
        $note->save();

        // 3. Verify sidecar file exists on disk alongside the entry
        $notePath = $note->path();
        $this->assertNotEmpty($notePath);

        $sidecarPath = preg_replace('/\.(md|yaml)$/', '.json', $notePath);
        $this->assertFileExists($sidecarPath);
        $sidecarJson = json_decode(File::get($sidecarPath), true);
        $this->assertEquals('Note', $sidecarJson['type']);
        $this->assertStringContainsString('Hello federated world!', $sidecarJson['content']);

        // 4. Verify front-matter inside .md does NOT contain activitypub_json
        $yaml = YAML::parse(File::get($notePath));
        $this->assertArrayNotHasKey('activitypub_json', $yaml);

        // 5. Verify reading via ActivityPubPayload domain object
        $payloadObj = ActivityPubPayload::for($note);
        $payloadArr = $payloadObj->toArray();
        $this->assertEquals('Note', $payloadArr['type']);
        $this->assertStringContainsString('Hello federated world!', $payloadArr['content']);
    }

    public function test_deleting_entry_deletes_sidecar()
    {
        // 1. Create Actor & Note
        $actor = Entry::make()
            ->collection('actors')
            ->slug('test-actor-del')
            ->data(['title' => 'Test Actor', 'is_internal' => true]);
        $actor->save();

        $note = Entry::make()
            ->collection('notes')
            ->slug('test-note-del')
            ->data([
                'content' => 'To be deleted',
                'actor' => $actor->id(),
                'is_internal' => true,
            ]);
        $note->setSupplement('activitypub_json', '{"type":"Note"}');
        $note->save();

        $sidecarPath = preg_replace('/\.(md|yaml)$/', '.json', $note->path());
        $this->assertFileExists($sidecarPath);

        // 2. Delete the Note
        $note->delete();

        // 3. Verify sidecar file is cleaned up
        $this->assertFileDoesNotExist($sidecarPath);
    }

    public function test_manual_override_preserves_and_updates_sidecar()
    {
        // 1. Create Actor
        $actor = Entry::make()
            ->collection('actors')
            ->slug('test-actor-manual')
            ->data(['title' => 'Test Actor', 'is_internal' => true]);
        $actor->save();

        // 2. Create entry with manual override (e.g. from Statamic CP form data)
        // Statamic CP form data sets $entry->set('activitypub_json', $formValue)
        // and $entry->set('activitypub_json_manual', true).
        $manualJson = json_encode([
            'type' => 'Note',
            'content' => 'Manual Override Content',
        ], JSON_PRETTY_PRINT);

        $note = Entry::make()
            ->collection('notes')
            ->slug('test-note-manual')
            ->data([
                'content' => 'Initial Content',
                'actor' => $actor->id(),
                'is_internal' => true,
                'activitypub_json_manual' => true,
                'activitypub_json' => $manualJson,
            ]);

        $note->save();

        // 3. Verify the saved sidecar contains the override content
        $sidecarPath = preg_replace('/\.(md|yaml)$/', '.json', $note->path());
        $this->assertFileExists($sidecarPath);
        $this->assertStringContainsString('Manual Override Content', File::get($sidecarPath));

        // 4. Verify front-matter inside .md does NOT contain activitypub_json
        $yaml = YAML::parse(File::get($note->path()));
        $this->assertArrayNotHasKey('activitypub_json', $yaml);

        // 5. Test saving WITHOUT changing JSON, ensuring existing sidecar is kept
        $note = Entry::find($note->id());
        $this->assertTrue($note->get('activitypub_json_manual'));

        // Simulating CP saving again: no activitypub_json is passed because user didn't change it.
        // But activitypub_json_manual remains true.
        $note->set('content', 'Updated content only');
        $note->save();

        $this->assertFileExists($sidecarPath);
        $this->assertStringContainsString('Manual Override Content', File::get($sidecarPath));
    }

    public function test_middleware_serves_from_sidecar()
    {
        // 1. Create Actor & Note with sidecar JSON
        $actor = Entry::make()
            ->collection('actors')
            ->slug('test-actor-mid')
            ->data(['title' => 'Test Actor', 'is_internal' => true]);
        $actor->save();

        $note = Entry::make()
            ->collection('notes')
            ->slug('test-note-mid')
            ->data([
                'content' => 'Middleware Note',
                'actor' => $actor->id(),
                'is_internal' => true,
                'activitypub_json_manual' => true,
            ]);
        $note->setSupplement('activitypub_json', '{"type":"Note","id":"http://localhost/notes/test-note-mid"}');
        $note->save();

        // 2. Execute Middleware Negotiation
        $request = Request::create('/notes/test-note-mid', 'GET');
        $request->headers->set('Accept', 'application/activity+json');

        $middleware = new NegotiateActivityPubResponse();
        $response = $middleware->handle($request, function ($req) use ($note) {
            // Return dummy response wrapping the Entry
            return new Response($note);
        });

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('application/activity+json', $response->headers->get('Content-Type'));
        
        $json = json_decode($response->getContent(), true);
        $this->assertEquals('Note', $json['type']);
        $this->assertEquals('http://localhost/notes/test-note-mid', $json['id']);
    }

    public function test_migration_command()
    {
        // 1. Create Actor
        $actor = Entry::make()
            ->collection('actors')
            ->slug('test-actor-mig')
            ->data(['title' => 'Test Actor', 'is_internal' => true]);
        $actor->save();

        // 2. Bypass normal listener by using saveQuietly so we write activitypub_json inline
        // (Simulating historical entries before the sidecar migration)
        $note = Entry::make()
            ->collection('notes')
            ->slug('test-note-mig')
            ->data([
                'content' => 'Migrated Note Content',
                'actor' => $actor->id(),
                'is_internal' => true,
                'activitypub_json' => '{"type":"Note","migrated":true}',
            ]);
        $note->saveQuietly();

        $notePath = $note->path();
        $sidecarPath = preg_replace('/\.(md|yaml)$/', '.json', $notePath);

        // Assert sidecar does NOT exist yet
        $this->assertFileDoesNotExist($sidecarPath);

        // Verify markdown file contains inline JSON front-matter
        $mdContents = File::get($notePath);
        $this->assertStringContainsString('activitypub_json', $mdContents);

        // 3. Test DRY RUN first
        $this->artisan('activitypub:migrate-json', [
            '--dry-run' => true,
        ])->assertExitCode(0);

        // Assert sidecar still does NOT exist
        $this->assertFileDoesNotExist($sidecarPath);

        // 4. Run ACTUAL migration
        $this->artisan('activitypub:migrate-json')
            ->expectsOutputToContain('Migration complete.')
            ->assertExitCode(0);

        // 5. Assert sidecar exists and has the correct content
        $this->assertFileExists($sidecarPath);
        $this->assertEquals('{"type":"Note","migrated":true}', File::get($sidecarPath));

        // 6. Assert markdown front-matter has been cleaned
        $yamlCleaned = YAML::parse(File::get($notePath));
        $this->assertArrayNotHasKey('activitypub_json', $yamlCleaned);
    }

    public function test_migration_command_with_dated_entry_and_path_mismatch()
    {
        // 1. Create Actor
        $actor = Entry::make()
            ->collection('actors')
            ->slug('test-actor-mig-date')
            ->data(['title' => 'Test Actor', 'is_internal' => true]);
        $actor->save();

        // 2. Create note entry with a date to trigger dated filename conventions
        // First save with date to establish old path, bypassing listener
        $note = Entry::make()
            ->collection('notes')
            ->slug('test-note-mig-date')
            ->date('2026-03-17-1845') // HHMM format
            ->data([
                'content' => 'Migrated Note Content with Date',
                'actor' => $actor->id(),
                'is_internal' => true,
                'activitypub_json' => '{"type":"Note","migrated":true}',
            ]);
        $note->saveQuietly();

        $originalPath = $note->path();
        
        // 3. Run the migration command
        $this->artisan('activitypub:migrate-json')
            ->expectsOutputToContain('Migration complete.')
            ->assertExitCode(0);

        // Reload the entry to see final path
        $note = Entry::find($note->id());
        $finalPath = $note->path();
        $finalSidecarPath = preg_replace('/\.(md|yaml)$/', '.json', $finalPath);

        // 4. Assert sidecar exists at the final path
        $this->assertFileExists($finalSidecarPath);
        $this->assertEquals('{"type":"Note","migrated":true}', File::get($finalSidecarPath));

        // 5. Assert markdown front-matter has been cleaned
        $yamlCleaned = YAML::parse(File::get($finalPath));
        $this->assertArrayNotHasKey('activitypub_json', $yamlCleaned);
    }
}

