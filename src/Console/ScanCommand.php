<?php

namespace ProjectMemory\Console;

use ProjectMemory\Exceptions\IndexLockedException;
use ProjectMemory\Indexing\IndexSynchronizer;

class ScanCommand extends ProjectMemoryCommand
{
    protected $signature = 'ai:scan {--force : Reparse every file even when the hash matches} {--json : Machine-readable output}';

    protected $description = 'Index application source into the disposable project-memory database';

    public function handle(IndexSynchronizer $synchronizer): int
    {
        try {
            $run = $synchronizer->sync('full', (bool) $this->option('force'));
        } catch (IndexLockedException $exception) {
            return $this->emit(['text' => $exception->getMessage(), 'error' => $exception->getMessage()], 2);
        } catch (\Throwable $exception) {
            return $this->emit(['text' => $exception->getMessage(), 'error' => $exception->getMessage()], self::FAILURE);
        }

        return $this->emit([
            'text' => sprintf(
                "Scan %s. Updated %d, skipped %d, deleted %d, errors %d, %d ms.\n",
                $run->status,
                $run->files_updated,
                $run->files_skipped,
                $run->deleted_nodes,
                count($run->errors ?? []),
                $run->duration_ms
            ),
            'run' => $run->toArray(),
        ], $run->status === 'completed' && ($run->errors ?? []) === [] ? self::SUCCESS : self::FAILURE);
    }
}
