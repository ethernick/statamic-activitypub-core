<?php

declare(strict_types=1);

namespace Ethernick\ActivityPubCore\Search;

use Statamic\Entries\Entry;

class SearchTokenExtractor
{
    /**
     * Stop words to omit from token index to keep index size compact and avoid high-frequency noise.
     */
    protected const STOP_WORDS = [
        'a', 'about', 'above', 'after', 'again', 'against', 'all', 'am', 'an', 'and', 'any', 'are',
        'as', 'at', 'be', 'because', 'been', 'before', 'being', 'below', 'between', 'both', 'but',
        'by', 'can', 'could', 'did', 'do', 'does', 'doing', 'down', 'during', 'each', 'few', 'for',
        'from', 'further', 'had', 'has', 'have', 'having', 'he', 'her', 'here', 'hers', 'herself',
        'him', 'himself', 'his', 'how', 'i', 'if', 'in', 'into', 'is', 'it', 'its', 'itself',
        'just', 'me', 'more', 'most', 'my', 'myself', 'no', 'nor', 'not', 'now', 'of', 'off',
        'on', 'once', 'only', 'or', 'other', 'our', 'ours', 'ourselves', 'out', 'over', 'own',
        'same', 'she', 'should', 'so', 'some', 'such', 'than', 'that', 'the', 'their', 'theirs',
        'them', 'themselves', 'then', 'there', 'these', 'they', 'this', 'those', 'through', 'to',
        'too', 'under', 'until', 'up', 'very', 'was', 'we', 'were', 'what', 'when', 'where',
        'which', 'while', 'who', 'whom', 'why', 'with', 'would', 'you', 'your', 'yours', 'yourself',
    ];

    /**
     * Extract normalized search tokens from a string.
     *
     * @param string $text
     * @param int $minWordLength
     * @return array<string>
     */
    public static function extract(string $text, int $minWordLength = 2): array
    {
        if (trim($text) === '') {
            return [];
        }

        // Strip HTML tags and markdown formatting
        $clean = strip_tags($text);
        // Replace punctuation and symbols with spaces, preserving letters and numbers
        $clean = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $clean) ?? '';
        $clean = mb_strtolower($clean, 'UTF-8');

        // Split into words
        $words = preg_split('/\s+/u', $clean, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $stopWordsMap = array_flip(self::STOP_WORDS);
        $tokens = [];

        foreach ($words as $word) {
            $len = mb_strlen($word, 'UTF-8');
            if ($len < $minWordLength || $len > 40) {
                continue;
            }

            if (isset($stopWordsMap[$word])) {
                continue;
            }

            $tokens[$word] = true;
        }

        return array_keys($tokens);
    }

    /**
     * Extract all searchable tokens from an Entry's text fields.
     *
     * @param Entry $entry
     * @return array<string>
     */
    public static function extractFromEntry(Entry $entry): array
    {
        $fields = [
            (string) ($entry->get('title') ?? ''),
            (string) ($entry->get('content') ?? ''),
            (string) ($entry->get('summary') ?? ''),
            (string) ($entry->slug() ?? ''),
        ];

        // Include any tags / hashtags
        $tags = $entry->get('tags', []);
        if (is_array($tags)) {
            $fields = array_merge($fields, array_map('strval', $tags));
        }

        $combined = implode(' ', array_filter($fields));

        return self::extract($combined);
    }
}
