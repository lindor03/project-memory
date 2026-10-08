<?php

namespace ProjectMemory\Data;

class ContextPacket
{
    /**
     * @param  list<array<string, mixed>>  $sources
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $operation,
        public string $text,
        public int $estimatedTokens,
        public bool $truncated,
        public bool $stale,
        public array $warnings,
        public array $sources,
        public int $retrievedCharacters,
        public array $metrics = [],
    ) {}

    public function toArray(): array
    {
        return [
            'operation' => $this->operation,
            'text' => $this->text,
            'estimated_input_tokens' => $this->estimatedTokens,
            'token_basis' => 'estimated_local_characters',
            'provider_reported_input_tokens' => $this->metrics['provider_reported_input_tokens'] ?? null,
            'provider_reported_output_tokens' => $this->metrics['provider_reported_output_tokens'] ?? null,
            'provider_reported_cached_tokens' => $this->metrics['provider_reported_cached_tokens'] ?? null,
            'truncated' => $this->truncated,
            'stale' => $this->stale,
            'warnings' => $this->warnings,
            'sources' => $this->sources,
            'retrieved_characters' => $this->retrievedCharacters,
            'metrics' => $this->metrics,
        ];
    }
}
