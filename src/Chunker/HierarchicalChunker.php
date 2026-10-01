<?php

declare(strict_types=1);

namespace EidCloud\RagVectorless\Chunker;

use EidCloud\RagVectorless\Model\DocumentNode;

/**
 * Parses markdown, code, and text into hierarchical document graphs:
 * Document -> Section (H1/H2) -> Header (H3/H4) -> Paragraph / Code blocks
 * Preserving line numbers, title hierarchy, and document topology.
 */
class HierarchicalChunker
{
    private int $minChunkLength;
    private int $maxChunkLength;

    public function __construct(int $minChunkLength = 10, int $maxChunkLength = 1500)
    {
        $this->minChunkLength = $minChunkLength;
        $this->maxChunkLength = $maxChunkLength;
    }

    /**
     * Chunk a document file into hierarchical DocumentNode items.
     *
     * @param string $filePath Absolute or relative path to document
     * @param array<string, mixed> $extraMetadata
     * @return array<DocumentNode>
     */
    public function chunkFile(string $filePath, array $extraMetadata = []): array
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new \InvalidArgumentException("File not found or unreadable: {$filePath}");
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new \RuntimeException("Failed to read file: {$filePath}");
        }

        // Infer metadata from file if not provided
        $metadata = array_merge([
            'filename' => basename($filePath),
            'extension' => pathinfo($filePath, PATHINFO_EXTENSION),
            'file_size' => filesize($filePath),
            'modified_at' => date('Y-m-d H:i:s', filemtime($filePath) ?: time()),
        ], $extraMetadata);

        return $this->chunkText($content, $filePath, $metadata);
    }

    /**
     * Parse text into hierarchical nodes.
     *
     * @param string $text Document content
     * @param string $documentPath Path or identifier
     * @param array<string, mixed> $metadata
     * @return array<DocumentNode>
     */
    public function chunkText(string $text, string $documentPath, array $metadata = []): array
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
        $totalLines = count($lines);
        $nodes = [];

        // Check frontmatter
        $frontmatter = [];
        $startLineOffset = 1;
        if (isset($lines[0]) && trim($lines[0]) === '---') {
            $fmEnd = null;
            for ($i = 1; $i < $totalLines; $i++) {
                if (trim($lines[$i]) === '---') {
                    $fmEnd = $i;
                    break;
                }
            }
            if ($fmEnd !== null) {
                // Parse simple key: value frontmatter
                for ($k = 1; $k < $fmEnd; $k++) {
                    $fLine = trim($lines[$k]);
                    if (str_contains($fLine, ':')) {
                        [$fKey, $fVal] = explode(':', $fLine, 2);
                        $frontmatter[trim($fKey)] = trim($fVal);
                    }
                }
                $startLineOffset = $fmEnd + 2;
            }
        }

        $metadata = array_merge($metadata, $frontmatter);

        // Document root node
        $docTitle = $metadata['title'] ?? pathinfo($documentPath, PATHINFO_FILENAME);
        $docId = md5($documentPath);
        
        // State tracking
        // Hierarchy stack: [ ['level' => 1, 'id' => ..., 'title' => ..., 'startLine' => ...] ]
        $headerStack = [
            [
                'depth' => 0,
                'id' => $docId,
                'title' => $docTitle,
                'level' => 'document',
            ]
        ];

        $currentParagraph = [];
        $pStartLine = $startLineOffset;
        $chunkIndex = 0;

        $flushParagraph = function () use (
            &$currentParagraph,
            &$pStartLine,
            &$headerStack,
            &$nodes,
            $documentPath,
            $metadata,
            &$chunkIndex
        ) {
            if (empty($currentParagraph)) {
                return;
            }

            $content = trim(implode("\n", $currentParagraph));
            $endLine = $pStartLine + count($currentParagraph) - 1;

            if (mb_strlen($content) >= $this->minChunkLength) {
                $chunkIndex++;
                $parent = end($headerStack);
                $breadcrumbs = array_map(fn($item) => $item['title'], $headerStack);
                
                $nodeId = sprintf('%s#chunk-%d', md5($documentPath), $chunkIndex);

                $nodes[] = new DocumentNode(
                    id: $nodeId,
                    documentPath: $documentPath,
                    level: 'paragraph',
                    title: $parent['title'] ?? 'Section',
                    content: $content,
                    startLine: $pStartLine,
                    endLine: $endLine,
                    breadcrumbs: $breadcrumbs,
                    metadata: $metadata,
                    parentId: $parent['id'] ?? null
                );
            }

            $currentParagraph = [];
        };

        $inCodeBlock = false;

        for ($i = $startLineOffset - 1; $i < $totalLines; $i++) {
            $line = $lines[$i];
            $currentLineNum = $i + 1;

            // Handle markdown code blocks
            if (str_starts_with(trim($line), '```')) {
                $inCodeBlock = !$inCodeBlock;
                $currentParagraph[] = $line;
                continue;
            }

            if ($inCodeBlock) {
                $currentParagraph[] = $line;
                continue;
            }

            // Check if line is a markdown heading (# H1, ## H2, etc.)
            if (preg_match('/^(#{1,6})\s+(.*)$/', trim($line), $matches)) {
                // Flush accumulated paragraph
                $flushParagraph();

                $depth = strlen($matches[1]);
                $headingTitle = trim($matches[2]);

                // Determine level
                $level = match ($depth) {
                    1, 2 => 'section',
                    3, 4 => 'header',
                    default => 'paragraph',
                };

                // Pop stack until parent depth is smaller
                while (count($headerStack) > 1 && end($headerStack)['depth'] >= $depth) {
                    array_pop($headerStack);
                }

                $parent = end($headerStack);
                $chunkIndex++;
                $headingId = sprintf('%s#h-%d', md5($documentPath), $chunkIndex);

                $breadcrumbs = array_map(fn($item) => $item['title'], $headerStack);
                $breadcrumbs[] = $headingTitle;

                // Push heading node
                $headerNode = new DocumentNode(
                    id: $headingId,
                    documentPath: $documentPath,
                    level: $level,
                    title: $headingTitle,
                    content: $headingTitle,
                    startLine: $currentLineNum,
                    endLine: $currentLineNum,
                    breadcrumbs: $breadcrumbs,
                    metadata: $metadata,
                    parentId: $parent['id'] ?? null
                );
                $nodes[] = $headerNode;

                $headerStack[] = [
                    'depth' => $depth,
                    'id' => $headingId,
                    'title' => $headingTitle,
                    'level' => $level,
                ];

                $pStartLine = $currentLineNum + 1;
                continue;
            }

            // Blank line indicates paragraph boundary
            if (trim($line) === '') {
                if (!empty($currentParagraph)) {
                    $flushParagraph();
                }
                $pStartLine = $currentLineNum + 1;
                continue;
            }

            if (empty($currentParagraph)) {
                $pStartLine = $currentLineNum;
            }
            $currentParagraph[] = $line;

            // Enforce max chunk length if paragraph becomes overly long
            if (mb_strlen(implode("\n", $currentParagraph)) > $this->maxChunkLength) {
                $flushParagraph();
                $pStartLine = $currentLineNum + 1;
            }
        }

        // Final flush
        $flushParagraph();

        return $nodes;
    }
}
