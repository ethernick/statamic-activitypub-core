<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Tests\Listeners;

use Tests\TestCase;
use Statamic\Facades\Entry;
use Statamic\Facades\Collection;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Ethernick\ActivityPubCore\Tests\Concerns\BackupsFiles;
use PHPUnit\Framework\Attributes\Test;

class ActivityPubListenerHashtagTest extends TestCase
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
            "hashtags:\n  enabled: true\n  taxonomy: tags\n  field: tags\nnotes:\n  enabled: true\n  type: Note\n  federated: true\n"
        );

        // Ensure taxonomy exists
        if (!Taxonomy::findByHandle('tags')) {
            Taxonomy::make('tags')->save();
        }

        // Ensure collections exist
        if (!Collection::findByHandle('actors')) {
            Collection::make('actors')->save();
        }
        if (!Collection::findByHandle('notes')) {
            Collection::make('notes')->save();
        }

        // Clear Blink cache
        \Statamic\Facades\Blink::forget('activitypub-settings');

        // Clear existing terms to avoid interference
        Term::all()->each->delete();
    }

    protected function tearDown(): void
    {
        $this->restoreBackedUpFiles();

        // Reset ActivityPubListener static actor cache
        $reflection = new \ReflectionClass(\Ethernick\ActivityPubCore\Listeners\ActivityPubListener::class);
        $actorCache = $reflection->getProperty('actorCache');
        $actorCache->setAccessible(true);
        $actorCache->setValue(null, []);

        parent::tearDown();
    }

    #[Test]
    public function it_extracts_hashtags_from_content_and_creates_terms()
    {
        $actor = Entry::make()
            ->collection('actors')
            ->slug('test-actor')
            ->data(['activitypub_id' => 'https://test.com/users/test', 'is_internal' => true]);
        $actor->save();

        $note = Entry::make()
            ->collection('notes')
            ->slug('hashtag-note')
            ->data([
                'content' => 'Hello #statamic and #ActivityPub!',
                'actor' => [$actor->id()],
                'is_internal' => true,
            ]);

        // This will trigger ActivityPubListener::handleEntrySaving
        $note->save();

        // Verify terms were created
        $this->assertNotNull(Term::find('tags::statamic'));
        $this->assertNotNull(Term::find('tags::activitypub'));

        // Verify terms are assigned to the entry
        $tags = $note->get('tags');
        $this->assertContains('statamic', $tags);
        $this->assertContains('activitypub', $tags);
    }

    #[Test]
    public function it_ignores_non_hashtags()
    {
        $actor = Entry::make()
            ->collection('actors')
            ->slug('test-actor')
            ->data(['activitypub_id' => 'https://test.com/users/test', 'is_internal' => true]);
        $actor->save();

        $note = Entry::make()
            ->collection('notes')
            ->slug('non-hashtag-note')
            ->data([
                'content' => 'Color #FFF and ID #123 are not tags.',
                'actor' => [$actor->id()],
                'is_internal' => true,
            ]);
        $note->save();

        // Numeric IDs should not be created as terms, but hex-like words can be
        $this->assertNull(Term::find('tags::123'));
        $this->assertNotNull(Term::find('tags::fff'));
    }

    #[Test]
    public function it_generates_correct_activitypub_json_and_html()
    {
        $actor = Entry::make()
            ->collection('actors')
            ->slug('test-actor')
            ->data(['activitypub_id' => 'https://test.com/users/test', 'is_internal' => true]);
        $actor->save();

        $note = Entry::make()
            ->collection('notes')
            ->slug('json-test-note')
            ->data([
                'content' => 'Check out #statamic',
                'actor' => [$actor->id()],
                'is_internal' => true,
            ]);
        $note->save();

        $json = $note->get('activitypub_json');
        $this->assertIsString($json);

        $data = json_decode($json, true);

        // Verify tag array
        $this->assertArrayHasKey('tag', $data);
        $hashtags = array_filter($data['tag'], fn($t) => $t['type'] === 'Hashtag');
        $this->assertCount(1, $hashtags);
        $this->assertEquals('#statamic', reset($hashtags)['name']);

        // Verify linkified content
        $content = $data['content'];
        $this->assertStringContainsString('<a href="', $content);
        $this->assertStringContainsString('class="mention hashtag"', $content);
        $this->assertStringContainsString('#<span>statamic</span>', $content);
    }

    #[Test]
    public function it_processes_manual_tags_and_creates_terms()
    {
        $actor = Entry::make()
            ->collection('actors')
            ->slug('test-actor')
            ->data(['activitypub_id' => 'https://test.com/users/test', 'is_internal' => true]);
        $actor->save();

        $note = Entry::make()
            ->collection('notes')
            ->slug('manual-tags-note')
            ->data([
                'content' => 'This note has no inline hashtags.',
                'tags' => ['existing-tag', 'New Manual Tag', 'another, comma, tag'],
                'actor' => [$actor->id()],
                'is_internal' => true,
            ]);

        // This triggers ensureTermsExist
        $note->save();

        // Verify terms were created/normalized
        $this->assertNotNull(Term::find('tags::existing-tag'));
        $this->assertNotNull(Term::find('tags::new-manual-tag'));
        $this->assertNotNull(Term::find('tags::another-comma-tag'));

        // Verify tags on entry were converted to slugs
        $tags = $note->get('tags');
        $this->assertContains('existing-tag', $tags);
        $this->assertContains('new-manual-tag', $tags);
        $this->assertContains('another-comma-tag', $tags);

        // Verify they appear in JSON
        $json = $note->get('activitypub_json');
        $data = json_decode($json, true);

        $this->assertArrayHasKey('tag', $data);
        $hashtags = array_filter($data['tag'], fn($t) => $t['type'] === 'Hashtag');

        // existing-tag, new-manual-tag, another-comma-tag
        $this->assertCount(3, $hashtags);
        $names = array_map(fn($t) => $t['name'], $hashtags);
        $this->assertContains('#existing-tag', $names);
        $this->assertContains('#new-manual-tag', $names);
        $this->assertContains('#another-comma-tag', $names);
    }
}
