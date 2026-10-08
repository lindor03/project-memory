<?php

namespace ProjectMemory\Context;

use ProjectMemory\Indexing\AnalyzerFingerprint;
use ProjectMemory\Indexing\FileHasher;
use ProjectMemory\Models\AiCodeNode;
use ProjectMemory\Support\NodeKeys;

/** A retrieval-scoped cache: never trusts timestamps across requests. */
class SourceFreshness
{
    /** @var array<string, AiCodeNode|null> */
    private array $files = [];

    /** @var array<string, string|null> */
    private array $hashes = [];

    private int $cacheHits = 0;

    private ?string $analyzerVersion = null;

    public function __construct(private readonly FileHasher $hasher, private readonly ?AnalyzerFingerprint $fingerprint = null) {}

    public function reset(): void
    {
        $this->files = [];
        $this->hashes = [];
        $this->cacheHits = 0;
        $this->analyzerVersion = null;
    }

    /** @param iterable<AiCodeNode> $nodes */
    public function prime(iterable $nodes): void
    {
        $missing = [];
        foreach ($nodes as $node) {
            foreach ($this->paths($node) as $path) {
                $key = $node->file_path === $path && $node->path_hash ? $node->path_hash : NodeKeys::pathHash($path);
                if ($node->node_type === 'file') {
                    $this->files[$key] = $node;
                } elseif (! array_key_exists($key, $this->files)) {
                    $missing[$key] = true;
                }
            }
        }

        foreach (array_chunk(array_keys($missing), 500) as $keys) {
            foreach ($keys as $key) {
                $this->files[$key] = null;
            }
            foreach (AiCodeNode::query()->where('node_type', 'file')->whereIn('path_hash', $keys)->get() as $file) {
                $this->files[$file->path_hash] = $file;
            }
        }
    }

    public function node(AiCodeNode $node): bool
    {
        $paths = $this->paths($node);
        if (! empty($node->metadata['stub']) || $paths === []) {
            return false;
        }
        $this->analyzerVersion ??= ($this->fingerprint ?? new AnalyzerFingerprint)->current();
        foreach ($paths as $path) {
            $key = $node->file_path === $path && $node->path_hash ? $node->path_hash : NodeKeys::pathHash($path);
            if (! array_key_exists($key, $this->files)) {
                $this->prime([$node]);
            }
            $file = $this->files[$key] ?? null;
            if (! $file instanceof AiCodeNode || $file->content_hash === null
                || ($file->metadata['analyzer_fingerprint'] ?? null) !== $this->analyzerVersion
                || $this->liveHash($path) !== $file->content_hash) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    public function paths(AiCodeNode $node): array
    {
        if ($node->file_path !== null) {
            return [$node->file_path];
        }
        if (empty($node->metadata['shared'])) {
            return [];
        }
        $metadata = $node->metadata ?? [];
        $sources = $metadata['sources'] ?? [];
        $sources = is_array($sources) ? $sources : [];
        foreach (is_array($metadata['columns'] ?? null) ? $metadata['columns'] : [] as $column) {
            if (is_array($column) && is_string($column['source'] ?? null)) {
                $sources[] = $column['source'];
            }
        }
        if ($sources === [] && is_string($metadata['source'] ?? null)) {
            $sources[] = $metadata['source'];
        }

        return array_values(array_unique(array_filter($sources, fn ($path) => is_string($path) && $path !== '')));
    }

    public function liveHash(string $path): ?string
    {
        $path = str_replace('\\', '/', $path);
        if (array_key_exists($path, $this->hashes)) {
            $this->cacheHits++;

            return $this->hashes[$path];
        }
        $this->hashes[$path] = null;
        // A corrupt or external reference must not cause reads outside the repository.
        if ($path === '' || str_contains($path, "\0") || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:|(^|\/)\.\.(\/|$)/', $path)) {
            return null;
        }
        $base = realpath((string) config('project-memory.scan.base_path', base_path()));
        $absolute = $base === false ? false : realpath($base.DIRECTORY_SEPARATOR.$path);
        if ($base === false || $absolute === false || ! is_file($absolute) || ! is_readable($absolute)) {
            return null;
        }
        $prefix = NodeKeys::absolute($base).'/';
        $normalized = NodeKeys::absolute($absolute);
        $inside = PHP_OS_FAMILY === 'Windows'
            ? str_starts_with(strtolower($normalized), strtolower($prefix))
            : str_starts_with($normalized, $prefix);
        if (! $inside) {
            return null;
        }

        try {
            return $this->hashes[$path] = $this->hasher->file($absolute);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{files_verified: int, files_unavailable: int, hash_cache_hits: int} */
    public function metrics(): array
    {
        $verified = count(array_filter($this->hashes, fn ($hash) => $hash !== null));

        return ['files_verified' => $verified, 'files_unavailable' => count($this->hashes) - $verified, 'hash_cache_hits' => $this->cacheHits];
    }
}
