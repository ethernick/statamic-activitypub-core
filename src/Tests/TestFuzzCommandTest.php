<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Tests;

use Tests\TestCase;
use Ethernick\ActivityPubCore\Tests\Concerns\ProvidesSandbox;

class TestFuzzCommandTest extends TestCase
{
    use ProvidesSandbox;

    public function test_activitypub_test_fuzz_generates_and_cleans_up(): void
    {
        // 1. Generate a small batch in sandbox
        $this->artisan('activitypub:test:fuzz', ['--count' => '5', '--actors' => '2'])
            ->expectsOutputToContain('ActivityPub Fuzz & Scale Generator')
            ->expectsOutputToContain('Fuzz dataset creation completed')
            ->assertExitCode(0);

        // 2. Clean up
        $this->artisan('activitypub:test:fuzz', ['--cleanup' => true])
            ->expectsOutputToContain('Cleaning up all fuzz test data')
            ->assertExitCode(0);
    }
}
