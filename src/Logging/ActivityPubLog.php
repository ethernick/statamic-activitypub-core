<?php

namespace Ethernick\ActivityPubCore\Logging;

use Illuminate\Support\Facades\Log;

class ActivityPubLog
{
    public static function info($message, $context = [])
    {
        self::write('info', $message, $context);
    }

    public static function warning($message, $context = [])
    {
        self::write('warning', $message, $context);
    }

    public static function error($message, $context = [])
    {
        self::write('error', $message, $context);
    }

    protected static function write($level, $message, $context = [])
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
