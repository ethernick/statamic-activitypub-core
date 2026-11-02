<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Tests;

use Tests\TestCase;
use Statamic\Facades\Entry;
use Statamic\Facades\Collection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Ethernick\ActivityPubCore\Services\ActorResolver;
use Ethernick\ActivityPubCore\Jobs\DownloadActorAvatar;

class AsyncActorAvatarTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!Collection::find('actors')) {
            Collection::make('actors')->save();
        }

        Storage::fake('assets');
    }

    public function test_actor_resolution_saves_remote_url_and_dispatches_avatar_job(): void
    {
        Queue::fake();

        $actorUrl = 'https://remote.social/users/charlie';
        $avatarUrl = 'https://remote.social/avatars/charlie.png';

        Http::fake([
            $actorUrl => Http::response([
                'id' => $actorUrl,
                'type' => 'Person',
                'preferredUsername' => 'charlie',
                'name' => 'Charlie Social',
                'inbox' => 'https://remote.social/users/charlie/inbox',
                'icon' => [
                    'type' => 'Image',
                    'url' => $avatarUrl,
                ],
            ], 200),
        ]);

        $resolver = new ActorResolver();
        $entry = $resolver->resolve($actorUrl, true);

        $this->assertNotNull($entry);
        $this->assertEquals($avatarUrl, $entry->get('avatar'));

        // Verify job was dispatched to queue
        Queue::assertPushed(DownloadActorAvatar::class, function ($job) use ($entry) {
            return true;
        });
    }

    public function test_download_actor_avatar_job_downloads_image_and_updates_entry(): void
    {
        $actor = Entry::make()
            ->collection('actors')
            ->slug('charlie-test')
            ->data([
                'title' => 'Charlie Test',
                'avatar' => 'https://remote.social/avatars/charlie.png',
            ]);
        $actor->save();

        $avatarContent = str_repeat('GIF89a', 20); // Dummy valid image payload > 50 bytes

        Http::fake([
            'https://remote.social/avatars/charlie.png' => Http::response($avatarContent, 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        $job = new DownloadActorAvatar($actor->id(), 'https://remote.social/avatars/charlie.png');
        $job->handle();

        // Refresh actor
        $refreshed = Entry::find($actor->id());
        $this->assertNotNull($refreshed);

        $savedAvatar = $refreshed->get('avatar');
        $this->assertStringStartsWith('avatars/', (string) $savedAvatar);
        $this->assertStringEndsWith('.png', (string) $savedAvatar);

        Storage::disk('assets')->assertExists($savedAvatar);
    }
}
