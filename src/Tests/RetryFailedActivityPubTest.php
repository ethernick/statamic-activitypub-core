<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Tests;

use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;

class RetryFailedActivityPubTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure failed_jobs table exists
        if (!\Illuminate\Support\Facades\Schema::hasTable('failed_jobs')) {
            Artisan::call('queue:failed-table');
            Artisan::call('migrate');
        }
    }

    #[Test]
    public function it_requires_id_or_all_option(): void
    {
        $this->artisan('activitypub:retry-failed')
            ->expectsOutput('You must specify either --id={id} or --all.')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_filters_activitypub_jobs_correctly(): void
    {
        // AP Job via queue name
        DB::table('failed_jobs')->insert([
            'uuid' => 'uuid-1',
            'connection' => 'database',
            'queue' => 'activitypub-outbox',
            'payload' => json_encode(['displayName' => 'SendToInboxJob']),
            'exception' => 'Error',
            'failed_at' => now(),
        ]);

        // AP Job via payload namespace
        DB::table('failed_jobs')->insert([
            'uuid' => 'uuid-2',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['job' => 'Ethernick\ActivityPubCore\Jobs\SendActivityPubPost']),
            'exception' => 'Error',
            'failed_at' => now(),
        ]);

        // Non-AP Job
        DB::table('failed_jobs')->insert([
            'uuid' => 'uuid-3',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['job' => 'App\Jobs\GenericJob']),
            'exception' => 'Error',
            'failed_at' => now(),
        ]);

        // We use --flush for easy verification without side -effects of calling queue:retry
        $this->artisan('activitypub:retry-failed --all --flush')
            ->expectsOutput('Flushing 2 failed jobs...')
            ->assertExitCode(0);

        $this->assertEquals(1, DB::table('failed_jobs')->count());
        $remaining = DB::table('failed_jobs')->first();
        $this->assertEquals('uuid-3', $remaining->uuid);
    }

    #[Test]
    public function it_can_flush_a_specific_id(): void
    {
        $id = DB::table('failed_jobs')->insertGetId([
            'uuid' => 'uuid-1',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['job' => 'App\AnyJob']),
            'exception' => 'Error',
            'failed_at' => now(),
        ]);

        $this->artisan("activitypub:retry-failed --id={$id} --flush")
            ->expectsOutput('Flushing 1 failed jobs...')
            ->assertExitCode(0);

        $this->assertEquals(0, DB::table('failed_jobs')->count());
    }
}
