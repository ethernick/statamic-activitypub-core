<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\Entry;

class DownloadActorAvatar implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;
    public int $timeout;

    protected string $actorId;
    protected string $avatarUrl;

    /**
     * Create a new job instance.
     */
    public function __construct(string $actorId, string $avatarUrl)
    {
        $this->actorId = $actorId;
        $this->avatarUrl = $avatarUrl;

        $this->tries = 2;
        $this->timeout = 30;

        $this->onQueue('default');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $actor = Entry::find($this->actorId);
        if (!$actor) {
            Log::warning("DownloadActorAvatar: Actor not found for ID: {$this->actorId}");
            return;
        }

        try {
            $response = Http::timeout(10)->get($this->avatarUrl);
            if (!$response->successful()) {
                Log::warning("DownloadActorAvatar: HTTP error {$response->status()} fetching avatar: {$this->avatarUrl}");
                return;
            }

            $contentType = (string) $response->header('Content-Type');
            if (!str_starts_with($contentType, 'image/')) {
                Log::warning("DownloadActorAvatar: Non-image content type ({$contentType}) for: {$this->avatarUrl}");
                return;
            }

            $contents = $response->body();
            if (strlen($contents) < 50) {
                Log::warning("DownloadActorAvatar: Image body too small for: {$this->avatarUrl}");
                return;
            }

            $name = md5($this->avatarUrl);
            $extension = 'jpg';
            $mimeMap = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
            ];

            if (isset($mimeMap[$contentType])) {
                $extension = $mimeMap[$contentType];
            } else {
                $ext = pathinfo(parse_url($this->avatarUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION);
                if ($ext) {
                    $extension = $ext;
                }
            }
            $extension = substr(preg_replace('/[^a-z0-9]/', '', strtolower($extension)), 0, 4) ?: 'jpg';

            $filename = "avatars/{$name}.{$extension}";
            Storage::disk('assets')->put($filename, $contents);

            // Update actor entry with the saved local asset path
            $actor->set('avatar', $filename);
            $actor->saveQuietly();

            Log::info("DownloadActorAvatar: Successfully downloaded avatar for actor {$actor->id()} to {$filename}");

        } catch (\Exception $e) {
            Log::error("DownloadActorAvatar: Exception fetching avatar for {$this->actorId}: " . $e->getMessage());
        }
    }
}
