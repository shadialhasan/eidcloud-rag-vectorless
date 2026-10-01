<?php

declare(strict_types=1);

namespace EidCloud\RagVectorless\Model;

/**
 * Represents a document node in the hierarchical document graph.
 */
class DocumentNode
{
    /**
     * @param string $id Unique chunk identifier
     * @param string $documentPath Path or source ID of origin document
     * @param string $level Level in hierarchy: 'document', 'section', 'header', 'paragraph'
     * @param string $title Title or header name
     * @param string $content Cleaned text content
     * @param int $startLine 1-based starting line number
     * @param int $endLine 1-based ending line number
     * @param array<string> $breadcrumbs Path in document topology (e.g. ['Document', 'Section 1', 'Sub 1.1'])
     * @param array<string, mixed> $metadata Arbitrary metadata (tags, author, date, type)
     * @param string|null $parentId ID of parent node in hierarchy
     */
    public function __construct(
        public readonly string $id,
        public readonly string $documentPath,
        public readonly string $level,
        public readonly string $title,
        public readonly string $content,
        public readonly int $startLine,
        public readonly int $endLine,
        public readonly array $breadcrumbs = [],
        public readonly array $metadata = [],
        public readonly ?string $parentId = null
    ) {
    }

    /**
     * Returns word count of content.
     */
    public function getWordCount(): int
    {
        return str_word_count($this->content);
    }

    /**
     * Formats node citation string.
     */
    public function getCitation(): string
    {
        $trail = !empty($this->breadcrumbs) ? implode(' > ', $this->breadcrumbs) : $this->title;
        return sprintf('%s (lines %d-%d) [%s]', $this->documentPath, $this->startLine, $this->endLine, $trail);
    }

    /**
     * Convert node to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'document_path' => $this->documentPath,
            'level' => $this->level,
            'title' => $this->title,
            'content' => $this->content,
            'start_line' => $this->startLine,
            'end_line' => $this->endLine,
            'breadcrumbs' => $this->breadcrumbs,
            'metadata' => $this->metadata,
            'parent_id' => $this->parentId,
            'citation' => $this->getCitation(),
        ];
    }

    /**
     * Reconstruct node from array.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string)$data['id'],
            documentPath: (string)$data['document_path'],
            level: (string)$data['level'],
            title: (string)$data['title'],
            content: (string)$data['content'],
            startLine: (int)$data['start_line'],
            endLine: (int)$data['end_line'],
            breadcrumbs: is_array($data['breadcrumbs']) ? $data['breadcrumbs'] : [],
            metadata: is_array($data['metadata']) ? $data['metadata'] : [],
            parentId: isset($data['parent_id']) ? (string)$data['parent_id'] : null
        );
    }
}
