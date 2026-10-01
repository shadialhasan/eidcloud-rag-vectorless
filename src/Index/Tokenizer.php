<?php

declare(strict_types=1);

namespace EidCloud\RagVectorless\Index;

/**
 * Text tokenizer and normalizer with English stop words filter and stemming helper.
 */
class Tokenizer
{
    /**
     * Common English stop words.
     * @var array<string, bool>
     */
    private const STOP_WORDS = [
        'a' => true, 'about' => true, 'above' => true, 'after' => true, 'again' => true,
        'against' => true, 'all' => true, 'am' => true, 'an' => true, 'and' => true,
        'any' => true, 'are' => true, 'as' => true, 'at' => true, 'be' => true,
        'because' => true, 'been' => true, 'before' => true, 'being' => true, 'below' => true,
        'between' => true, 'both' => true, 'but' => true, 'by' => true, 'can' => true,
        'did' => true, 'do' => true, 'does' => true, 'doing' => true, 'don' => true,
        'down' => true, 'during' => true, 'each' => true, 'few' => true, 'for' => true,
        'from' => true, 'further' => true, 'had' => true, 'has' => true, 'have' => true,
        'having' => true, 'he' => true, 'her' => true, 'here' => true, 'hers' => true,
        'herself' => true, 'him' => true, 'himself' => true, 'his' => true, 'how' => true,
        'i' => true, 'if' => true, 'in' => true, 'into' => true, 'is' => true,
        'it' => true, 'its' => true, 'itself' => true, 'just' => true, 'me' => true,
        'more' => true, 'most' => true, 'my' => true, 'myself' => true, 'no' => true,
        'nor' => true, 'not' => true, 'now' => true, 'of' => true, 'off' => true,
        'on' => true, 'once' => true, 'only' => true, 'or' => true, 'other' => true,
        'our' => true, 'ours' => true, 'ourselves' => true, 'out' => true, 'over' => true,
        'own' => true, 's' => true, 'same' => true, 'she' => true, 'should' => true,
        'so' => true, 'some' => true, 'such' => true, 'than' => true, 'that' => true,
        'the' => true, 'their' => true, 'theirs' => true, 'them' => true, 'themselves' => true,
        'then' => true, 'there' => true, 'these' => true, 'they' => true, 'this' => true,
        'those' => true, 'through' => true, 'to' => true, 'too' => true, 'under' => true,
        'until' => true, 'up' => true, 'very' => true, 'was' => true, 'we' => true,
        'were' => true, 'what' => true, 'when' => true, 'where' => true, 'which' => true,
        'while' => true, 'who' => true, 'whom' => true, 'why' => true, 'will' => true,
        'with' => true, 'you' => true, 'your' => true, 'yours' => true, 'yourself' => true,
    ];

    /**
     * Tokenize text into lowercased terms.
     *
     * @param string $text
     * @param bool $removeStopWords
     * @return array<string>
     */
    public static function tokenize(string $text, bool $removeStopWords = true): array
    {
        $normalized = mb_strtolower($text, 'UTF-8');
        // Replace non-alphanumeric characters with space
        $clean = preg_replace('/[^\p{L}\p{N}_\-]+/u', ' ', $normalized);
        if ($clean === null) {
            return [];
        }

        $parts = explode(' ', $clean);
        $tokens = [];

        foreach ($parts as $part) {
            $term = trim($part);
            if ($term === '' || mb_strlen($term) < 2) {
                continue;
            }

            if ($removeStopWords && isset(self::STOP_WORDS[$term])) {
                continue;
            }

            $tokens[] = self::stem($term);
        }

        return $tokens;
    }

    /**
     * Minimal Porter-like suffix stripper for basic morphological variants.
     */
    public static function stem(string $word): string
    {
        $len = strlen($word);
        if ($len <= 3) {
            return $word;
        }

        if (str_ends_with($word, 'ing') && $len > 5) {
            return substr($word, 0, -3);
        }
        if (str_ends_with($word, 'ies') && $len > 4) {
            return substr($word, 0, -3) . 'y';
        }
        if (str_ends_with($word, 'es') && $len > 4) {
            return substr($word, 0, -2);
        }
        if (str_ends_with($word, 's') && !str_ends_with($word, 'ss') && $len > 3) {
            return substr($word, 0, -1);
        }
        if (str_ends_with($word, 'ed') && $len > 4) {
            return substr($word, 0, -2);
        }
        if (str_ends_with($word, 'ly') && $len > 4) {
            return substr($word, 0, -2);
        }

        return $word;
    }
}
