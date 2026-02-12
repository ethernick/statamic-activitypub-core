<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Actions;

use Statamic\Actions\Action;
use Statamic\Facades\Entry;

class ResendActivityAction extends Action
{
    public static function title(): string
    {
        return 'Resend';
    }

    public function visibleTo(mixed $item): bool
    {
        if (!$item instanceof \Statamic\Contracts\Entries\Entry) {
            return false;
        }

        // Only show for activity entries
        return $item->collectionHandle() === 'activities';
    }

    public function visibleToBulk(mixed $items): bool
    {
        // Allow bulk resending
        return $items->every(function ($item) {
            return $item instanceof \Statamic\Contracts\Entries\Entry
                && $item->collectionHandle() === 'activities';
        });
    }

    public function authorize(mixed $user, mixed $item): bool
    {
        return $user->can('edit', $item);
    }

    public function run(mixed $items, mixed $values): array
    {
        $items->each(function ($activity) {
            \Ethernick\ActivityPubCore\Jobs\SendActivityPubPost::dispatch($activity->id())
                ->onQueue('activitypub-outbox');
        });

        $count = $items->count();
        $plural = $count === 1 ? 'activity' : 'activities';

        return [
            'message' => "Queued {$count} {$plural} for delivery",
        ];
    }

    public function buttonText(): string
    {
        /** @translation */
        return 'Resend';
    }

    public function confirmationText(): string
    {
        /** @translation */
        return 'Are you sure you want to resend this activity?';
    }

    public function warningText(): string
    {
        return 'This will re-send the activity to all followers. Only use this for failed or broken activities.';
    }
}
