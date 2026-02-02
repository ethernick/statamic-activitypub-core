<?php

namespace Ethernick\ActivityPubCore\Actions;

use Statamic\Actions\Action;
use Statamic\Facades\Entry;

class ResendActivityAction extends Action
{
    public static function title()
    {
        return 'Resend';
    }

    public function visibleTo($item)
    {
        if (!$item instanceof \Statamic\Contracts\Entries\Entry) {
            return false;
        }

        // Only show for activity entries
        return $item->collectionHandle() === 'activities';
    }

    public function visibleToBulk($items)
    {
        // Allow bulk resending
        return $items->every(function ($item) {
            return $item instanceof \Statamic\Contracts\Entries\Entry
                && $item->collectionHandle() === 'activities';
        });
    }

    public function authorize($user, $item)
    {
        return $user->can('edit', $item);
    }

    public function run($items, $values)
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

    public function buttonText()
    {
        /** @translation */
        return 'Resend';
    }

    public function confirmationText()
    {
        /** @translation */
        return 'Are you sure you want to resend this activity?';
    }

    public function warningText()
    {
        return 'This will re-send the activity to all followers. Only use this for failed or broken activities.';
    }
}
