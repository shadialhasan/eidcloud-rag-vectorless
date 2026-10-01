<?php

declare(strict_types=1);

namespace EidCloud\RagVectorless;

use EidCloud\RagVectorless\Chunker\HierarchicalChunker;
use EidCloud\RagVectorless\Index\Bm25Index;
use EidCloud\RagVectorless\Model\DocumentNode;
use EidCloud\RagVectorless\Model\SearchResult;
use EidCloud\RagVectorless\Retriever\ContextRetriever;
use EidCloud\RagVectorless\Storage\JsonStorage;
use EidCloud\RagVectorless\Storage\SqliteStorage;
use EidCloud\RagVectorless\Storage\StorageInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

/**
 * Main entry point for EidCloud Vectorless RAG Engine.
 * Provides indexing, querying, and context augmentation with zero vector database overhead.
 */
class RagEngine
{
    private HierarchicalChunker $chunker;
    private Bm25Index $index;
    private ContextRetriever $retriever;
    private ?StorageInterface $storage;

    public function __construct(
        ?StorageInterface $storage = null,
        float $k1 = 1.2,
        float $b = 0.75,
        int $minChunkLength = 10,
        int $maxChunkLength = 1500
    ) {
        $this->storage = $storage;
        $this->chunker = new HierarchicalChunker($minChunkLength, $maxChunkLength);
        $this->index = new Bm25Index($k1, $b, $this->storage);
        $this->retriever = new ContextRetriever($this->index);
    }

    /**
     * Create an instance backed by a SQLite database.
     */
    public static function createWithSqlite(string $dbPath, float $k1 = 1.2, float $b = 0.75): self
    {
        $storage = new SqliteStorage($dbPath);
        return new self($storage, $k1, $b);
    }

    /**
     * Create an instance backed by a JSON file.
     */
    public static function createWithJson(string $jsonPath, float $k1 = 1.2, float $b = 0.75): self
    {
        $storage = new JsonStorage($jsonPath);
        return new self($storage, $k1, $b);
    }

    /**
     * Create an in-memory instance.
     */
    public static function createInMemory(float $k1 = 1.2, float $b = 0.75): self
    {
        return new self(null, $k1, $b);
    }

    /**
     * Index a single file.
     *
     * @param string $filePath
     * @param array<string, mixed> $extraMetadata
     * @return array<DocumentNode> Indexed nodes
     */
    public function indexFile(string $filePath, array $extraMetadata = []): array
    {
        $nodes = $this->chunker->chunkFile($filePath, $extraMetadata);
        $this->index->addNodes($nodes);
        return $nodes;
    }

    /**
     * Index raw text or markdown directly.
     *
     * @param string $text
     * @param string $documentPath Virtual document path
     * @param array<string, mixed> $metadata
     * @return array<DocumentNode>
     */
    public function indexText(string $text, string $documentPath = 'memory://doc', array $metadata = []): array
    {
        $nodes = $this->chunker->chunkText($text, $documentPath, $metadata);
        $this->index->addNodes($nodes);
        return $nodes;
    }

    /**
     * Index a directory of files recursively.
     *
     * @param string $directory Path to directory
     * @param array<string> $extensions Supported extensions (default: md, txt, php, json, yaml, yml, rst)
     * @return array{files_indexed: int, nodes_created: int, duration_ms: float}
     */
    public function indexDirectory(string $directory, array $extensions = ['md', 'txt', 'php', 'json', 'yaml', 'yml', 'rst']): array
    {
        if (!is_dir($directory)) {
            throw new \InvalidArgumentException("Directory not found: {$directory}");
        }

        $startTime = microtime(true);
        $extPattern = '/\.(' . implode('|', $extensions) . ')$/i';

        $dirIterator = new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator = new RecursiveIteratorIterator($dirIterator);
        $regex = new RegexIterator($iterator, $extPattern, RegexIterator::MATCH);

        $fileCount = 0;
        $nodeCount = 0;

        foreach ($regex as $file) {
            $filePath = $file->getRealPath();
            if ($filePath === false || !is_file($filePath)) {
                continue;
            }

            $nodes = $this->indexFile($filePath);
            $fileCount++;
            $nodeCount += count($nodes);
        }

        // Save index state if storage configured
        if ($this->storage !== null) {
            $this->index->persist();
        }

        $durationMs = (microtime(true) - $startTime) * 1000;

        return [
            'files_indexed' => $fileCount,
            'nodes_created' => $nodeCount,
            'duration_ms' => round($durationMs, 2),
        ];
    }

    /**
     * Query the index and retrieve ranked results.
     *
     * @param string $query
     * @param int $topK
     * @param array<string, mixed> $filters
     * @return array<SearchResult>
     */
    public function search(string $query, int $topK = 5, array $filters = []): array
    {
        return $this->retriever->retrieve($query, $topK, $filters);
    }

    /**
     * Query and assemble full context with citations for LLM prompt injection.
     *
     * @param string $query
     * @param int $topK
     * @param array<string, mixed> $filters
     * @param int $maxCharacters
     * @return array{
     *     context_text: string,
     *     citations: array<string>,
     *     results: array<SearchResult>,
     *     total_characters: int
     * }
     */
    public function ask(string $query, int $topK = 5, array $filters = [], int $maxCharacters = 8000): array
    {
        return $this->retriever->assembleContext($query, $topK, $filters, $maxCharacters);
    }

    /**
     * Persist current state.
     */
    public function persist(): void
    {
        $this->index->persist();
    }

    public function getIndex(): Bm25Index
    {
        return $this->index;
    }

    public function getRetriever(): ContextRetriever
    {
        return $this->retriever;
    }

    public function getChunker(): HierarchicalChunker
    {
        return $this->chunker;
    }
}
