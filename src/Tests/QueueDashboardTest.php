<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Tests;

use Tests\TestCase;
use Statamic\Facades\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;

class QueueDashboardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure tables exist for testing
        Artisan::call('queue:table');
        Artisan::call('queue:failed-table');
        Artisan::call('migrate');
    }

    #[Test]
    public function an_authenticated_super_user_can_view_queue_dashboard(): void
    {
        $user = User::make()->email('test@example.com')->makeSuper()->save();

        $this->actingAs($user)
            ->get(cp_route('activitypub.queue.index'))
            ->assertOk()
            ->assertSee('Queue Status')
            ->assertSee('queue-status');
    }

    #[Test]
    public function queue_dashboard_requires_authentication(): void
    {
        $this->get(cp_route('activitypub.queue.index'))
            ->assertRedirect(cp_route('login'));
    }

    #[Test]
    public function it_returns_queue_status_counts(): void
    {
        $user = User::make()->email('test@example.com')->makeSuper()->save();

        // Let's manually insert some test data just for counts
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'TestJob']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time(),
            'created_at' => time(),
        ]);

        DB::table('failed_jobs')->insert([
            'uuid' => 'fake-uuid',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'FailedJob']),
            'exception' => 'Exception string',
            'failed_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson(cp_route('activitypub.queue.status'));

        $response->assertOk()
            ->assertJson([
                'pending_count' => 1,
                'failed_count' => 1,
            ]);
    }

    #[Test]
    public function it_returns_pending_jobs_with_parsed_payloads(): void
    {
        $user = User::make()->email('test@example.com')->makeSuper()->save();

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode([
                'displayName' => 'TestJobName',
                'data' => [
                    'commandName' => 'App\Jobs\TheRealJobName'
                ]
            ]),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time(),
            'created_at' => time(),
        ]);

        $response = $this->actingAs($user)->getJson(cp_route('activitypub.queue.pending'));

        $response->assertOk();
        $this->assertEquals('App\Jobs\TheRealJobName', $response->json('data.0.parsed_name'));
    }

    #[Test]
    public function it_can_delete_a_specific_pending_job(): void
    {
        $user = User::make()->email('test@example.com')->makeSuper()->save();

        $id = DB::table('jobs')->insertGetId([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'TestJobName']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time(),
            'created_at' => time(),
        ]);

        $this->assertEquals(1, DB::table('jobs')->count());

        $this->actingAs($user)->deleteJson(cp_route('activitypub.queue.pending.delete', ['id' => $id]))
            ->assertOk();

        $this->assertEquals(0, DB::table('jobs')->count());
    }

    #[Test]
    public function it_can_flush_pending_jobs_by_type(): void
    {
        $user = User::make()->email('test@example.com')->makeSuper()->save();

        // Job to flush
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'SearchIndexUpdate', 'data' => ['commandName' => 'Statamic\Stache\Jobs\UpdateSearchIndex']]),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time(),
            'created_at' => time(),
        ]);

        // Job to keep
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'OtherJob', 'data' => ['commandName' => 'App\Jobs\OtherJob']]),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time(),
            'created_at' => time(),
        ]);

        $this->actingAs($user)->postJson(cp_route('activitypub.queue.pending.flush'), [
            'type' => 'SearchIndexUpdate'
        ])->assertOk();

        $this->assertEquals(1, DB::table('jobs')->count());
        $payload = json_decode(DB::table('jobs')->first()->payload, true);
        $this->assertEquals('App\Jobs\OtherJob', $payload['data']['commandName']);
    }
}
