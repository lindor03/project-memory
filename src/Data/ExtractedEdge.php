<?php

namespace ProjectMemory\Data;

class ExtractedEdge
{
    public function __construct(
        public string $sourceKey,
        public string $targetKey,
        public string $targetType,
        public ?string $targetSymbol,
        public string $relationship,
        public float $confidence,
        public array $metadata = [],
    ) {}
}
