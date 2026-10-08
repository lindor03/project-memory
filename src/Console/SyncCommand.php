<?php

namespace ProjectMemory\Console;

use ProjectMemory\Exceptions\IndexLockedException;
use ProjectMemory\Indexing\IndexSynchronizer;

class SyncCommand extends ProjectMemoryCommand
{
    protected $signature = 'ai:sync {--force : Reparse every file even when the hash matches} {--json : Machine-readable output}';

    protected $description = 'Incrementally synchronize changed and deleted source files';

    public function handle(IndexSynchronizer $synchronizer): int
    {
        try {
            $run = $synchronizer->sync('incremental', (bool) $this->option('force'));
        } catch (IndexLockedException $exception) {
            return $this->emit(['text' => $exception->getMessage(), 'error' => $exception->getMessage()], 2);
        } catch (\Throwable $exception) {
            return $this->emit(['text' => $exception->getMessage(), 'error' => $exception->getMessage()], self::FAILURE);
        }

        return $this->emit([
            'text' => sprintf(
                "Sync %s. Updated %d, skipped %d, deleted %d, errors %d, %d ms.\n",
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
