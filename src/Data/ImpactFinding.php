<?php

namespace ProjectMemory\Data;

class ImpactFinding
{
    public function __construct(
        public string $nodeKey,
        public string $symbol,
        public string $type,
        public ?string $file,
        public string $relationship,
        public string $direction,
        public string $reason,
        public float $confidence,
        public string $risk,
        public int $distance,
        public array $pathNodeKeys = [],
    ) {}

    public function toArray(): array
    {
        return [
            'node_key' => $this->nodeKey,
            'symbol' => $this->symbol,
            'type' => $this->type,
            'file' => $this->file,
            'relationship' => $this->relationship,
            'direction' => $this->direction,
            'reason' => $this->reason,
            'confidence' => $this->confidence,
            'risk' => $this->risk,
            'distance' => $this->distance,
            'safety' => 'unverified',
            'path_node_keys' => $this->pathNodeKeys,
        ];
    }
}
