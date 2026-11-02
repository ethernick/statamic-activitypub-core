<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Storage;

use Statamic\Entries\Entry;
use Statamic\Facades\File;

class ActivityPubPayload
{
    protected Entry $entry;
    protected ?array $data = null;
    protected ?string $rawJson = null;

    public function __construct(Entry $entry)
    {
        $this->entry = $entry;
    }

    public static function for(Entry $entry): self
    {
        return new self($entry);
    }

    /**
     * Get the raw JSON string from sidecar, supplement, or fallback.
     */
    public function raw(): ?string
    {
        if ($this->rawJson !== null) {
            return $this->rawJson;
        }

        // 1. Check runtime supplement
        if ($json = $this->entry->getSupplement('activitypub_json')) {
            return $this->rawJson = $json;
        }

        // 2. Check sidecar path on disk
        $path = $this->entry->path();
        if ($path) {
            $jsonPath = $this->sidecarPath($path);
            if (File::exists($jsonPath)) {
                return $this->rawJson = File::get($jsonPath);
            }
        }

        // 3. Fallback: check entry's inline front-matter (for backwards compatibility)
        $inline = $this->entry->get('activitypub_json');
        if ($inline) {
            if (is_array($inline) && isset($inline['code'])) {
                $inline = $inline['code'];
            }
            return $this->rawJson = (string) $inline;
        }

        return null;
    }

    /**
     * Parse the raw payload into an array.
     */
    public function toArray(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        $raw = $this->raw();
        if (!$raw) {
            return [];
        }

        return $this->data = json_decode($raw, true) ?: [];
    }

    /**
     * Write the payload to the sidecar storage and update state.
     */
    public function save(string|array $payload): bool
    {
        $json = is_array($payload) 
            ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            : $payload;

        $this->rawJson = $json;
        $this->data = is_array($payload) ? $payload : json_decode($json, true);

        // 1. Write sidecar file if path is available
        $path = $this->entry->path();
        if ($path) {
            $jsonPath = $this->sidecarPath($path);
            File::put($jsonPath, $json);
        }

        // 2. Clear inline data from entry
        $this->entry->remove('activitypub_json');

        // 3. Keep a runtime supplement for downstream events
        $this->entry->setSupplement('activitypub_json', $json);

        return true;
    }

    /**
     * Delete the sidecar file.
     */
    public function delete(): bool
    {
        $path = $this->entry->path();
        if ($path) {
            $jsonPath = $this->sidecarPath($path);
            if (File::exists($jsonPath)) {
                File::delete($jsonPath);
                return true;
            }
        }
        return false;
    }

    /**
     * Check if the sidecar file exists on disk.
     */
    public function exists(): bool
    {
        $path = $this->entry->path();
        if (!$path) {
            return false;
        }

        return File::exists($this->sidecarPath($path));
    }

    /**
     * Get the sidecar file path on disk.
     */
    public function sidecarFilePath(): ?string
    {
        $path = $this->entry->path();
        if (!$path) {
            return null;
        }

        return $this->sidecarPath($path);
    }

    protected function sidecarPath(string $entryPath): string
    {
        return preg_replace('/\.(md|yaml)$/', '.json', $entryPath);
    }
}
