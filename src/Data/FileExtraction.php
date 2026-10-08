<?php

namespace ProjectMemory\Data;

class FileExtraction
{
    /** @var array<string, ExtractedNode> */
    public array $nodes = [];

    /** @var array<string, ExtractedEdge> */
    public array $edges = [];

    /** @var list<string> */
    public array $notes = [];

    public function __construct(
        public readonly string $relativePath,
        public readonly string $contentHash,
        public readonly int $bytes,
    ) {}

    public function addNode(ExtractedNode $node): void
    {
        $this->nodes[$node->key] = $node;
    }

    public function addEdge(ExtractedEdge $edge): void
    {
        if ($edge->sourceKey === $edge->targetKey) {
            return;
        }

        $edge->confidence = max(0.0, min(1.0, $edge->confidence));
        $id = $edge->sourceKey.'|'.$edge->targetKey.'|'.$edge->relationship;
        $existing = $this->edges[$id] ?? null;

        if ($existing === null || $edge->confidence > $existing->confidence) {
            $this->edges[$id] = $edge;
        }
    }
}
