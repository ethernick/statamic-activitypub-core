<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Contracts;

interface ActivityHandlerInterface
{
    /**
     * Get the array of activity types handled by this controller.
     * Format: ['ActivityType:ObjectType'] e.g. ['Create:Note', 'Update:Question']
     *
     * @return array
     */
    public static function getHandledActivityTypes(): array;
}
