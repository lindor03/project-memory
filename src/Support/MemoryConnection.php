<?php

namespace ProjectMemory\Support;

use ProjectMemory\Models\AiAgentRun;
use ProjectMemory\Models\AiChangeSet;
use ProjectMemory\Models\AiCodeEdge;
use ProjectMemory\Models\AiCodeNode;
use ProjectMemory\Models\AiIndexRun;
use ProjectMemory\Models\AiKnowledge;
use ProjectMemory\Models\AiModule;

class MemoryConnection
{
    private ?array $verifiedConfiguration = null;

    public function name(): string
    {
        $configured = config('project-memory.database.connection');

        if (! is_string($configured) || $configured === '' || $configured === 'default') {
            return (string) config('database.default');
        }

        return $configured;
    }

    public function databaseName(): string
    {
        return (string) config('database.connections.'.$this->name().'.database');
    }

    public function assertSafe(): void
    {
        // Cache only a successful guard against the complete resolved config.
        // Runtime overrides (including tests) invalidate it immediately.
        $configuration = [config('project-memory.database'), config('database.default'), config('database.connections')];
        if ($this->verifiedConfiguration === $configuration) {
            return;
        }
        $database = $this->databaseName();
        $blocked = config('project-memory.database.blocked_databases', []);

        $applicationDatabase = (string) config('database.connections.'.config('database.default').'.database');
        $memoryDriver = config('database.connections.'.$this->name().'.driver');
        $applicationDriver = config('database.connections.'.config('database.default').'.driver');

        if ($database === '') {
            throw new \RuntimeException('Project memory requires an explicitly configured database.');
        }

        if ($database !== ':memory:' && $applicationDatabase !== '' && strcasecmp($database, $applicationDatabase) === 0) {
            throw new \RuntimeException(
                'Refusing to store project memory in the application database ['.$database.'].'
            );
        }

        if ($database !== ':memory:' && $memoryDriver === 'sqlite' && $applicationDriver === 'sqlite'
            && $this->canonicalSqlite($database) === $this->canonicalSqlite($applicationDatabase)) {
            throw new \RuntimeException('Refusing to store project memory in the application SQLite database ['.$database.'].');
        }

        if (is_array($blocked) && in_array(strtolower($database), array_map('strtolower', $blocked), true)) {
            throw new \RuntimeException(
                'Refusing to store project memory in ['.$database.']. Use the dedicated project_memory database.'
            );
        }
        $this->verifiedConfiguration = $configuration;
    }

    private function canonicalSqlite(string $path): string
    {
        $resolved = realpath($path);
        if ($resolved === false) {
            $parent = realpath(dirname($path));
            $resolved = ($parent === false ? dirname($path) : $parent).DIRECTORY_SEPARATOR.basename($path);
        }
        $resolved = str_replace('\\', '/', $resolved);

        return PHP_OS_FAMILY === 'Windows' ? strtolower($resolved) : $resolved;
    }

    /**
     * @return list<class-string>
     */
    public function models(): array
    {
        return [
            AiModule::class,
            AiCodeNode::class,
            AiCodeEdge::class,
            AiKnowledge::class,
            AiChangeSet::class,
            AiIndexRun::class,
            AiAgentRun::class,
        ];
    }
}
