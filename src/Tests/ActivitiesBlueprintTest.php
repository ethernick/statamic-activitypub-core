<?php

namespace Ethernick\ActivityPubCore\Tests;

use Statamic\Facades\Blueprint;
use Tests\TestCase;
use Ethernick\ActivityPubCore\Tests\Concerns\ProvidesSandbox;

class ActivitiesBlueprintTest extends TestCase
{
    use ProvidesSandbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupSandbox();
        
        // Seed the blueprint from production to sandbox
        $this->seedSandboxFile('resources/blueprints/collections/activities/activities.yaml');
        
        // Ensure stache is clear
        \Statamic\Facades\Stache::clear();
    }

    public function test_activities_blueprint_has_expected_fields()
    {
        $path = resource_path('blueprints/collections/activities/activities.yaml');

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
