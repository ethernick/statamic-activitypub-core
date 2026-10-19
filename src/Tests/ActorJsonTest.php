<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Tests;

use Statamic\Facades\Entry;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection;
use Tests\TestCase;
use Ethernick\ActivityPubCore\Tests\Concerns\ProvidesSandbox;

class ActorJsonTest extends TestCase
{
    use ProvidesSandbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupSandbox();
        $this->setupCollections(['actors', 'activities']);

        // Clean up only test-scoped actors
        Entry::query()
            ->where('collection', 'actors')
            ->get()
            ->filter(fn ($entry) => str_starts_with((string) $entry->slug(), 'test-actor-'))
            ->each->delete();
    }

    protected function tearDown(): void
    {
        Entry::query()
            ->where('collection', 'actors')
            ->get()
            ->filter(fn ($entry) => str_starts_with((string) $entry->slug(), 'test-actor-'))
            ->each->delete();

        parent::tearDown();
    }

    public function test_actor_json_summary_is_parsed_markdown()
    {
        $actor = Entry::make()
            ->collection('actors')
            ->slug('test-actor-nick')
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
            ->slug('test-actor-with-avatar')
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

    public function test_actor_json_icon_generates_and_resolves_without_container_prefix()
    {
        // 1. Setup Disk and Asset Container
        config(['filesystems.disks.test_assets' => [
            'driver' => 'local',
            'root' => storage_path('test_assets'),
            'url' => 'http://localhost/test_assets',
        ]]);

        $container = AssetContainer::make('assets')
            ->disk('test_assets')
            ->save();
        
        // Ensure directory exists with a valid 1x1 pixel JPEG image
        $im = imagecreatetruecolor(1, 1);
        ob_start();
        imagejpeg($im);
        $validImageContent = ob_get_clean();
        imagedestroy($im);
        \Illuminate\Support\Facades\Storage::disk('test_assets')->put('nick2.jpg', $validImageContent);

        $asset = Asset::make()
            ->container($container)
            ->path('nick2.jpg');
        $asset->save();

        // 2. Setup Actor with avatar ID as simple path (no container prefix)
        $actor = Entry::make()
            ->collection('actors')
            ->slug('test-actor-no-prefix')
            ->data([
                'title' => 'Nick',
                'avatar' => 'nick2.jpg', // No container prefix
                'is_internal' => true
            ]);
        $actor->save();

        // Remove the static public file if it exists, to test on-demand generation
        $staticPath = public_path('activitypub/avatars/test-actor-no-prefix.jpg');
        if (file_exists($staticPath)) {
            unlink($staticPath);
        }

        // 3. Transform
        $transformer = app(\Ethernick\ActivityPubCore\Transformers\ActivityPubObjectTransformer::class);
        $json = $transformer->transform($actor);

        // 4. Assert
        $this->assertNotNull($json['icon'], 'Icon should not be null');
        $this->assertEquals('Image', $json['icon']['type']);
        $this->assertEquals(url('activitypub/avatars/test-actor-no-prefix.jpg'), $json['icon']['url']);
        
        // Assert the file was generated on the fly
        $this->assertTrue(file_exists($staticPath), 'Static public avatar image should be generated on the fly');

        // Cleanup
        if (file_exists($staticPath)) {
            unlink($staticPath);
        }
    }

    public function test_actor_json_supports_alternative_actor_types()
    {
        $transformer = app(\Ethernick\ActivityPubCore\Transformers\ActivityPubObjectTransformer::class);

        // 1. Default to Person
        $personActor = Entry::make()
            ->collection('actors')
            ->slug('test-actor-default-person')
            ->data(['title' => 'Jane Doe', 'is_internal' => true]);
        $personActor->save();
        $this->assertEquals('Person', $transformer->transform($personActor)['type']);

        // 2. Organization
        $orgActor = Entry::make()
            ->collection('actors')
            ->slug('test-actor-acme-corp')
            ->data(['title' => 'Acme Corp', 'actor_type' => 'Organization', 'is_internal' => true]);
        $orgActor->save();
        $this->assertEquals('Organization', $transformer->transform($orgActor)['type']);

        // 3. Service (Bot)
        $serviceActor = Entry::make()
            ->collection('actors')
            ->slug('test-actor-feed-bot')
            ->data(['title' => 'Feed Bot', 'actor_type' => 'Service', 'is_internal' => true]);
        $serviceActor->save();
        $this->assertEquals('Service', $transformer->transform($serviceActor)['type']);

        // 4. CP transformation exposes actor_type
        $cpData = \Ethernick\ActivityPubCore\Services\ActivityPubUtils::transformActorForCp($serviceActor);
        $this->assertEquals('Service', $cpData['actor_type']);
    }
}
