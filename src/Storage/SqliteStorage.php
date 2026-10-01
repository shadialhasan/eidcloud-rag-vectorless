<?php

declare(strict_types=1);

namespace EidCloud\RagVectorless\Storage;

use EidCloud\RagVectorless\Model\DocumentNode;
use PDO;

class SqliteStorage implements StorageInterface
{
    private PDO $pdo;

    public function __construct(private readonly string $dbPath)
    {
        $dir = dirname($this->dbPath);
        if ($dir !== '' && !is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $dir));
        }

        $this->pdo = new PDO("sqlite:{$this->dbPath}");
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->initSchema();
    }

    private function initSchema(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS metadata (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS nodes (
                id TEXT PRIMARY KEY,
                document_path TEXT NOT NULL,
                level TEXT NOT NULL,
                title TEXT NOT NULL,
                content TEXT NOT NULL,
                start_line INTEGER NOT NULL,
                end_line INTEGER NOT NULL,
                breadcrumbs TEXT NOT NULL,
                metadata TEXT NOT NULL,
                parent_id TEXT,
                doc_length INTEGER NOT NULL
            );
            CREATE TABLE IF NOT EXISTS inverted_index (
                term TEXT NOT NULL,
                node_id TEXT NOT NULL,
                tf INTEGER NOT NULL,
                PRIMARY KEY (term, node_id)
            );
            CREATE INDEX IF NOT EXISTS idx_term ON inverted_index(term);
        ');
    }

    public function save(array $nodes, array $invertedIndex, array $docLengths, array $stats): void
    {
        $this->pdo->beginTransaction();

        try {
            $this->pdo->exec('DELETE FROM metadata');
            $this->pdo->exec('DELETE FROM nodes');
            $this->pdo->exec('DELETE FROM inverted_index');

            // Save stats
            $stmtMeta = $this->pdo->prepare('INSERT INTO metadata (key, value) VALUES (:key, :value)');
            foreach ($stats as $k => $v) {
                $stmtMeta->execute([
                    ':key' => $k,
                    ':value' => (string)$v,
                ]);
            }

            // Save nodes
            $stmtNode = $this->pdo->prepare('
                INSERT INTO nodes (id, document_path, level, title, content, start_line, end_line, breadcrumbs, metadata, parent_id, doc_length)
                VALUES (:id, :document_path, :level, :title, :content, :start_line, :end_line, :breadcrumbs, :metadata, :parent_id, :doc_length)
            ');

            foreach ($nodes as $id => $node) {
                $stmtNode->execute([
                    ':id' => $node->id,
                    ':document_path' => $node->documentPath,
                    ':level' => $node->level,
                    ':title' => $node->title,
                    ':content' => $node->content,
                    ':start_line' => $node->startLine,
                    ':end_line' => $node->endLine,
                    ':breadcrumbs' => json_encode($node->breadcrumbs, JSON_UNESCAPED_UNICODE),
                    ':metadata' => json_encode($node->metadata, JSON_UNESCAPED_UNICODE),
                    ':parent_id' => $node->parentId,
                    ':doc_length' => $docLengths[$id] ?? 0,
                ]);
            }

            // Save inverted index
            $stmtIndex = $this->pdo->prepare('
                INSERT INTO inverted_index (term, node_id, tf)
                VALUES (:term, :node_id, :tf)
            ');

            foreach ($invertedIndex as $term => $postings) {
                foreach ($postings as $nodeId => $tf) {
                    $stmtIndex->execute([
                        ':term' => $term,
                        ':node_id' => $nodeId,
                        ':tf' => $tf,
                    ]);
                }
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function load(): ?array
    {
        // Check if database has nodes
        $check = $this->pdo->query('SELECT COUNT(*) FROM nodes')->fetchColumn();
        if ($check === 0) {
            return null;
        }

        // Load stats
        $statsRows = $this->pdo->query('SELECT key, value FROM metadata')->fetchAll(PDO::FETCH_KEY_PAIR);
        $stats = [];
        foreach ($statsRows as $k => $v) {
            $stats[$k] = is_numeric($v) ? (float)$v : $v;
        }

        // Load nodes & lengths
        $nodeRows = $this->pdo->query('SELECT * FROM nodes')->fetchAll(PDO::FETCH_ASSOC);
        $nodes = [];
        $docLengths = [];

        foreach ($nodeRows as $row) {
            $node = new DocumentNode(
                id: $row['id'],
                documentPath: $row['document_path'],
                level: $row['level'],
                title: $row['title'],
                content: $row['content'],
                startLine: (int)$row['start_line'],
                endLine: (int)$row['end_line'],
                breadcrumbs: json_decode($row['breadcrumbs'], true) ?: [],
                metadata: json_decode($row['metadata'], true) ?: [],
                parentId: $row['parent_id']
            );
            $nodes[$node->id] = $node;
            $docLengths[$node->id] = (int)$row['doc_length'];
        }

        // Load inverted index
        $indexRows = $this->pdo->query('SELECT term, node_id, tf FROM inverted_index')->fetchAll(PDO::FETCH_ASSOC);
        $invertedIndex = [];
        foreach ($indexRows as $row) {
            $invertedIndex[$row['term']][$row['node_id']] = (int)$row['tf'];
        }

        return [
            'nodes' => $nodes,
            'invertedIndex' => $invertedIndex,
            'docLengths' => $docLengths,
            'stats' => $stats,
        ];
    }

    public function clear(): void
    {
        $this->pdo->exec('DELETE FROM metadata; DELETE FROM nodes; DELETE FROM inverted_index;');
    }
}
