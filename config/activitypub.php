<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Configure queue behavior for ActivityPub jobs including retry attempts,
    | timeouts, and backoff strategies for different queue types.
    |
    */

    'queue' => [

        // Outbox queue - for sending activities to followers
        'outbox' => [
            'name' => 'activitypub-outbox',
            'tries' => env('ACTIVITYPUB_QUEUE_OUTBOX_TRIES', 3),
            'timeout' => env('ACTIVITYPUB_QUEUE_OUTBOX_TIMEOUT', 120), // seconds
            'backoff' => [
                env('ACTIVITYPUB_QUEUE_OUTBOX_BACKOFF_1', 60),   // 1 minute
                env('ACTIVITYPUB_QUEUE_OUTBOX_BACKOFF_2', 300),  // 5 minutes
                env('ACTIVITYPUB_QUEUE_OUTBOX_BACKOFF_3', 900),  // 15 minutes
            ],
            'batch_size' => env('ACTIVITYPUB_QUEUE_OUTBOX_BATCH', 50),
        ],

        // Default queue - for general background jobs
        'default' => [
            'name' => 'default',
            'tries' => env('ACTIVITYPUB_QUEUE_DEFAULT_TRIES', 3),
            'timeout' => env('ACTIVITYPUB_QUEUE_DEFAULT_TIMEOUT', 120), // seconds
            'batch_size' => env('ACTIVITYPUB_QUEUE_DEFAULT_BATCH', 50),
            'max_time' => env('ACTIVITYPUB_QUEUE_DEFAULT_MAX_TIME', 50), // seconds
        ],

        // Maintenance queue - for heavy background tasks
        'maintenance' => [
            'name' => 'maintenance',
            'tries' => env('ACTIVITYPUB_QUEUE_MAINTENANCE_TRIES', 2),
            'timeout' => env('ACTIVITYPUB_QUEUE_MAINTENANCE_TIMEOUT', 600), // 10 minutes
        ],

        // Inbox queue - for processing incoming activities
        'inbox' => [
            'name' => 'activitypub-inbox',
            'batch_size' => env('ACTIVITYPUB_QUEUE_INBOX_BATCH', 50),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Configuration
    |--------------------------------------------------------------------------
    |
    | Configure HTTP behavior for outbound ActivityPub requests.
    |
    */

    'http' => [
        // Timeout for HTTP requests to remote servers (seconds)
        'timeout' => env('ACTIVITYPUB_HTTP_TIMEOUT', 30),

        // User agent string for outbound requests
        'user_agent' => env('ACTIVITYPUB_USER_AGENT', 'StatamicActivityPub/0.1'),

        // Maximum number of concurrent HTTP requests when sending to multiple inboxes
        // Higher values = faster delivery but more server resources
        // Lower values = slower but more conservative
        'max_concurrent' => env('ACTIVITYPUB_HTTP_MAX_CONCURRENT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Limit outbound HTTP requests to prevent overwhelming remote servers
    | and avoid being blocked. Rate limits are applied PER DOMAIN.
    |
    */

    'rate_limits' => [
        // Maximum requests per minute to a single remote domain
        // Default: 30 requests/minute per domain (one every 2 seconds)
        // Adjust based on remote server policies and your needs
        'per_minute' => env('ACTIVITYPUB_RATE_LIMIT_PER_MINUTE', 30),

        // Whether to enable rate limiting (disable for testing)
        'enabled' => env('ACTIVITYPUB_RATE_LIMIT_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Data Retention
    |--------------------------------------------------------------------------
    |
    | Configure how long to keep external ActivityPub data before cleanup.
    | Internal content (created by local actors) is never automatically deleted.
    |
    */

    'retention' => [
        // Days to keep external activities (Follow, Like, Announce, etc.)
        'activities' => env('ACTIVITYPUB_RETENTION_ACTIVITIES', 2),

        // Days to keep external entries (Notes, Articles, etc. from remote servers)
        'entries' => env('ACTIVITYPUB_RETENTION_ENTRIES', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Schedule Configuration
    |--------------------------------------------------------------------------
    |
    | Configure automated task scheduling for queue processing and maintenance.
    |
    */

    'schedule' => [
        // Interval in minutes between queue processing runs (1-60)
        'interval' => env('ACTIVITYPUB_SCHEDULE_INTERVAL', 1),

        // Time of day to run maintenance tasks (HH:MM format)
        'maintenance_time' => env('ACTIVITYPUB_SCHEDULE_MAINTENANCE', '02:00'),
    ],

];
