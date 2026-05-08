<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Tests;

use Statamic\Facades\Entry;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection;
use Tests\TestCase;

class ActorJsonTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Ensure actors collection exists and is clean
        if (!Collection::find('actors')) {
            Collection::make('actors')->save();
        }
        Entry::query()->where('collection', 'actors')->get()->each->delete();
    }

    public function test_actor_json_summary_is_parsed_markdown()
    {
        $actor = Entry::make()
            ->collection('actors')
            ->slug('nick')
            ->data([
                'title' => 'Nick',
                'content' => 'Building with [Statamic](https://statamic.com)',
                'is_internal' => true
            ]);
        $actor->save();

        $transformer = app(\Ethernick\ActivityPubCore\Transformers\ActivityPubObjectTransformer::class);
        $json = $transformer->transform($actor);

        $this->assertStringContainsString('<p>Building with <a href="https://statamic.com">Statamic</a></p>', $json['summary']);
    }

    public function test_actor_json_icon_resolves_asset_id()
    {
        // 1. Setup Disk and Asset Container
        config(['filesystems.disks.test_assets' => [
            'driver' => 'local',
            'root' => storage_path('test_assets'),
            'url' => 'http://localhost/test_assets',
        ]]);

        $container = AssetContainer::make('avatars')
            ->disk('test_assets')
            ->save();
        
        // Ensure directory exists
        \Illuminate\Support\Facades\Storage::disk('test_assets')->put('nick.jpg', 'fake-image-content');

        $asset = Asset::make()
            ->container($container)
            ->path('nick.jpg');
        $asset->save();

        // 2. Setup Actor
        $actor = Entry::make()
            ->collection('actors')
            ->slug('nick-with-avatar')
            ->data([
                'title' => 'Nick',
                'avatar' => $asset->id(), // e.g. 'avatars::nick.jpg'
                'is_internal' => true
            ]);
        $actor->save();

        // 3. Transform
        $transformer = app(\Ethernick\ActivityPubCore\Transformers\ActivityPubObjectTransformer::class);
        $json = $transformer->transform($actor);

        // 4. Assert
        $this->assertNotNull($json['icon'], 'Icon should not be null');
        $this->assertEquals('Image', $json['icon']['type']);
        $this->assertEquals('http://localhost/test_assets/nick.jpg', $json['icon']['url']);
    }
}
