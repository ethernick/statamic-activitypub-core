<?php

namespace Ethernick\ActivityPubCore\Tests;

use Ethernick\ActivityPubCore\Console\Commands\ProcessInbox;
use Ethernick\ActivityPubCore\Console\Commands\ProcessOutbox;
use Ethernick\ActivityPubCore\Jobs\FileQueue;
use Ethernick\ActivityPubCore\Jobs\InboxHandler;
use Statamic\Facades\File;
use Statamic\Facades\YAML;
use Tests\TestCase;

class CommandBatchTest extends TestCase
{
    protected $settingsPath;
    protected $originalSettings;

    public function setUp(): void
    {
        parent::setUp();
        $this->settingsPath = resource_path('settings/activitypub.yaml');
        if (File::exists($this->settingsPath)) {
            $this->originalSettings = File::get($this->settingsPath);
        }
    }

    public function tearDown(): void
    {
        if ($this->originalSettings) {
            File::put($this->settingsPath, $this->originalSettings);
        } else {
            File::delete($this->settingsPath);
        }
        parent::tearDown();
    }

    public function test_process_inbox_uses_configured_batch_size()
    {
        // 1. Set configured limit to 10
        $settings = ['inbox_batch_size' => 10];
        File::put($this->settingsPath, YAML::dump($settings));

        // 2. Mock FileQueue to expect a list call with limit 10
        $this->mock(FileQueue::class, function ($mock) {
            $mock->shouldReceive('list')
                ->once()
                ->with('inbox', 10)
                ->andReturn([]);
        });

        // 3. Mock Handler (dependency)
        $this->mock(InboxHandler::class);

        // 4. Run command without flag
        $this->artisan('activitypub:process-inbox')
            ->assertExitCode(0);
    }

    public function test_process_inbox_flag_overrides_configuration()
    {
        // 1. Set configured limit to 10
        $settings = ['inbox_batch_size' => 10];
        File::put($this->settingsPath, YAML::dump($settings));

        // 2. Mock FileQueue to expect a list call with limit 5 (flag value)
        $this->mock(FileQueue::class, function ($mock) {
            $mock->shouldReceive('list')
                ->once()
                ->with('inbox', 5)
                ->andReturn([]);
        });

        $this->mock(InboxHandler::class);

        // 3. Run command with flag
        $this->artisan('activitypub:process-inbox', ['--limit' => 5])
            ->assertExitCode(0);
    }

    public function test_process_outbox_uses_configured_batch_size()
    {
        // 1. Set configured limit to 20
        $settings = ['outbox_batch_size' => 20];
        File::put($this->settingsPath, YAML::dump($settings));

        // 2. Mock FileQueue to expect a list call with limit 20
        $this->mock(FileQueue::class, function ($mock) {
            $mock->shouldReceive('list')
                ->once()
                ->with('outbox', 20)
                ->andReturn([]);
        });

        // 3. Run command without flag
        $this->artisan('activitypub:process-outbox')
            ->assertExitCode(0);
    }

    public function test_process_outbox_defaults_to_50_if_no_setting()
    {
        // 1. Ensure no settings file or empty setting
        if (File::exists($this->settingsPath)) {
            File::delete($this->settingsPath);
        }

        // 2. Mock FileQueue to expect default limit 50
        $this->mock(FileQueue::class, function ($mock) {
            $mock->shouldReceive('list')
                ->once()
                ->with('outbox', 50)
                ->andReturn([]);
        });

        // 3. Run command
        $this->artisan('activitypub:process-outbox')
            ->assertExitCode(0);
    }
}
