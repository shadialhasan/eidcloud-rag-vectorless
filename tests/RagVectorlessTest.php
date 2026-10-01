<?php

declare(strict_types=1);

namespace EidCloud\RagVectorless\Tests;

// Autoloader
spl_autoload_register(function (string $class) {
    $prefix = 'EidCloud\\RagVectorless\\';
    $baseDir = dirname(__DIR__) . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

use EidCloud\RagVectorless\Chunker\HierarchicalChunker;
use EidCloud\RagVectorless\Index\Bm25Index;
use EidCloud\RagVectorless\Index\Tokenizer;
use EidCloud\RagVectorless\Model\DocumentNode;
use EidCloud\RagVectorless\RagEngine;
use EidCloud\RagVectorless\Storage\JsonStorage;
use EidCloud\RagVectorless\Storage\SqliteStorage;

class RagVectorlessTest
{
    private int $assertions = 0;
    private int $failures = 0;

    private function assert(bool $condition, string $message): void
    {
        $this->assertions++;
        if (!$condition) {
            $this->failures++;
            echo "  \033[0;31m✖ FAIL:\033[0m {$message}\n";
        } else {
            echo "  \033[0;32m✔ PASS:\033[0m {$message}\n";
        }
    }

    public function runAll(): bool
    {
        echo "\033[1;36mRunning EidCloud Vectorless RAG Test Suite...\033[0m\n\n";

        $this->testTokenizer();
        $this->testHierarchicalChunking();
        $this->testBm25Scoring();
        $this->testSqliteStorage();
        $this->testJsonStorage();
        $this->testContextAssemblyAndCitations();
        $this->testMetadataFiltering();

        echo "\n" . str_repeat('=', 60) . "\n";
        echo "Results: {$this->assertions} assertions, ";
        if ($this->failures === 0) {
            echo "\033[1;32m0 failures (100% PASS)\033[0m\n";
            return true;
        } else {
            echo "\033[1;31m{$this->failures} failures\033[0m\n";
            return false;
        }
    }

    private function testTokenizer(): void
    {
        echo "\033[1;33m[1] Testing Tokenizer & Normalization\033[0m\n";

        $tokens = Tokenizer::tokenize("The EidCloud Vectorless RAG engine indexes documents quickly!");
        $this->assert(in_array('eidcloud', $tokens), "Preserves domain keywords");
        $this->assert(in_array('rag', $tokens), "Preserves technical abbreviations");
        $this->assert(!in_array('the', $tokens), "Removes common English stop words");
        $this->assert(in_array('index', $tokens), "Applies morphological stemmer (indexes -> index)");
    }

    private function testHierarchicalChunking(): void
    {
        echo "\n\033[1;33m[2] Testing Hierarchical Document Chunking\033[0m\n";

        $doc = <<<MD
# System Architecture

## Authentication Service
EidCloud requires Ed25519 token signatures for all incoming requests.
Tokens expire after 15 minutes.

## Database Subsystem
Uses embedded SQLite with Raft consensus replication.
MD;

        $chunker = new HierarchicalChunker(minChunkLength: 15);
        $nodes = $chunker->chunkText($doc, 'test://doc1.md');

        $this->assert(count($nodes) >= 3, "Creates nodes for sections and paragraphs");
        
        $hasAuth = false;
        foreach ($nodes as $node) {
            if (str_contains($node->content, 'Ed25519')) {
                $hasAuth = true;
                $this->assert($node->startLine > 1, "Tracks accurate line numbers: line {$node->startLine}");
                $this->assert(in_array('System Architecture', $node->breadcrumbs), "Preserves document topology breadcrumbs");
            }
        }
        $this->assert($hasAuth, "Captured paragraph under section hierarchy");
    }

    private function testBm25Scoring(): void
    {
        echo "\n\033[1;33m[3] Testing BM25 Lexical Ranking\033[0m\n";

        $index = new Bm25Index(k1: 1.2, b: 0.75);

        $node1 = new DocumentNode(
            id: 'n1',
            documentPath: 'auth.md',
            level: 'paragraph',
            title: 'Authentication',
            content: 'Authentication is handled via JWT tokens and Ed25519 keys.',
            startLine: 1,
            endLine: 5
        );

        $node2 = new DocumentNode(
            id: 'n2',
            documentPath: 'db.md',
            level: 'paragraph',
            title: 'Storage',
            content: 'Postgres and SQLite handles data persistence and relational tables.',
            startLine: 1,
            endLine: 5
        );

        $index->addNodes([$node1, $node2]);

        $results = $index->search('JWT authentication tokens', topK: 2);

        $this->assert(!empty($results), "Returns search matches");
        $this->assert($results[0]['node']->id === 'n1', "BM25 ranks most relevant chunk first");
        $this->assert($results[0]['score'] > 0.0, "Score is positive and non-zero");
    }

    private function testSqliteStorage(): void
    {
        echo "\n\033[1;33m[4] Testing SQLite Inverted Index Persistence\033[0m\n";

        $tempDb = sys_get_temp_dir() . '/test_rag_' . uniqid() . '.sqlite';
        $engine = RagEngine::createWithSqlite($tempDb);

        $markdown = "## Deployment\nAlways use TLS 1.3 reverse proxies with automated certificate renewal.";
        $engine->indexText($markdown, 'docs/deploy.md', ['tags' => 'security,prod']);
        $engine->persist();

        $this->assert(file_exists($tempDb), "SQLite database file created on disk");

        // Load into fresh engine
        $loadedEngine = RagEngine::createWithSqlite($tempDb);
        $results = $loadedEngine->search('TLS automated certificate');

        $this->assert(!empty($results), "Retrieved results from persisted SQLite database");
        $this->assert(str_contains($results[0]->node->content, 'TLS 1.3'), "Loaded node content accurately");

        @unlink($tempDb);
    }

    private function testJsonStorage(): void
    {
        echo "\n\033[1;33m[5] Testing JSON Inverted Index Persistence\033[0m\n";

        $tempJson = sys_get_temp_dir() . '/test_rag_' . uniqid() . '.json';
        $engine = RagEngine::createWithJson($tempJson);

        $markdown = "## Caching\nRedis in-memory caching cluster provides sub-millisecond lookups.";
        $engine->indexText($markdown, 'docs/cache.md');
        $engine->persist();

        $this->assert(file_exists($tempJson), "JSON database file created on disk");

        // Load into fresh engine
        $loadedEngine = RagEngine::createWithJson($tempJson);
        $results = $loadedEngine->search('Redis sub-millisecond lookups');

        $this->assert(!empty($results), "Retrieved results from persisted JSON storage");
        $this->assert(str_contains($results[0]->node->content, 'Redis in-memory'), "Matched correct cached chunk");

        @unlink($tempJson);
    }

    private function testContextAssemblyAndCitations(): void
    {
        echo "\n\033[1;33m[6] Testing Context Assembly & Exact Citations\033[0m\n";

        $engine = RagEngine::createInMemory();
        $sampleText = <<<MD
# User Management

## Password Policies
Passwords must be at least 16 characters long and change every 90 days.
Multi-factor authentication (MFA) is strictly required for admin accounts.
MD;

        $engine->indexText($sampleText, 'config/security.md');
        $context = $engine->ask("What is the password policy?", topK: 1);

        $this->assert(!empty($context['citations']), "Generated citations list");
        $this->assert(str_contains($context['citations'][0], 'config/security.md'), "Citation includes accurate file path");
        $this->assert(str_contains($context['citations'][0], 'lines'), "Citation includes line numbers");
        $this->assert(str_contains($context['context_text'], '--- SOURCE 1'), "Context block formatted with source markers");
    }

    private function testMetadataFiltering(): void
    {
        echo "\n\033[1;33m[7] Testing Metadata Filtering\033[0m\n";

        $engine = RagEngine::createInMemory();
        $engine->indexText("Feature A requires license key", 'docA.md', ['tags' => ['enterprise'], 'env' => 'prod']);
        $engine->indexText("Feature B is open source", 'docB.md', ['tags' => ['community'], 'env' => 'dev']);

        $resAll = $engine->search('feature');
        $this->assert(count($resAll) === 2, "Unfiltered query returns all matches");

        $resFiltered = $engine->search('feature', filters: ['env' => 'prod']);
        $this->assert(count($resFiltered) === 1, "Filter returns only matching env");
        $this->assert($resFiltered[0]->node->documentPath === 'docA.md', "Matched docA with prod env");
    }
}
