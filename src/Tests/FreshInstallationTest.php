<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Tests;

use Tests\TestCase;
use Ethernick\ActivityPubCore\Tests\Concerns\ProvidesSandbox;
use Ethernick\ActivityPubCore\Services\ActivityPubTypes;
use Ethernick\ActivityPubCore\Storage\CustomCollectionEntriesStore;
use Statamic\Stache\Stores\CollectionEntriesStore;
use PHPUnit\Framework\Attributes\Test;
use ActivityPhp\Type;
use Illuminate\Support\Facades\File;

class FreshInstallationTest extends TestCase
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
    public function it_registers_service_provider_bindings_and_singletons(): void
    {
        $this->assertTrue(app()->bound(ActivityPubTypes::class));
        $this->assertInstanceOf(ActivityPubTypes::class, app(ActivityPubTypes::class));

        $store = app(CollectionEntriesStore::class);
        $this->assertInstanceOf(CustomCollectionEntriesStore::class, $store);
    }

    #[Test]
    public function it_registers_custom_activity_php_types(): void
    {
        $person = Type::create('Person');
        $this->assertInstanceOf(\Ethernick\ActivityPubCore\Types\Person::class, $person);

        $app = Type::create('Application');
        $this->assertInstanceOf(\Ethernick\ActivityPubCore\Types\Application::class, $app);

        $propertyValue = Type::create('PropertyValue');
        $this->assertInstanceOf(\Ethernick\ActivityPubCore\Types\PropertyValue::class, $propertyValue);
    }

    #[Test]
    public function it_resolves_default_configuration_options(): void
    {
        $this->assertEquals('activitypub-outbox', config('activitypub.queue.outbox.name'));
        $this->assertEquals(3, config('activitypub.queue.outbox.tries'));
        $this->assertEquals(15, config('activitypub.http.timeout'));
        $this->assertTrue(config('activitypub.rate_limits.enabled'));
    }

    #[Test]
    public function it_publishes_configuration_file(): void
    {
        $targetConfig = config_path('activitypub.php');
        if (File::exists($targetConfig)) {
            File::delete($targetConfig);
        }

        $this->artisan('vendor:publish', [
            '--tag' => 'activitypub-config',
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertFileExists($targetConfig);

        if (File::exists($targetConfig)) {
            File::delete($targetConfig);
        }
    }

    #[Test]
    public function it_boots_and_initializes_without_existing_content_collections(): void
    {
        $collectionsPath = $this->sandboxPath . '/content/collections';
        if (File::exists($collectionsPath)) {
            File::deleteDirectory($collectionsPath);
        }
        File::makeDirectory($collectionsPath, 0755, true);

        $typesService = app(ActivityPubTypes::class);
        $this->assertIsArray($typesService->all());

        $this->assertTrue(true);
    }

    #[Test]
    public function it_executes_install_command_in_isolation(): void
    {
        $this->artisan('activitypub:install')
            ->expectsChoice('No collection found for Person. What would you like to do?', 'create', [
                'create' => "Create a new 'Actors' collection",
            ])
            ->expectsQuestion('Enter the name for the Person collection', 'Actors')
            ->expectsChoice('No collection found for Note. What would you like to do?', 'create', [
                'create' => "Create a new 'Notes' collection",
                'select' => 'Select an existing collection',
            ])
            ->expectsQuestion('Enter the name for the Note collection', 'Notes')
            ->expectsChoice('No collection found for Activity. What would you like to do?', 'create', [
                'create' => "Create a new 'Activities' collection",
                'select' => 'Select an existing collection',
            ])
            ->expectsQuestion('Enter the name for the Activity collection', 'Activities')
            ->expectsConfirmation('Would you like to create your first ActivityPub profile now?', 'no')
            ->assertExitCode(0);

        // Verify configuration file is created
        $this->assertFileExists(config_path('activitypub.php'));

        // Verify settings file is created
        $settingsPath = \Ethernick\ActivityPubCore\Services\ActivityPubUtils::settingsPath();
        $this->assertFileExists($settingsPath);
    }
}
