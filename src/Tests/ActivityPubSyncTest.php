<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Tests;

use Statamic\Facades\Entry;
use Statamic\Facades\File;
use Statamic\Facades\User;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;
use Ethernick\ActivityPubCore\Storage\ActivityPubPayload;

class ActivityPubSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!\Statamic\Facades\Collection::find('notes')) {
            \Statamic\Facades\Collection::make('notes')->save();
        }
    }

    public function test_sync_command_ingests_markdown_entry_lacking_sidecar()
    {
        $user = User::make()->id('sync-test-user')->email('sync-test@example.com')->makeSuper();
        $user->save();
        $this->actingAs($user);

        // Simulate a raw file upload saved quietly (no sidecar generated)
        $entry = Entry::make()
            ->collection('notes')
            ->slug('raw-uploaded-note')
            ->data([
                'title' => 'Direct Upload Note',
                'content' => 'This note was uploaded via SFTP without using CP',
                'is_internal' => true,
                'date' => now()->format('Y-m-d H:i'),
            ])
            ->published(true);
        $entry->saveQuietly();

        $payload = ActivityPubPayload::for($entry);
        $this->assertFalse($payload->exists());
        $this->assertNull($entry->fresh()->get('search_tokens'));

        // Run the sync command
        Artisan::call('activitypub:sync', ['--collection' => ['notes']]);

        // Verify sidecar was created and search_tokens were generated
        $this->assertTrue($payload->exists());
        $fresh = $entry->fresh();
        $tokens = $fresh->get('search_tokens');
        $this->assertIsArray($tokens);
        $this->assertContains('uploaded', $tokens);
        $this->assertContains('sftp', $tokens);
    }

    public function test_sync_command_detects_modified_markdown_file()
    {
        $user = User::make()->id('sync-test-mod-user')->email('sync-mod@example.com')->makeSuper();
        $user->save();
        $this->actingAs($user);

        $entry = Entry::make()
            ->collection('notes')
            ->slug('note-to-be-modified')
            ->data([
                'content' => 'Original content before edit',
                'is_internal' => true,
                'date' => now()->format('Y-m-d H:i'),
            ])
            ->published(true);
        $entry->save();

        $payload = ActivityPubPayload::for($entry);
        $this->assertTrue($payload->exists());

        // Artificially age the sidecar so the entry path has a newer mtime
        $sidecarPath = $payload->sidecarFilePath();
        $entryPath = $entry->path();
        $now = time();
        touch($entryPath, $now);
        touch($sidecarPath, $now - 60);

        // Simulate manual update in entry file
        $entry->set('content', 'Modified content with new keyword fediverse');
        $entry->saveQuietly();
        touch($entryPath, $now + 10);

        // Run sync
        Artisan::call('activitypub:sync', ['--collection' => ['notes']]);

        // Verify updated sidecar and refreshed search tokens
        $fresh = $entry->fresh();
        $tokens = $fresh->get('search_tokens');
        $this->assertIsArray($tokens);
        $this->assertContains('fediverse', $tokens);
    }
}
