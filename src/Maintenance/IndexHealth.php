<?php

namespace ProjectMemory\Maintenance;

use ProjectMemory\Indexing\AnalyzerFingerprint;
use ProjectMemory\Indexing\SourceScanner;
use ProjectMemory\Models\AiCodeNode;
use ProjectMemory\Models\AiIndexRun;
use ProjectMemory\Support\NodeKeys;

/** Read-only diagnostics: checking health must never install or re-approve memory. */
class IndexHealth
{
    public function __construct(private readonly SourceScanner $scanner) {}

    public function inspect(): array
    {
        $last = AiIndexRun::query()->latest('id')->first();
        $published = AiIndexRun::query()->where('status', 'completed')->latest('id')->first();
        $base = NodeKeys::absolute((string) config('project-memory.scan.base_path', base_path()));
        $missing = $changed = $unreadable = $files = 0;
        $samples = [];
        $indexedPaths = [];
        AiCodeNode::query()->where('node_type', 'file')->select(['id', 'file_path', 'content_hash'])
            ->chunkById(200, function ($nodes) use ($base, &$missing, &$changed, &$unreadable, &$files, &$samples, &$indexedPaths) {
                foreach ($nodes as $node) {
                    $files++;
                    $path = (string) $node->file_path;
                    $indexedPaths[$path] = true;
                    $safe = $path !== '' && ! str_starts_with($path, '/') && ! preg_match('/^[a-z]:/i', $path)
                        && ! in_array('..', explode('/', str_replace('\\', '/', $path)), true) && ! str_contains($path, "\0");
                    $absolute = $base.'/'.$path;
                    $real = $safe ? realpath($absolute) : false;
                    $realBase = realpath($base);
                    $normalized = $real !== false ? NodeKeys::absolute($real) : '';
                    $prefix = $realBase !== false ? NodeKeys::absolute($realBase).'/' : '';
                    $contained = $real !== false && $realBase !== false && (PHP_OS_FAMILY === 'Windows'
                        ? str_starts_with(strtolower($normalized), strtolower($prefix)) : str_starts_with($normalized, $prefix));
                    $reason = null;
                    if (! $safe || ! is_file($absolute)) {
                        $missing++;
                        $reason = 'missing';
                    } elseif (! $contained || ! is_readable($absolute) || ($hash = hash_file('sha256', $absolute)) === false) {
                        $unreadable++;
                        $reason = 'unreadable_or_outside_repository';
                    } elseif ($hash !== $node->content_hash) {
                        $changed++;
                        $reason = 'changed';
                    }
                    if ($reason !== null && count($samples) < 20) {
                        $samples[] = ['file' => $path, 'reason' => $reason];
                    }
                }
            });
        $unindexed = 0;
        foreach ($this->scanner->discover() as $file) {
            if (! isset($indexedPaths[$file['path']])) {
                $unindexed++;
                if (count($samples) < 20) {
                    $samples[] = ['file' => $file['path'], 'reason' => 'unindexed'];
                }
            }
        }
        $expected = class_exists(AnalyzerFingerprint::class) ? app(AnalyzerFingerprint::class)->current() : null;
        $indexed = $published?->metadata['analyzer_fingerprint'] ?? null;
        $outdated = $files > 0 && $expected !== null && $expected !== $indexed;
        $stale = $missing + $changed + $unreadable + $unindexed;
        $healthy = $files > 0 && $stale === 0 && ! $outdated && $last?->status === 'completed' && ($last?->errors ?? []) === [];

        return [
            'initialized' => $files > 0,
            'fresh' => $files > 0 && $stale === 0 && ! $outdated,
            'healthy' => $healthy,
            'indexed_files' => $files,
            'stale_files' => $stale,
            'changed_files' => $changed,
            'missing_files' => $missing,
            'unreadable_files' => $unreadable,
            'unindexed_files' => $unindexed,
            'scan_warnings' => $this->scanner->warnings(),
            'coverage_complete' => $this->scanner->warnings() === [],
            'stale_samples' => $samples,
            'analyzer_outdated' => $outdated,
            'analyzer_fingerprint' => $indexed,
            'expected_analyzer_fingerprint' => $expected,
            'last_status' => $last?->status,
            'last_errors' => $last?->errors ?? [],
            'last_metrics' => $this->compactMetrics($last?->metadata ?? []),
            'last_finished_at' => $last?->finished_at?->toIso8601String(),
            'action' => $healthy ? null : 'Run ai:sync and inspect cited live source until the index is fresh.',
            'source_authoritative' => true,
        ];
    }

    private function compactMetrics(array $metadata): array
    {
        foreach (['changed_symbols', 'removed_symbols'] as $field) {
            if (is_array($metadata[$field] ?? null)) {
                $metadata[$field.'_count'] = count($metadata[$field]);
                $metadata[$field.'_sample'] = array_slice($metadata[$field], 0, 5);
                unset($metadata[$field]);
            }
        }

        return $metadata;
    }
}
