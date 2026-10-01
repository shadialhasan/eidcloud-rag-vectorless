<?php

declare(strict_types=1);

namespace EidCloud\RagVectorless\Retriever;

use EidCloud\RagVectorless\Index\Bm25Index;
use EidCloud\RagVectorless\Model\DocumentNode;
use EidCloud\RagVectorless\Model\SearchResult;

/**
 * Context retriever and prompt/citation assembler for LLM augmentation.
 */
class ContextRetriever
{
    public function __construct(private readonly Bm25Index $index)
    {
    }

    /**
     * Retrieve ranked SearchResult objects for a user query.
     *
     * @param string $query
     * @param int $topK
     * @param array<string, mixed> $filters
     * @return array<SearchResult>
     */
    public function retrieve(string $query, int $topK = 5, array $filters = []): array
    {
        $rawResults = $this->index->search($query, $topK, $filters);
        $results = [];

        foreach ($rawResults as $item) {
            $results[] = new SearchResult(
                node: $item['node'],
                score: $item['score'],
                matchedTerms: $item['matched_terms']
            );
        }

        return $results;
    }

    /**
     * Build formatted context block specifically structured for LLM prompts.
     *
     * @param string $query
     * @param int $topK
     * @param array<string, mixed> $filters
     * @param int $maxCharacters Maximum context length budget
     * @return array{
     *     context_text: string,
     *     citations: array<string>,
     *     results: array<SearchResult>,
     *     total_characters: int
     * }
     */
    public function assembleContext(
        string $query,
        int $topK = 5,
        array $filters = [],
        int $maxCharacters = 8000
    ): array {
        $results = $this->retrieve($query, $topK, $filters);
        $citations = [];
        $contextChunks = [];
        $totalChars = 0;

        foreach ($results as $idx => $result) {
            $node = $result->node;
            $citation = sprintf('[Source %d] %s', $idx + 1, $result->getCitation());
            $citations[] = $citation;

            $chunkHeader = sprintf(
                "--- SOURCE %d (%s) ---\nFile: %s (Lines %d-%d)\nTopology: %s\n",
                $idx + 1,
                strtoupper($node->level),
                $node->documentPath,
                $node->startLine,
                $node->endLine,
                implode(' > ', $node->breadcrumbs)
            );

            $chunkBody = $node->content;
            $fullChunk = $chunkHeader . $chunkBody . "\n\n";

            if ($totalChars + strlen($fullChunk) > $maxCharacters && !empty($contextChunks)) {
                break;
            }

            $contextChunks[] = $fullChunk;
            $totalChars += strlen($fullChunk);
        }

        return [
            'context_text' => implode('', $contextChunks),
            'citations' => $citations,
            'results' => $results,
            'total_characters' => $totalChars,
        ];
    }
}
