<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Listeners;

use Statamic\Events\EntrySaving;
use Statamic\Entries\Entry;
use Statamic\Facades\Asset;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class GenerateActorAvatar
{
    public function handle(EntrySaving $event): void
    {
        /** @var Entry $entry */
        $entry = $event->entry;

        if ($entry->collectionHandle() !== 'actors') {
            return;
        }

        $avatarPath = $entry->get('avatar');

        if (!$avatarPath) {
            return;
        }

        $asset = Asset::find($avatarPath);

        // Try looking up with container prefix if not found
        if (!$asset && !str_contains($avatarPath, '::')) {
            $asset = Asset::find('assets::' . $avatarPath);
        }

        if (!$asset || !$asset->exists()) {
            return;
        }

        $sourcePath = $asset->resolvedPath();
        $publicDir = public_path('activitypub/avatars');
        $destPath = $publicDir . '/' . $entry->slug() . '.jpg';

        if (!File::exists($publicDir)) {
            File::makeDirectory($publicDir, 0755, true);
        }

        try {
            // Use Intervention Image v3 API if available, fallback to GD directly
            if (class_exists(\Intervention\Image\ImageManager::class)) {
                $this->resizeWithIntervention($sourcePath, $destPath);
            } else {
                $this->resizeWithGd($sourcePath, $destPath);
            }
        } catch (\Exception $e) {
            // Log error but don't stop saving
            Log::error('Failed to generate ActivityPub avatar: ' . $e->getMessage());
        }
    }

    /**
     * Resize using Intervention Image v3 API
     */
    protected function resizeWithIntervention(string $sourcePath, string $destPath): void
    {
        $manager = new \Intervention\Image\ImageManager(
            \Intervention\Image\Drivers\Gd\Driver::class
        );

        $image = $manager->read($sourcePath);
        $image->cover(256, 256);
        $image->toJpeg(85)->save($destPath);
    }

    /**
     * Fallback: resize using PHP's GD extension directly
     */
    protected function resizeWithGd(string $sourcePath, string $destPath): void
    {
        $info = getimagesize($sourcePath);
        if ($info === false) {
            throw new \RuntimeException("Cannot read image: $sourcePath");
        }

        $mime = $info['mime'];
        $srcWidth = $info[0];
        $srcHeight = $info[1];

        // Create source image from file type
        $source = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($sourcePath),
            'image/png' => imagecreatefrompng($sourcePath),
            'image/gif' => imagecreatefromgif($sourcePath),
            'image/webp' => imagecreatefromwebp($sourcePath),
            default => throw new \RuntimeException("Unsupported image type: $mime"),
        };

        if ($source === false) {
            throw new \RuntimeException("Failed to create image resource from: $sourcePath");
        }

        // Calculate crop dimensions for center-crop fit (same as cover/fit)
        $size = 256;
        $ratio = max($size / $srcWidth, $size / $srcHeight);
        $cropWidth = (int) ceil($size / $ratio);
        $cropHeight = (int) ceil($size / $ratio);
        $cropX = (int) (($srcWidth - $cropWidth) / 2);
        $cropY = (int) (($srcHeight - $cropHeight) / 2);

        $dest = imagecreatetruecolor($size, $size);
        imagecopyresampled($dest, $source, 0, 0, $cropX, $cropY, $size, $size, $cropWidth, $cropHeight);
        imagejpeg($dest, $destPath, 85);

        imagedestroy($source);
        imagedestroy($dest);
    }
}
