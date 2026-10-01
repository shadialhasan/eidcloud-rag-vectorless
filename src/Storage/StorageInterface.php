<?php

declare(strict_types=1);

namespace EidCloud\RagVectorless\Storage;

use EidCloud\RagVectorless\Model\DocumentNode;

interface StorageInterface
{
    /**
     * Save nodes and inverted index to persistent storage.
     *
     * @param array<string, DocumentNode> $nodes
     * @param array<string, array<string, int>> $invertedIndex term => [nodeId => tf]
     * @param array<string, int> $docLengths nodeId => length
     * @param array<string, mixed> $stats
     */
    public function save(array $nodes, array $invertedIndex, array $docLengths, array $stats): void;

    /**
     * Load index from storage.
     *
     * @return array{
     *     nodes: array<string, DocumentNode>,
     *     invertedIndex: array<string, array<string, int>>,
     *     docLengths: array<string, int>,
     *     stats: array<string, mixed>
     * }|null
     */
    public function load(): ?array;

    /**
     * Clear storage.
     */
    public function clear(): void;
}
