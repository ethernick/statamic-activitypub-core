<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Http\Controllers\CP;

use Statamic\Http\Controllers\CP\CpController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Http\JsonResponse;

class QueueController extends CpController
{
    /**
     * Display the Queue Dashboard view.
     */
    public function index()
    {
        return view('activitypub::cp.queue.index');
    }

    /**
     * Get the overall status/counts of the queues.
     */
    public function status(): JsonResponse
    {
        $pendingCount = DB::table('jobs')->count();
        $failedCount = DB::table('failed_jobs')->count();

        return response()->json([
            'pending_count' => $pendingCount,
            'failed_count' => $failedCount,
        ]);
    }

    /**
     * Get a paginated list of pending jobs, parsing the payload for the job class.
     */
    public function pending(Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', 50);

        $jobs = DB::table('jobs')->orderBy('id', 'asc')->paginate($perPage);

        $jobs->getCollection()->transform(function ($job) {
            $payload = json_decode($job->payload, true);
            $jobName = $payload['displayName'] ?? 'Unknown';

            // Extract the actual job class from 'data.commandName' if available
            if (isset($payload['data']['commandName'])) {
                $jobName = $payload['data']['commandName'];
            } elseif (isset($payload['job'])) {
                $jobName = $payload['job'];
            }

            $job->parsed_name = $jobName;
            $job->display_name = $payload['displayName'] ?? $jobName;
            $job->raw_payload = $job->payload;
            return $job;
        });

        return response()->json($jobs);
    }

    /**
     * Delete a specific pending job.
     */
    public function deletePending(string $id): JsonResponse
    {
        DB::table('jobs')->where('id', $id)->delete();

        return response()->json(['message' => 'Job deleted successfully.']);
    }

    /**
     * Flush all pending jobs of a specific parsed type.
     */
    public function flushPendingByType(Request $request): JsonResponse
    {
        $type = $request->input('type');
        if (!$type) {
            return response()->json(['message' => 'Type is required.'], 422);
        }

        $jobs = DB::table('jobs')->get();
        $deletedCount = 0;

        foreach ($jobs as $job) {
            $payload = json_decode($job->payload, true);
            $jobName = $payload['displayName'] ?? 'Unknown';

            if (isset($payload['data']['commandName'])) {
                $jobName = $payload['data']['commandName'];
            } elseif (isset($payload['job'])) {
                $jobName = $payload['job'];
            }

            $displayName = $payload['displayName'] ?? $jobName;

            if ($displayName === $type) {
                DB::table('jobs')->where('id', $job->id)->delete();
                $deletedCount++;
            }
        }

        return response()->json(['message' => "Successfully flushed {$deletedCount} jobs of type {$type}."]);
    }

    /**
     * Get a paginated list of failed jobs.
     */
    public function failed(Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', 50);

        $jobs = DB::table('failed_jobs')->orderBy('failed_at', 'desc')->paginate($perPage);

        $jobs->getCollection()->transform(function ($job) {
            $payload = json_decode($job->payload, true);
            $jobName = $payload['displayName'] ?? 'Unknown';

            if (isset($payload['data']['commandName'])) {
                $jobName = $payload['data']['commandName'];
            } elseif (isset($payload['job'])) {
                $jobName = $payload['job'];
            }

            $job->parsed_name = $jobName;
            $job->display_name = $payload['displayName'] ?? $jobName;
            $job->raw_payload = $job->payload;
            return $job;
        });

        return response()->json($jobs);
    }

    /**
     * Retry a specific failed job.
     */
    public function retry(string $id): JsonResponse
    {
        Artisan::call('queue:retry', ['id' => [$id]]);

        return response()->json(['message' => 'Job retry initiated.']);
    }

    /**
     * Delete a specific failed job.
     */
    public function deleteFailed(string $id): JsonResponse
    {
        DB::table('failed_jobs')->where('id', $id)->delete();

        return response()->json(['message' => 'Failed job deleted successfully.']);
    }

    /**
     * Flush all failed jobs.
     */
    public function flushFailed(): JsonResponse
    {
        Artisan::call('queue:flush');

        return response()->json(['message' => 'All failed jobs flushed.']);
    }

    /**
     * Retry all ActivityPub related failed jobs.
     */
    public function retryFailedActivityPub(): JsonResponse
    {
        $query = DB::table('failed_jobs')
            ->where(function ($q) {
                $q->where('queue', 'activitypub-outbox')
                    ->orWhere('payload', 'like', '%Ethernick\\\\ActivityPubCore%');
            });

        $jobs = $query->get();
        if ($jobs->isEmpty()) {
            return response()->json(['message' => 'No ActivityPub failed jobs found.']);
        }

        $count = $jobs->count();
        foreach ($jobs as $job) {
            Artisan::call('queue:retry', ['id' => [$job->id]]);
        }

        return response()->json(['message' => "Retry initiated for {$count} ActivityPub jobs."]);
    }

    /**
     * Flush all ActivityPub related failed jobs.
     */
    public function flushFailedActivityPub(): JsonResponse
    {
        $query = DB::table('failed_jobs')
            ->where(function ($q) {
                $q->where('queue', 'activitypub-outbox')
                    ->orWhere('payload', 'like', '%Ethernick\\\\ActivityPubCore%');
            });

        $count = $query->count();
        if ($count === 0) {
            return response()->json(['message' => 'No ActivityPub failed jobs found.']);
        }

        $query->delete();

        return response()->json(['message' => "Successfully flushed {$count} ActivityPub jobs."]);
    }
}
