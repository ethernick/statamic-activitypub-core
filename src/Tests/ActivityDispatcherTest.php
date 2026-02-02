<?php

namespace Ethernick\ActivityPubCore\Tests;

use Ethernick\ActivityPubCore\Services\ActivityDispatcher;
use Ethernick\ActivityPubCore\Http\Controllers\NoteController;
use Ethernick\ActivityPubCore\Http\Controllers\ArticleController;
use Ethernick\ActivityPubQuestions\Http\Controllers\QuestionController;
use Tests\TestCase;
use Statamic\Entries\Entry;

class ActivityDispatcherTest extends TestCase
{
    public function test_it_dispatches_create_note_to_note_controller()
    {
        // Mock payload
        $payload = [
            'type' => 'Create',
            'object' => [
                'type' => 'Note',
                'content' => 'Hello World'
            ]
        ];

        $mockController = \Mockery::mock(NoteController::class);
        $mockController->shouldReceive('handleCreate')
            ->once()
            ->andReturn('handled');

        $this->app->instance(NoteController::class, $mockController);

        // Act
        // Pass mock actors
        $localActor = \Mockery::mock(Entry::class);
        $externalActor = \Mockery::mock(Entry::class);

        $result = ActivityDispatcher::dispatch($payload, $localActor, $externalActor);

        // Assert
        $this->assertEquals('handled', $result);
    }

    public function test_it_returns_null_for_unhandled_type()
    {
        $payload = [
            'type' => 'UnknownType',
            'object' => 'foo'
        ];

        $localActor = \Mockery::mock(Entry::class);
        $externalActor = \Mockery::mock(Entry::class);

        $result = ActivityDispatcher::dispatch($payload, $localActor, $externalActor);

        $this->assertNull($result);
    }

    public function test_it_registers_and_dispatches_specific_controllers()
    {
        // Register actual controllers for the test logic (simulating ServiceProvider boot)
        ActivityDispatcher::registerController(NoteController::class);
        ActivityDispatcher::registerController(QuestionController::class);
        ActivityDispatcher::registerController(ArticleController::class);

        $localActor = \Mockery::mock(Entry::class);
        $localActor->shouldReceive('id')->andReturn('local-actor-id');
        $localActor->shouldReceive('get')->andReturn([]);
        $localActor->shouldReceive('absoluteUrl')->andReturn('https://example.com/actors/local');

        $externalActor = \Mockery::mock(Entry::class);
        $externalActor->shouldReceive('id')->andReturn('external-actor-id');
        $externalActor->shouldReceive('get')->andReturn(null);
        $externalActor->shouldReceive('save')->andReturn(true);

        // --- Test Question Dispatch ---
        $payloadQ = ['type' => 'Create', 'object' => ['type' => 'Question', 'content' => 'Poll?']];
        // Mock QuestionController (from ActivityPubQuestions addon)
        $mockQ = \Mockery::mock(QuestionController::class);
        $mockQ->shouldReceive('handleCreate')->once()->andReturn('handled_question');
        $this->app->instance(QuestionController::class, $mockQ);

        $resultQ = ActivityDispatcher::dispatch($payloadQ, $localActor, $externalActor);
        $this->assertEquals('handled_question', $resultQ);

        // --- Test Article Dispatch ---
        $payloadA = ['type' => 'Create', 'object' => ['type' => 'Article', 'content' => 'Long Read']];
        // Mock ArticleController
        $mockA = \Mockery::mock(ArticleController::class);
        $mockA->shouldReceive('handleCreate')->once()->andReturn('handled_article');
        $this->app->instance(ArticleController::class, $mockA);

        $resultA = ActivityDispatcher::dispatch($payloadA, $localActor, $externalActor);
        $this->assertEquals('handled_article', $resultA);

        // --- Test Note Dispatch ---
        $payloadN = ['type' => 'Create', 'object' => ['type' => 'Note', 'content' => 'Short']];
        // Mock NoteController
        $mockN = \Mockery::mock(NoteController::class);
        $mockN->shouldReceive('handleCreate')->once()->andReturn('handled_note');
        $this->app->instance(NoteController::class, $mockN);

        $resultN = ActivityDispatcher::dispatch($payloadN, $localActor, $externalActor);
        $this->assertEquals('handled_note', $resultN);
    }
    public function test_it_discovers_unregistered_controllers()
    {
        $path = __DIR__ . '/../Http/Controllers';
        $namespace = 'Ethernick\\ActivityPubCore\\Http\\Controllers\\';

        ActivityDispatcher::discover($path, $namespace);

        $payload = ['type' => 'Create', 'object' => ['type' => 'Note', 'content' => 'Discovered']];
        $mockN = \Mockery::mock(NoteController::class);
        $mockN->shouldReceive('handleCreate')->once()->andReturn('handled_discovered');
        $this->app->instance(NoteController::class, $mockN);

        $localActor = \Mockery::mock(Entry::class);
        $externalActor = \Mockery::mock(Entry::class);

        $result = ActivityDispatcher::dispatch($payload, $localActor, $externalActor);

        $this->assertEquals('handled_discovered', $result);
    }
}
