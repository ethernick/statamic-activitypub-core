<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Tests;

use Tests\TestCase;
use Statamic\Facades\User;
use Ethernick\ActivityPubCore\Tests\Concerns\ProvidesSandbox;
use PHPUnit\Framework\Attributes\Test;

class ActivityPubSettingsControllerTest extends TestCase
{
    use ProvidesSandbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupSandbox();
    }

    protected function tearDown(): void
    {
        $this->teardownSandbox();
        parent::tearDown();
    }

    #[Test]
    public function it_can_view_the_settings_page(): void
    {
        $user = User::make()->email('admin@example.com')->makeSuper()->save();

        $this->actingAs($user)
            ->get(cp_route('activitypub.settings.index'))
            ->assertOk()
            ->assertSee('activity-pub-settings');
    }

    #[Test]
    public function it_can_save_settings(): void
    {
        $user = User::make()->email('admin@example.com')->makeSuper()->save();

        $this->actingAs($user)
            ->post(cp_route('activitypub.settings.update'), [
                'collections' => [],
                'types' => [],
                'federated' => [],
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Settings saved.');
    }

    #[Test]
    public function it_can_manage_blocklist_entries(): void
    {
        $user = User::make()->email('admin@example.com')->makeSuper()->save();

        // 1. Add domain block
        $response = $this->actingAs($user)
            ->postJson(cp_route('activitypub.blocklist.domain'), [
                'domain' => 'https://spam-instance.example.com/'
            ]);
        $response->assertOk()
            ->assertJsonFragment(['message' => 'Domain spam-instance.example.com blocked successfully.']);

        $this->assertTrue(\Ethernick\ActivityPubCore\Services\BlockList::isBlocked('spam-instance.example.com'));

        // 2. Fetch blocklist entries
        $listResponse = $this->actingAs($user)
            ->getJson(cp_route('activitypub.blocklist.index'));
        $listResponse->assertOk()
            ->assertJsonFragment(['identifier' => 'spam-instance.example.com', 'type' => 'domain']);

        $entry = \Ethernick\ActivityPubCore\Models\BlockListEntry::where('identifier', 'spam-instance.example.com')->first();
        $this->assertNotNull($entry);

        // 3. Delete block
        $deleteResponse = $this->actingAs($user)
            ->deleteJson(cp_route('activitypub.blocklist.delete', ['id' => $entry->id]));
        $deleteResponse->assertOk()
            ->assertJsonFragment(['message' => 'Entry removed from blocklist.']);

        $this->assertFalse(\Ethernick\ActivityPubCore\Services\BlockList::isBlocked('spam-instance.example.com'));
    }

    #[Test]
    public function it_can_run_maintenance_via_api(): void
    {
        $user = User::make()->email('admin@example.com')->makeSuper()->save();

        $response = $this->actingAs($user)
            ->postJson(cp_route('activitypub.maintenance.run'));

        $response->assertOk()
            ->assertJsonFragment(['message' => 'Maintenance executed successfully.']);
    }
}
