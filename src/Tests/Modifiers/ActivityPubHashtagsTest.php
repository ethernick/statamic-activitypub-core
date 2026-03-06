<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Tests\Modifiers;

use Tests\TestCase;
use Statamic\Facades\Term;
use Statamic\Facades\Taxonomy;
use Ethernick\ActivityPubCore\Modifiers\ActivityPubHashtags;
use Ethernick\ActivityPubCore\Tests\Concerns\BackupsFiles;
use PHPUnit\Framework\Attributes\Test;

class ActivityPubHashtagsTest extends TestCase
{
    use BackupsFiles;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupFiles([]);

        // Ensure settings exist
        if (!file_exists(resource_path('settings'))) {
            mkdir(resource_path('settings'), 0755, true);
        }
        file_put_contents(
            resource_path('settings/activitypub.yaml'),
            "hashtags:\n  enabled: true\n  taxonomy: tags\n  field: tags\n"
        );

        // Ensure taxonomy exists
        if (!Taxonomy::findByHandle('tags')) {
            Taxonomy::make('tags')->save();
        }

        // Clear Blink cache
        \Statamic\Facades\Blink::forget('activitypub-settings');
    }

    protected function tearDown(): void
    {
        $this->restoreBackedUpFiles();
        parent::tearDown();
    }

    #[Test]
    public function it_linkifies_hashtags()
    {
        Term::make()
            ->taxonomy('tags')
            ->slug('statamic')
            ->data(['title' => 'Statamic'])
            ->save();

        $modifier = new ActivityPubHashtags();
        $content = 'Hello #statamic and #unknown';

        $result = $modifier->index($content, [], []);

        $this->assertStringContainsString('<a href="/tags/statamic" class="hashtag" rel="tag">#statamic</a>', $result);
        $this->assertStringContainsString('<a href="/tags/unknown" class="hashtag" rel="tag">#unknown</a>', $result);
    }

    #[Test]
    public function it_ignores_hashtags_when_disabled()
    {
        file_put_contents(
            resource_path('settings/activitypub.yaml'),
            "hashtags:\n  enabled: false\n"
        );
        \Statamic\Facades\Blink::forget('activitypub-settings');

        $modifier = new ActivityPubHashtags();
        $content = 'Hello #statamic';

        $result = $modifier->index($content, [], []);

        $this->assertEquals('Hello #statamic', $result);
    }

    #[Test]
    public function it_ignores_hashtags_inside_html_tags()
    {
        Term::make()
            ->taxonomy('tags')
            ->slug('statamic')
            ->data(['title' => 'Statamic'])
            ->save();

        $modifier = new ActivityPubHashtags();
        $content = '<img alt="#statamic"> #statamic';

        $result = $modifier->index($content, [], []);

        $this->assertStringContainsString('<img alt="#statamic">', $result);
        $this->assertStringContainsString('<a href="/tags/statamic"', $result);
    }
}
