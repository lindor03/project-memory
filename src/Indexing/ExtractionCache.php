<?php

namespace ProjectMemory\Indexing;

use ProjectMemory\Data\ExtractedEdge;
use ProjectMemory\Data\ExtractedNode;
use ProjectMemory\Data\FileExtraction;
use ProjectMemory\Support\MemoryConnection;

class ExtractionCache
{
    public function __construct(private readonly MemoryConnection $memory) {}

    public function key(string $path, string $hash, string $fingerprint): string
    {
        return hash('sha256', $path.'|'.$hash.'|'.$fingerprint);
    }

    public function get(string $key): ?FileExtraction
    {
        $this->memory->assertSafe();
        $row = app('db')->connection($this->memory->name())->table('ai_extraction_cache')->where('cache_key', $key)->first();
        if ($row === null) {
            return null;
        }
        try {
            $data = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
            $artifactHash = $data['artifact_hash'] ?? null;
            unset($data['artifact_hash']);
            if (! is_string($artifactHash) || ! hash_equals($artifactHash, $this->artifactHash($data))) {
                return null;
            }
            if ($data['hash'] !== $row->content_hash || $this->key($data['path'], $data['hash'], $row->analyzer_fingerprint) !== $key) {
                return null;
            }
            $extraction = new FileExtraction($data['path'], $data['hash'], $data['bytes']);
            foreach ($data['nodes'] as $node) {
                $extraction->addNode(new ExtractedNode(...$node));
            }
            foreach ($data['edges'] as $edge) {
                $extraction->addEdge(new ExtractedEdge(...$edge));
            }
            $extraction->notes = $data['notes'];
            app('db')->connection($this->memory->name())->table('ai_extraction_cache')->where('cache_key', $key)->update(['last_used_at' => now()]);

            return $extraction;
        } catch (\Throwable) {
            // A corrupt cached artifact is always reconstructed from source.
            return null;
        }
    }

    public function put(string $key, string $fingerprint, FileExtraction $extraction): void
    {
        $this->memory->assertSafe();
        $payload = ['path' => $extraction->relativePath, 'hash' => $extraction->contentHash, 'bytes' => $extraction->bytes,
            'nodes' => array_map(fn ($node) => array_values(get_object_vars($node)), array_values($extraction->nodes)),
            'edges' => array_map(fn ($edge) => array_values(get_object_vars($edge)), array_values($extraction->edges)), 'notes' => $extraction->notes];
        $payload['artifact_hash'] = $this->artifactHash($payload);
        app('db')->connection($this->memory->name())->table('ai_extraction_cache')->upsert([
            ['cache_key' => $key, 'content_hash' => $extraction->contentHash, 'analyzer_fingerprint' => $fingerprint,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'last_used_at' => now()],
        ], ['cache_key'], ['payload', 'last_used_at']);
    }

    public function prune(): int
    {
        $this->memory->assertSafe();

        return app('db')->connection($this->memory->name())->table('ai_extraction_cache')
            ->where('last_used_at', '<', now()->subDays(max(1, (int) config('project-memory.index.cache_retention_days', 30))))->delete();
    }

    private function artifactHash(array $payload): string
    {
        return hash('sha256', json_encode($this->canonicalize($payload), JSON_THROW_ON_ERROR));
    }

    private function canonicalize(array $value): array
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->canonicalize($item);
            }
        }
        unset($item);
        // MySQL JSON normalizes object-member order; list order is meaningful.
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
