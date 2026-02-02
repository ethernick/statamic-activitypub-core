<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Jobs;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileQueue
{
    protected string $disk = 'local';
    protected string $basePath = 'activitypub';

    public function __construct()
    {
        // Ensure directories exist
        if (!Storage::disk($this->disk)->exists($this->basePath . '/inbox')) {
            Storage::disk($this->disk)->makeDirectory($this->basePath . '/inbox');
        }
        if (!Storage::disk($this->disk)->exists($this->basePath . '/outbox')) {
            Storage::disk($this->disk)->makeDirectory($this->basePath . '/outbox');
        }
    }

    /**
     * Push an item to the specified queue.
     */
    public function push(string $queue, array $data): string
    {
        $timestamp = now()->format('YmdHis');
        $uuid = (string) Str::uuid();
        $filename = "{$timestamp}_{$uuid}.json";
        $path = "{$this->basePath}/{$queue}/{$filename}";

        Storage::disk($this->disk)->put($path, json_encode($data, JSON_PRETTY_PRINT));

        return $filename;
    }

    /**
     * Get the next batch of files from the queue, sorted by timestamp.
     */
    public function list(string $queue, int $limit = 50): array
    {
        // Get files
        $files = Storage::disk($this->disk)->files("{$this->basePath}/{$queue}");

        // Sort by name (which acts as timestamp sort due to naming convention)
        sort($files);

        return array_slice($files, 0, $limit);
    }

    /**
     * Read a file's content (parsed JSON).
     */
    public function get(string $path): ?array
    {
        if (!Storage::disk($this->disk)->exists($path)) {
            return null;
        }

        $content = Storage::disk($this->disk)->get($path);
        return json_decode($content, true);
    }

    /**
     * Delete a file from the queue.
     */
    public function delete(string $path): bool
    {
        return Storage::disk($this->disk)->delete($path);
    }

    /**
     * Move a file to a failed directory (optional, for future use).
     */
    public function fail(string $path): void
    {
        // For now, user said "stop processing" for inbox, or "proces again" for outbox.
        // We might implement a 'failed' folder logic later if needed.
        // For now, if we don't delete it, it stays in the queue.
    }
}
