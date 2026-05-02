<?php

namespace Ethernick\ActivityPubCore\Tests;

use Ethernick\ActivityPubCore\Services\BlockList;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Statamic\Facades\File;
use Statamic\Facades\YAML;
use Ethernick\ActivityPubCore\Services\ActivityPubUtils;

class BlockListTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        
        // Run migrations for the new tables
        $this->artisan('migrate');

        // Clear BlockListEntry table
        \Ethernick\ActivityPubCore\Models\BlockListEntry::query()->delete();
        
        // Reset blocklist in settings (legacy, should be empty)
        $path = ActivityPubUtils::settingsPath();
        if (File::exists($path)) {
            $settings = YAML::parse(File::get($path));
            unset($settings['blocklist']);
            File::put($path, YAML::dump($settings));
        }
        
        // Clear static cache
        $reflection = new \ReflectionClass(BlockList::class);
        $property = $reflection->getProperty('blocklist');
        $property->setAccessible(true);
        $property->setValue(null, null);

        // Clear AutoBlock table
        \Ethernick\ActivityPubCore\Models\AutoBlock::query()->delete();
    }


    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_check_if_a_domain_is_blocked()
    {
        BlockList::add('blocked.com');
        
        $this->assertTrue(BlockList::isBlocked('blocked.com'));
        $this->assertTrue(BlockList::isBlocked('sub.blocked.com'));
        $this->assertFalse(BlockList::isBlocked('allowed.com'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_check_if_a_url_is_blocked()
    {
        $url = 'https://example.com/users/bad_user';
        BlockList::add($url);
        
        $this->assertTrue(BlockList::isBlocked($url));
        $this->assertFalse(BlockList::isBlocked('example.com')); // Explicit URL block != Domain block
        
        // Blocking the domain should block the URL
        BlockList::add('example.com');
        $this->assertTrue(BlockList::isBlocked('https://example.com/users/good_user'));
    }


    #[\PHPUnit\Framework\Attributes\Test]
    public function it_resolves_handles_and_aliases_when_adding()
    {
        $handle = '@bob@example.com';
        $actorUrl = 'https://example.com/users/bob';
        $aliasUrl = 'https://old.example.com/bob';

        Http::fake([
            'https://example.com/.well-known/webfinger?resource=acct:bob@example.com' => Http::response([
                'subject' => 'acct:bob@example.com',
                'aliases' => [$aliasUrl],
                'links' => [
                    [
                        'rel' => 'self',
                        'type' => 'application/activity+json',
                        'href' => $actorUrl
                    ]
                ]
            ])
        ]);

        BlockList::add($handle, 'Test Block');

        $this->assertTrue(BlockList::isBlocked($handle));
        $this->assertTrue(BlockList::isBlocked($actorUrl));
        $this->assertTrue(BlockList::isBlocked($aliasUrl));
        
        $this->assertDatabaseHas('activity_pub_auto_blocks', [
            'identifier' => $handle,
            'reason' => 'Test Block'
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_prune_old_logs()
    {
        // Create an old log
        $oldLog = \Ethernick\ActivityPubCore\Models\AutoBlock::create([
            'identifier' => 'old@example.com',
            'urls' => [],
            'reason' => 'Old'
        ]);
        $oldLog->created_at = now()->subDays(10);
        $oldLog->save();

        // Create a new log
        \Ethernick\ActivityPubCore\Models\AutoBlock::create([
            'identifier' => 'new@example.com',
            'urls' => [],
            'reason' => 'New'
        ]);

        // Prune logs older than 7 days
        BlockList::prune(7);

        $this->assertDatabaseMissing('activity_pub_auto_blocks', ['identifier' => 'old@example.com']);
        $this->assertDatabaseHas('activity_pub_auto_blocks', ['identifier' => 'new@example.com']);
    }
}
