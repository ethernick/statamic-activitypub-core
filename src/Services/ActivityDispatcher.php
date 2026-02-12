<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Services;

use Illuminate\Support\Facades\Log;

class ActivityDispatcher
{
    /**
     * Map of Activity Type + Object Type -> Controller Class
     * Format: 'ActivityType:ObjectType' => ControllerClass
     * Wildcards: 'ActivityType:*' or '*:ObjectType'
     */
    protected static $registry = [];

    protected static $defaultHandlers = [
        'Create:Note' => \Ethernick\ActivityPubCore\Http\Controllers\NoteController::class,
        'Update:Note' => \Ethernick\ActivityPubCore\Http\Controllers\NoteController::class,
        'Delete:Note' => \Ethernick\ActivityPubCore\Http\Controllers\NoteController::class,
        'QuoteRequest:Reference' => \Ethernick\ActivityPubCore\Http\Controllers\QuoteRequestController::class,
        'Accept:QuoteRequest' => \Ethernick\ActivityPubCore\Http\Controllers\AcceptController::class,
    ];

    public static function register(string $activityType, string $objectType, string $controllerClass): void
    {
        self::$registry["$activityType:$objectType"] = $controllerClass;
    }

    public static function registerController(string $controllerClass): void
    {
        if (is_subclass_of($controllerClass, \Ethernick\ActivityPubCore\Contracts\ActivityHandlerInterface::class)) {
            foreach ($controllerClass::getHandledActivityTypes() as $key) {
                // $key should be "Activity:Object"
                self::$registry[$key] = $controllerClass;
            }
        }
    }

    public static function discover(string $directory, string $namespace): void
    {
        if (!is_dir($directory))
            return;

        $files = scandir($directory);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..')
                continue;
            if (pathinfo($file, PATHINFO_EXTENSION) !== 'php')
                continue;

            $className = $namespace . pathinfo($file, PATHINFO_FILENAME);
            if (class_exists($className)) {
                self::registerController($className);
            }
        }
    }

    public static function registerControllersFromTypes(): void
    {
        if (class_exists(\Ethernick\ActivityPubCore\Services\ActivityPubTypes::class)) {
            $types = (new \Ethernick\ActivityPubCore\Services\ActivityPubTypes())->all();
            foreach ($types as $typeDef) {
                if (!empty($typeDef['controller']) && class_exists($typeDef['controller'])) {
                    self::registerController($typeDef['controller']);
                }
            }
        }
    }

    public static function dispatch(array $payload, mixed $localActor, mixed $externalActor): mixed
    {
        $type = $payload['type'] ?? 'Unknown';
        $object = $payload['object'] ?? null;

        $objectType = 'Unknown';
        if (is_array($object)) {
            $objectType = $object['type'] ?? 'Unknown';
        } elseif (is_string($object)) {
            // Can't easily determine type from ID without fetching. 
            // Dispatcher might need to handle this or generic fallback.
            // For Delete activities, object is often just an ID (URI).
            // We might treat it as 'Unknown' or specialized 'Reference'.
            $objectType = 'Reference';
        }

        $key = "$type:$objectType";

        $controllerClass = self::$registry[$key] ?? self::$defaultHandlers[$key] ?? null;

        if (!$controllerClass) {
            // Try wildcards
            $controllerClass = self::$registry["$type:*"] ?? self::$defaultHandlers["$type:*"] ?? null;
        }

        if ($controllerClass) {
            Log::info("ActivityDispatcher: Dispatching $key to $controllerClass");

            // Instantiating the controller. 
            // In Laravel, we can resolve from container.
            $controller = app($controllerClass);

            // Determine method: handle{ActivityType}
            $method = "handle{$type}";

            if (method_exists($controller, $method)) {
                return $controller->$method($payload, $localActor, $externalActor);
            } else {
                Log::warning("ActivityDispatcher: Method $method not found on $controllerClass");
            }
        }

        Log::info("ActivityDispatcher: No handler found for $key");
        return null;
    }
}
