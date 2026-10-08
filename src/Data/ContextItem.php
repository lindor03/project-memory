<?php

namespace ProjectMemory\Data;

class ContextItem
{
    public function __construct(
        public string $id,
        public int $score,
        public string $text,
        public array $source = [],
        public ?string $compactText = null,
    ) {}
}
