<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Logging;

use Illuminate\Support\Facades\Log;

class ActivityPubLog
{
    public static function info(mixed $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    public static function warning(mixed $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    public static function error(mixed $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    protected static function write(string $level, mixed $message, array $context = []): void
    {
        // Also log to default channel for safety/monitoring
        Log::$level('[ActivityPub] ' . $message, $context);

        // Write to specific log file
        Log::build([
                    'driver' => 'single',
                    'path' => storage_path('logs/activitypub.log'),
                ])->$level($message, $context);
    }
}
