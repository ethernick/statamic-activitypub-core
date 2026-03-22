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
}
