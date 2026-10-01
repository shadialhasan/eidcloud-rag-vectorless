<?php

declare(strict_types=1);

namespace EidCloud\RagVectorless\Storage;

use EidCloud\RagVectorless\Model\DocumentNode;

class JsonStorage implements StorageInterface
{
    public function __construct(private readonly string $filePath)
    {
    }

    public function save(array $nodes, array $invertedIndex, array $docLengths, array $stats): void
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $dir));
        }

        $serializedNodes = [];
        foreach ($nodes as $id => $node) {
            $serializedNodes[$id] = $node->toArray();
        }

        $data = [
            'nodes' => $serializedNodes,
            'inverted_index' => $invertedIndex,
            'doc_lengths' => $docLengths,
            'stats' => $stats,
        ];

        file_put_contents($this->filePath, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function load(): ?array
    {
        if (!file_exists($this->filePath) || !is_readable($this->filePath)) {
            return null;
        }

        $raw = file_get_contents($this->filePath);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['nodes'], $data['inverted_index'], $data['doc_lengths'], $data['stats'])) {
            return null;
        }

        $nodes = [];
        foreach ($data['nodes'] as $id => $nodeData) {
            $nodes[$id] = DocumentNode::fromArray($nodeData);
        }

        return [
            'nodes' => $nodes,
            'invertedIndex' => $data['inverted_index'],
            'docLengths' => $data['doc_lengths'],
            'stats' => $data['stats'],
        ];
    }

    public function clear(): void
    {
        if (file_exists($this->filePath)) {
            unlink($this->filePath);
        }
    }
}
