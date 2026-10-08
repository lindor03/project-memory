<?php

namespace ProjectMemory\Data;

class ExtractedNode
{
    public function __construct(
        public string $key,
        public string $type,
        public ?string $symbol,
        public ?int $startLine,
        public ?int $endLine,
        public string $contentHash,
        public ?string $summary,
        public array $metadata = [],
        public bool $shared = false,
    ) {}
}
