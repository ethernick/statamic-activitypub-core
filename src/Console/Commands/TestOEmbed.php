<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Console\Commands;

use Illuminate\Console\Command;
use Ethernick\ActivityPubCore\Services\OEmbed;
use Ethernick\ActivityPubCore\Services\LinkPreview;

class TestOEmbed extends Command
{
    protected $signature = 'activitypub:test-oembed {url : The URL to test}';

    protected $description = 'Test OEmbed resolution for a specific URL';

    public function handle()
    {
        $url = $this->argument('url');

        $this->info("Testing OEmbed for: {$url}");
        $this->newLine();

        // Wrap in <a> tag like it would be in content
        $htmlContent = '<p><a href="' . $url . '">' . $url . '</a></p>';

        $this->info('Testing OEmbed...');
        try {
            $oembedData = OEmbed::resolve($htmlContent);

            if ($oembedData) {
                $this->info('✓ OEmbed succeeded!');
                $this->table(
                    ['Field', 'Value'],
                    [
                        ['Type', $oembedData['type'] ?? 'N/A'],
                        ['Provider', $oembedData['provider'] ?? 'N/A'],
                        ['HTML Length', isset($oembedData['html']) ? strlen($oembedData['html']) : 0],
                    ]
                );

                if (isset($oembedData['html'])) {
                    $this->newLine();
                    $this->line('HTML Preview (first 200 chars):');
                    $this->line(substr($oembedData['html'], 0, 200));
                }
            } else {
                $this->warn('✗ OEmbed returned null (no embed available)');
            }
        } catch (\Exception $e) {
            $this->error('✗ OEmbed threw exception: ' . $e->getMessage());
            $this->line('Stack trace:');
            $this->line($e->getTraceAsString());
        }

        $this->newLine();
        $this->info('Testing Link Preview as fallback...');
        try {
            $previewUrl = LinkPreview::extractUrl($htmlContent);
            if ($previewUrl) {
                $this->info("Extracted URL: {$previewUrl}");
                $previewData = LinkPreview::fetch($previewUrl);

                if ($previewData) {
                    $this->info('✓ Link Preview succeeded!');
                    $this->table(
                        ['Field', 'Value'],
                        [
                            ['Title', $previewData['title'] ?? 'N/A'],
                            ['Description', substr($previewData['description'] ?? 'N/A', 0, 100)],
                            ['Image', $previewData['image'] ?? 'N/A'],
                        ]
                    );
                } else {
                    $this->warn('✗ Link Preview returned null');
                }
            } else {
                $this->warn('✗ Could not extract URL from content');
            }
        } catch (\Exception $e) {
            $this->error('✗ Link Preview threw exception: ' . $e->getMessage());
        }

        return 0;
    }
}
