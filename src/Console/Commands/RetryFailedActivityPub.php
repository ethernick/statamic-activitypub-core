<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

class RetryFailedActivityPub extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'activitypub:retry-failed 
                            {--id= : Retry a specific failed job ID} 
                            {--all : Retry all ActivityPub related failed jobs}
                            {--flush : Delete the matching failed jobs instead of retrying}
                            {--queue=activitypub-outbox : The queue to filter by}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Retry or flush failed ActivityPub jobs from the dead letter queue.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $id = $this->option('id');
        $all = $this->option('all');
        $flush = $this->option('flush');
        $queueFilter = $this->option('queue');

        if (!$id && !$all) {
            $this->error('You must specify either --id={id} or --all.');
            return 1;
        }

        $query = DB::table('failed_jobs');

        if ($id) {
            $query->where('id', $id);
        } else {
            // Filter by queue or ActivityPub namespace in payload
            $query->where(function ($q) use ($queueFilter) {
                $q->where('queue', $queueFilter)
                    ->orWhere('payload', 'like', '%Ethernick\\\\ActivityPubCore%');
            });
        }

        $jobs = $query->get();

        if ($jobs->isEmpty()) {
            $this->info('No matching failed jobs found.');
            return 0;
        }

        $action = $flush ? 'Flushing' : 'Retrying';
        $this->info("{$action} " . $jobs->count() . " failed jobs...");

        $bar = $this->output->createProgressBar($jobs->count());
        $bar->start();

        foreach ($jobs as $job) {
            if ($flush) {
                DB::table('failed_jobs')->where('id', $job->id)->delete();
            } else {
                Artisan::call('queue:retry', ['id' => [$job->id]]);
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        $completedAction = $flush ? 'flushed' : 'retry initiated for';
        $this->info("Successfully {$completedAction} " . $jobs->count() . " jobs.");

        return 0;
    }
}
