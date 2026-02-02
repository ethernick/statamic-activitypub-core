<?php

namespace Ethernick\ActivityPubCore\Tests;

use Statamic\Facades\Blueprint;
use Tests\TestCase;

class ActivitiesBlueprintTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Ensure stache is clear
        \Statamic\Facades\Stache::clear();
    }

    public function test_activities_blueprint_has_expected_fields()
    {
        $path = resource_path('blueprints/collections/activities/activities.yaml');

        if (!file_exists($path)) {
            $path = base_path('resources/blueprints/collections/activities/activities.yaml');
        }

        $this->assertFileExists($path);

        $yaml = \Statamic\Facades\YAML::file($path)->parse();

        // Navigate the structure: tabs -> main -> sections -> [0] -> fields
        $fields = collect($yaml['tabs']['main']['sections'][0]['fields'])
            ->map(fn($field) => $field['handle'] ?? null)
            ->filter()
            ->values()
            ->all();

        $this->assertContains('title', $fields);
        $this->assertContains('actor', $fields);
        $this->assertContains('related_object', $fields);
    }
}
