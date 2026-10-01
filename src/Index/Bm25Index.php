<?php

declare(strict_types=1);

namespace EidCloud\RagVectorless\Index;

use EidCloud\RagVectorless\Model\DocumentNode;
use EidCloud\RagVectorless\Storage\StorageInterface;

/**
 * Highly optimized in-memory BM25 lexical ranker with term-frequency saturation (k1)
 * and document-length normalization (b). Supports SQLite or JSON persistent storage.
 */
class Bm25Index
{
    /**
     * Term saturation parameter (default: 1.2 to 1.5).
     */
    private float $k1;

    /**
     * Document length normalization parameter (default: 0.75).
     */
    private float $b;

    /**
     * Map of nodeId => DocumentNode.
     * @var array<string, DocumentNode>
     */
    private array $nodes = [];

    /**
     * Inverted index: term => [nodeId => tf].
     * @var array<string, array<string, int>>
     */
    private array $invertedIndex = [];

    /**
     * Map of nodeId => length (in token count).
     * @var array<string, int>
     */
    private array $docLengths = [];

    /**
     * Average document length across the corpus.
     */
    private float $avgDocLength = 0.0;

    /**
     * Cached IDF scores: term => idf.
     * @var array<string, float>
     */
    private array $idfCache = [];

    private ?StorageInterface $storage;

    public function __construct(float $k1 = 1.2, float $b = 0.75, ?StorageInterface $storage = null)
    {
        $this->k1 = $k1;
        $this->b = $b;
        $this->storage = $storage;

        if ($this->storage !== null) {
            $this->loadFromStorage();
        }
    }

    /**
     * Add a node to the index.
     */
    public function addNode(DocumentNode $node): void
    {
        $this->nodes[$node->id] = $node;

        // Combine title, content, breadcrumbs, and tags for rich retrieval
        $textToIndex = $node->title . ' ' . $node->content . ' ' . implode(' ', $node->breadcrumbs);
        if (!empty($node->metadata['tags'])) {
            $tags = is_array($node->metadata['tags']) ? implode(' ', $node->metadata['tags']) : (string)$node->metadata['tags'];
            $textToIndex .= ' ' . $tags;
        }

        $tokens = Tokenizer::tokenize($textToIndex);
        $docLength = count($tokens);
        $this->docLengths[$node->id] = $docLength;

        // Calculate term frequencies for this document
        $termFrequencies = [];
        foreach ($tokens as $token) {
            $termFrequencies[$token] = ($termFrequencies[$token] ?? 0) + 1;
        }

        // Add to inverted index
        foreach ($termFrequencies as $term => $tf) {
            if (!isset($this->invertedIndex[$term])) {
                $this->invertedIndex[$term] = [];
            }
            $this->invertedIndex[$term][$node->id] = $tf;
        }

        // Invalidate IDF cache
        $this->idfCache = [];
        $this->recalculateStats();
    }

    /**
     * Add multiple nodes in batch.
     *
     * @param array<DocumentNode> $nodes
     */
    public function addNodes(array $nodes): void
    {
        foreach ($nodes as $node) {
            $this->addNode($node);
        }
        $this->recalculateStats();
    }

    /**
     * Recalculate average document length.
     */
    private function recalculateStats(): void
    {
        $totalDocs = count($this->nodes);
        if ($totalDocs === 0) {
            $this->avgDocLength = 0.0;
            return;
        }

        $totalLength = array_sum($this->docLengths);
        $this->avgDocLength = (float)$totalLength / $totalDocs;
    }

    /**
     * Calculate Robertson-Spärck Jones Inverse Document Frequency (IDF) with floor smoothing:
     * IDF(q) = ln( (N - n(q) + 0.5) / (n(q) + 0.5) + 1 )
     */
    public function getIdf(string $term): float
    {
        if (isset($this->idfCache[$term])) {
            return $this->idfCache[$term];
        }

        $N = count($this->nodes);
        $nq = isset($this->invertedIndex[$term]) ? count($this->invertedIndex[$term]) : 0;

        if ($N === 0 || $nq === 0) {
            return 0.0;
        }

        $idf = log(($N - $nq + 0.5) / ($nq + 0.5) + 1.0);
        $this->idfCache[$term] = max(0.0001, $idf);

        return $this->idfCache[$term];
    }

    /**
     * Search index using Okapi BM25 scoring algorithm.
     *
     * @param string $query Query string
     * @param int $topK Number of matches to return
     * @param array<string, mixed> $filters Metadata filters
     * @return array<array{node: DocumentNode, score: float, matched_terms: array<string, int>}>
     */
    public function search(string $query, int $topK = 5, array $filters = []): array
    {
        $queryTokens = Tokenizer::tokenize($query);
        if (empty($queryTokens) || empty($this->nodes)) {
            return [];
        }

        $scores = [];
        $matchedTerms = [];

        // For each query term, accumulate score across documents in posting list
        foreach ($queryTokens as $term) {
            if (!isset($this->invertedIndex[$term])) {
                continue;
            }

            $idf = $this->getIdf($term);
            $postings = $this->invertedIndex[$term];

            foreach ($postings as $nodeId => $tf) {
                // Check metadata filters early to save score calculations
                if (!empty($filters) && !$this->matchesFilters($this->nodes[$nodeId], $filters)) {
                    continue;
                }

                $docLength = $this->docLengths[$nodeId] ?? 1;
                $lenNorm = 1.0 - $this->b + $this->b * ($docLength / ($this->avgDocLength ?: 1.0));
                
                // BM25 term saturation: (tf * (k1 + 1)) / (tf + k1 * lenNorm)
                $tfComponent = ($tf * ($this->k1 + 1.0)) / ($tf + $this->k1 * $lenNorm);
                $termScore = $idf * $tfComponent;

                // Boost heading and title matches
                $node = $this->nodes[$nodeId];
                if (stripos($node->title, $term) !== false) {
                    $termScore *= 1.35; // 35% boost for heading/title relevance
                }

                $scores[$nodeId] = ($scores[$nodeId] ?? 0.0) + $termScore;
                $matchedTerms[$nodeId][$term] = $tf;
            }
        }

        if (empty($scores)) {
            return [];
        }

        arsort($scores);

        $results = [];
        $count = 0;
        foreach ($scores as $nodeId => $score) {
            $results[] = [
                'node' => $this->nodes[$nodeId],
                'score' => $score,
                'matched_terms' => $matchedTerms[$nodeId] ?? [],
            ];
            $count++;
            if ($count >= $topK) {
                break;
            }
        }

        return $results;
    }

    /**
     * Check if a document node satisfies metadata filter constraints.
     *
     * @param DocumentNode $node
     * @param array<string, mixed> $filters
     * @return bool
     */
    public function matchesFilters(DocumentNode $node, array $filters): bool
    {
        $meta = $node->metadata;

        foreach ($filters as $key => $filterVal) {
            if ($key === 'level') {
                if ($node->level !== $filterVal) {
                    return false;
                }
                continue;
            }

            if ($key === 'document_path') {
                if ($node->documentPath !== $filterVal && !str_ends_with($node->documentPath, (string)$filterVal)) {
                    return false;
                }
                continue;
            }

            if ($key === 'tag' || $key === 'tags') {
                $nodeTags = $meta['tags'] ?? [];
                if (is_string($nodeTags)) {
                    $nodeTags = array_map('trim', explode(',', $nodeTags));
                }
                if (is_array($filterVal)) {
                    if (empty(array_intersect($filterVal, (array)$nodeTags))) {
                        return false;
                    }
                } else {
                    if (!in_array($filterVal, (array)$nodeTags, true)) {
                        return false;
                    }
                }
                continue;
            }

            if ($key === 'date_from' && isset($meta['date'])) {
                if (strtotime((string)$meta['date']) < strtotime((string)$filterVal)) {
                    return false;
                }
                continue;
            }

            if ($key === 'date_to' && isset($meta['date'])) {
                if (strtotime((string)$meta['date']) > strtotime((string)$filterVal)) {
                    return false;
                }
                continue;
            }

            // General key-value match
            if (isset($meta[$key])) {
                if ((string)$meta[$key] !== (string)$filterVal) {
                    return false;
                }
            } else {
                return false;
            }
        }

        return true;
    }

    /**
     * Persist current index state to storage.
     */
    public function persist(): void
    {
        if ($this->storage === null) {
            return;
        }

        $stats = [
            'total_nodes' => count($this->nodes),
            'avg_doc_length' => $this->avgDocLength,
            'k1' => $this->k1,
            'b' => $this->b,
            'indexed_at' => date('Y-m-d H:i:s'),
        ];

        $this->storage->save($this->nodes, $this->invertedIndex, $this->docLengths, $stats);
    }

    /**
     * Load state from storage.
     */
    public function loadFromStorage(): bool
    {
        if ($this->storage === null) {
            return false;
        }

        $loaded = $this->storage->load();
        if ($loaded === null) {
            return false;
        }

        $this->nodes = $loaded['nodes'];
        $this->invertedIndex = $loaded['invertedIndex'];
        $this->docLengths = $loaded['docLengths'];
        $this->avgDocLength = (float)($loaded['stats']['avg_doc_length'] ?? 0.0);
        $this->idfCache = [];

        return true;
    }

    /**
     * Return all indexed nodes.
     *
     * @return array<string, DocumentNode>
     */
    public function getNodes(): array
    {
        return $this->nodes;
    }

    public function getNodeCount(): int
    {
        return count($this->nodes);
    }

    public function getTermCount(): int
    {
        return count($this->invertedIndex);
    }

    public function getAvgDocLength(): float
    {
        return $this->avgDocLength;
    }

    public function clear(): void
    {
        $this->nodes = [];
        $this->invertedIndex = [];
        $this->docLengths = [];
        $this->avgDocLength = 0.0;
        $this->idfCache = [];

        if ($this->storage !== null) {
            $this->storage->clear();
        }
    }
}
