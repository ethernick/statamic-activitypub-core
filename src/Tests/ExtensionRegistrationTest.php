<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Tests;

use Tests\TestCase;
use Ethernick\ActivityPubCore\Tests\Concerns\ProvidesSandbox;
use Ethernick\ActivityPubCore\Services\ActivityPubTypes;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use PHPUnit\Framework\Attributes\Test;

class ExtensionRegistrationTest extends TestCase
{
    use ProvidesSandbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupSandbox();

        if (!Collection::find('notes')) {
            Collection::make('notes')->save();
        }
    }

    protected function tearDown(): void
    {
        $this->teardownSandbox();
        parent::tearDown();
    }

    #[Test]
    public function it_can_register_custom_activitypub_types(): void
    {
        ActivityPubTypes::register(
            'Event',
            'Event Type',
            'events',
            'CustomEventController',
            ['events'],
            'CustomStoreHandler',
            'CustomOutboxHandler',
            'CustomInboxHandler'
        );

        $types = app(ActivityPubTypes::class)->all();

        $this->assertArrayHasKey('Event', $types);
        $this->assertEquals('Event Type', $types['Event']['label']);
        $this->assertEquals('events', $types['Event']['slug']);
        $this->assertEquals('CustomEventController', ActivityPubTypes::getController('Event'));
        $this->assertEquals('CustomStoreHandler', ActivityPubTypes::getStoreHandler('Event'));
        $this->assertEquals('CustomOutboxHandler', ActivityPubTypes::getOutboxHandler('Event'));
        $this->assertEquals('CustomInboxHandler', ActivityPubTypes::getInboxHandler('Event'));
        $this->assertContains('events', ActivityPubTypes::getCollections('Event'));
        $this->assertContains('events', ActivityPubTypes::getInboxCollections());
    }

    #[Test]
    public function it_handles_duplicate_registration_and_modifications(): void
    {
        ActivityPubTypes::register('DuplicateType', 'Original Label');
        $this->assertEquals('Original Label', app(ActivityPubTypes::class)->all()['DuplicateType']['label']);

        ActivityPubTypes::register(
            'DuplicateType',
            'Overridden Label',
            'duplicates',
            'NewController'
        );

        $this->assertEquals('Overridden Label', app(ActivityPubTypes::class)->all()['DuplicateType']['label']);
        $this->assertEquals('NewController', ActivityPubTypes::getController('DuplicateType'));

        ActivityPubTypes::modify('DuplicateType', ['label' => 'Modified Label']);
        $this->assertEquals('Modified Label', app(ActivityPubTypes::class)->all()['DuplicateType']['label']);
    }

    #[Test]
    public function it_executes_outbox_hooks_in_priority_order(): void
    {
        $executionOrder = [];
        $entry = Entry::make()->collection('notes')->slug('test-outbox-entry');

        ActivityPubTypes::registerOutboxHook(function ($entry, &$data) use (&$executionOrder) {
            $executionOrder[] = 'low_priority';
        }, 10);

        ActivityPubTypes::registerOutboxHook(function ($entry, &$data) use (&$executionOrder) {
            $executionOrder[] = 'high_priority';
        }, 100);

        ActivityPubTypes::registerOutboxHook(function ($entry, &$data) use (&$executionOrder) {
            $executionOrder[] = 'medium_priority';
        }, 50);

        $data = [];
        ActivityPubTypes::executeOutboxHooks($entry, $data);

        $this->assertEquals(['high_priority', 'medium_priority', 'low_priority'], $executionOrder);
    }

    #[Test]
    public function it_executes_transform_hooks(): void
    {
        $entry = Entry::make()->collection('notes')->slug('test-transform-entry');

        ActivityPubTypes::registerTransformHook(function ($entry, &$data) {
            $data['transformed'] = true;
            $data['hook_applied'] = 'yes';
        });

        $data = [];
        ActivityPubTypes::executeTransformHooks($entry, $data);

        $this->assertTrue($data['transformed']);
        $this->assertEquals('yes', $data['hook_applied']);
    }

    #[Test]
    public function it_executes_store_hooks(): void
    {
        $executed = false;
        ActivityPubTypes::registerStoreHook(function ($entry) use (&$executed) {
            $executed = true;
        });

        $entry = Entry::make()->collection('notes')->slug('test-hook-entry');
        ActivityPubTypes::executeStoreHooks($entry);

        $this->assertTrue($executed);
    }

    #[Test]
    public function it_returns_null_inbox_handler_for_unknown_types(): void
    {
        $handler = ActivityPubTypes::getInboxHandler('NonExistentType');
        $this->assertNull($handler);
    }
}
