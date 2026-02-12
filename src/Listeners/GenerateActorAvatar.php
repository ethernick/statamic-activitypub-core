<?php


namespace Ethernick\ActivityPubCore\Listeners;

use Statamic\Events\EntrySaving;
use Statamic\Entries\Entry;
use Statamic\Facades\Asset;
use Intervention\Image\ImageManagerStatic as Image;
use Illuminate\Support\Facades\File;

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

        // Handle both simple path strings and augmented values if they happen to be there (though get() usually raw)
        // Usually 'assets/avatars/img.jpg' or just 'avatars/img.jpg' if container is implied?
        // Let's resolve the asset to be sure.

        $asset = Asset::find($avatarPath);

        // Try looking up with container prefix if not found
        if (!$asset && !str_contains($avatarPath, '::')) {
            $asset = Asset::find('assets::' . $avatarPath);
        }

        if (!$asset || !$asset->exists()) {
            return;
        }

        $sourcePath = $asset->resolvedPath(); // Absolute path on disk
        $publicDir = public_path('activitypub/avatars');
        $destPath = $publicDir . '/' . $entry->slug() . '.jpg';

        if (!File::exists($publicDir)) {
            File::makeDirectory($publicDir, 0755, true);
        }

        try {
            // Resize and save
            // Check if Image facade is available, otherwise use direct class if facade not aliased
            if (class_exists('Intervention\Image\Facades\Image')) {
                $img = \Intervention\Image\Facades\Image::make($sourcePath);
            } else {
                $img = Image::make($sourcePath);
            }

            $img->fit(256, 256, function ($constraint) {
                $constraint->upsize();
            });

            $img->save($destPath, 85, 'jpg');

        } catch (\Exception $e) {
            // Log error but don't stop saving
            \Illuminate\Support\Facades\Log::error('Failed to generate ActivityPub avatar: ' . $e->getMessage());
        }
    }
}
