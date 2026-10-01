<?php

declare(strict_types=1);

namespace EidCloud\RagVectorless\Model;

/**
 * Represents a matched search result containing node, score, and citation.
 */
class SearchResult
{
    /**
     * @param DocumentNode $node
     * @param float $score BM25 relevance score
     * @param array<string, int> $matchedTerms Frequency of matched query terms in this node
     */
    public function __construct(
        public readonly DocumentNode $node,
        public readonly float $score,
        public readonly array $matchedTerms = []
    ) {
    }

    /**
     * Get exact citation.
     */
    public function getCitation(): string
    {
        return $this->node->getCitation();
    }

    /**
     * Convert result to array for JSON serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'score' => round($this->score, 4),
            'citation' => $this->getCitation(),
            'node' => $this->node->toArray(),
            'matched_terms' => $this->matchedTerms,
        ];
    }
}
