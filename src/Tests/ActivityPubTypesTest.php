<?php

namespace Ethernick\ActivityPubCore\Tests;

use Tests\TestCase;
use Ethernick\ActivityPubCore\Services\ActivityPubTypes;

class ActivityPubTypesTest extends TestCase
{
    public function test_can_retrieve_default_types()
    {
        $typesService = app(ActivityPubTypes::class);
        $config = $typesService->getConfig();
        $options = $typesService->getOptions();

        // Check full config
        $this->assertIsArray($config);
        $this->assertArrayHasKey('Note', $config);
        $this->assertArrayHasKey('Person', $config);
        $this->assertEquals('Note', $config['Note']['label']);

        // Check legacy options
        $this->assertEquals('Note', $options['Note']);
    }

    public function test_can_add_custom_type()
    {
        ActivityPubTypes::register('CustomType', 'My Custom Type');

        $typesService = app(ActivityPubTypes::class);
        $config = $typesService->getConfig();
        $options = $typesService->getOptions();

        $this->assertArrayHasKey('CustomType', $config);
        $this->assertEquals('My Custom Type', $config['CustomType']['label']);

        $this->assertArrayHasKey('CustomType', $options);
        $this->assertEquals('My Custom Type', $options['CustomType']);
    }

    public function test_service_is_singleton()
    {
        $service1 = app(ActivityPubTypes::class);
        // Using static register, so instance doesn't matter for registration, 
        // but verifying singleton nature of the service resolution itself is still valid 
        // if we want to check if the class instance returned is same.
        // However, the original test was testing if adding to one instance adds to the static state shared by another.
        // Since state is static, it persists.

        ActivityPubTypes::register('SingletonTest', 'Singleton Test');

        $service2 = app(ActivityPubTypes::class);
        $options = $service2->getOptions();

        $this->assertArrayHasKey('SingletonTest', $options);
        $this->assertSame($service1, $service2);
    }
    public function test_can_modify_existing_type()
    {
        $typesService = app(ActivityPubTypes::class);

        // Register a base type
        $typesService->register('BaseType', 'Base Type', 'OldController');

        // Modify it
        $typesService->modify('BaseType', ['controller' => 'NewController']);

        $config = $typesService->getConfig();
        $this->assertEquals('NewController', $config['BaseType']['controller']);
        $this->assertEquals('Base Type', $config['BaseType']['label']);
    }
}
