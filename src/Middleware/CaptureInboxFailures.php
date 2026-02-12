<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CaptureInboxFailures
{
    public function handle(Request $request, Closure $next): mixed
    {
        try {
            return $next($request);
        } catch (\Exception $e) {
            // Only capture if it's an ActivityPub related error or crash

            Log::error('ActivityPub Inbox Global Failure: ' . $e->getMessage());

            // Capture the failed payload
            try {
                $timestamp = now()->format('Y-m-d_H-i-s');
                $id = uniqid();
                $filename = "activitypub/failures/global_{$timestamp}_{$id}.json";
                $data = [
                    'url' => $request->fullUrl(),
                    'method' => $request->method(),
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'payload' => $request->json()->all(),
                    'headers' => $request->headers->all(),
                ];
                Storage::disk('local')->put($filename, json_encode($data, JSON_PRETTY_PRINT));
                Log::info("Saved failed Global payload to storage/$filename");
            } catch (\Exception $writeErr) {
                Log::error("Failed to save failed payload: " . $writeErr->getMessage());
            }

            // Re-throw so the app can still respond with 500 or handle it
            throw $e;
        }
    }
}
