<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Tests;

use Tests\TestCase;
use Ethernick\ActivityPubCore\Tests\Concerns\ProvidesSandbox;

class TestSearchCommandTest extends TestCase
{
    use ProvidesSandbox;

    public function test_activitypub_test_search_command_runs_successfully(): void
    {
        $this->artisan('activitypub:test:search')
            ->expectsOutputToContain('ActivityPub Search Index Performance & Health Test')
            ->expectsOutputToContain('Actor Fast Lookup Map')
            ->expectsOutputToContain('Search Token Index Coverage')
            ->expectsOutputToContain('Standard Query Benchmark Suite')
            ->assertExitCode(0);
    }

    public function test_activitypub_test_search_command_with_custom_query(): void
    {
        $this->artisan('activitypub:test:search', ['query' => '@admin'])
            ->expectsOutputToContain('Custom Query Benchmark')
            ->assertExitCode(0);
    }
}
