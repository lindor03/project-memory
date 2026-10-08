<?php

namespace ProjectMemory\Schema;

use ProjectMemory\Support\MemoryConnection;

class SchemaInstaller
{
    /** @var list<string> */
    public const TABLES = [
        'ai_modules',
        'ai_code_nodes',
        'ai_code_edges',
        'ai_knowledge',
        'ai_change_sets',
        'ai_index_runs',
        'ai_agent_runs',
        'ai_extraction_cache',
    ];

    public function __construct(private readonly MemoryConnection $memory) {}

    public function prepare(): void
    {
        $this->memory->assertSafe();
        $this->ensureSqliteFile();
    }

    public function install(): void
    {
        $this->prepare();

        $connection = $this->memory->name();
        $migrator = app('migrator');
        $paths = [dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations'];

        $migrator->usingConnection($connection, function () use ($migrator, $paths) {
            if (! $migrator->repositoryExists()) {
                $migrator->getRepository()->createRepository();
            }

            $migrator->run($paths);
        });
    }

    public function ready(): bool
    {
        try {
            $this->memory->assertSafe();
            $schema = app('db')->connection($this->memory->name())->getSchemaBuilder();
            foreach (self::TABLES as $table) {
                if (! $schema->hasTable($table)) {
                    return false;
                }
            }

            return $schema->hasColumns('ai_code_edges', ['declaration_path_hash', 'reference_hash']);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return list<string> */
    public function existingTables(): array
    {
        $this->prepare();
        $schema = app('db')->connection($this->memory->name())->getSchemaBuilder();
        $found = [];
        foreach (self::TABLES as $table) {
            if ($schema->hasTable($table)) {
                $found[] = $table;
            }
        }

        return $found;
    }

    private function ensureSqliteFile(): void
    {
        $name = $this->memory->name();
        $driver = config('database.connections.'.$name.'.driver');
        $database = (string) config('database.connections.'.$name.'.database');

        if ($driver !== 'sqlite' || $database === ':memory:' || $database === '') {
            return;
        }

        $directory = dirname($database);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        if (! is_file($database)) {
            touch($database);
        }
    }
}
