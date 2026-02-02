<?php

namespace Ethernick\ActivityPubCore\Console\Commands\Migrations;

use Illuminate\Console\Command;
use Statamic\Facades\Entry;
use Illuminate\Support\Facades\Log;

class PopulateMentionedUrls extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'activitypub:migrate:mentioned-urls';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Populate mentioned_urls field for existing notes and polls based on ActivityPub tags or content regex.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info("Starting migration of mentioned_urls...");

        $entries = Entry::query()
            ->whereIn('collection', ['notes', 'polls'])
            ->get();

        $count = 0;
        $total = $entries->count();

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($entries as $entry) {
            $mentioned = [];
            $source = 'none';

            // 1. Try Parse AP JSON Tags
            $apJson = $entry->get('activitypub_json');
            if ($apJson) {
                $data = json_decode($apJson, true);
                if (isset($data['tag']) && is_array($data['tag'])) {
                    foreach ($data['tag'] as $tag) {
                        if (($tag['type'] ?? '') === 'Mention' && isset($tag['href'])) {
                            $mentioned[] = $tag['href'];
                        }
                    }
                    if (!empty($mentioned)) {
                        $source = 'tags';
                    }
                }
            }

            // 2. Fallback: Content Parsing (Regex / DOM)
            // If no strict tags found (e.g. local note), parse HTML content for microformats
            if (empty($mentioned)) {
                $content = $entry->get('content');
                if ($content) {
                    // Match <a ... class="... mention ..." ... href="...">
                    // Simple regex for hrefs in anchors with class mention
                    // <a href="https://ethernick.com/@nick" class="u-url mention">

                    // Regex explain: 
                    // <a [^>]*class="[^"]*mention[^"]*"[^>]*href="([^"]+)"
                    // Note: attributes order varies, so we check stricter.

                    // Use simple regex for all links, then filter? Or strict mention class?
                    // Let's look for class="...mention..." and capture href.

                    // Pattern: href="([^"]+)" inside an <a> tag that also has class=".*mention.*"
                    // Doing this with regex is fragile but effective for standard generated HTML.

                    preg_match_all('/<a[^>]+class="[^"]*mention[^"]*"[^>]+href="([^"]+)"/i', $content, $matches);
                    if (!empty($matches[1])) {
                        $mentioned = array_unique($matches[1]);
                        $source = 'content_regex';
                    }
                }
            }

            if (!empty($mentioned)) {
                $entry->set('mentioned_urls', array_values(array_unique($mentioned)));
                $entry->save();
                $count++;
            } else {
                // Ensure empty array is set if previously null?
                // Or just leave null? Scope query usually handles null.
                // But safer to have empty array if we rely on whereJsonContains?
                // Actually Statamic flat file: if key missing, it's null.
                // Let's set it to empty array to be explicit it was scanned?
                // Or save space?
                // Let's save space, if empty, don't set it (remove it).
                $entry->remove('mentioned_urls');
                // Only save if dirty? remove() makes it dirty.
                // But wait, if we remove it, the strict query `where('mentioned_urls', 'contains', ...)` might fail?
                // No, Stache query handles missing keys gracefully usually.
                $entry->save();
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Migration complete. Updated $count entries out of $total.");

        return 0;
    }
}
