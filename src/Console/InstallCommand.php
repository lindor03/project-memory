<?php

namespace ProjectMemory\Console;

use Illuminate\Console\Command;
use ProjectMemory\Integration\CursorConfiguration;
use ProjectMemory\PackageInfo;
use ProjectMemory\Schema\SchemaInstaller;
use ProjectMemory\Support\Compatibility;
use ProjectMemory\Support\MemoryConnection;

class InstallCommand extends Command
{
    protected $signature = 'project-memory:install
        {--migrate : Apply pending additive migrations on an existing memory database}
        {--cursor : Add a portable MCP server and rule when they are absent}
        {--json : Machine-readable output}';

    protected $description = 'Check compatibility and initialize project memory without dropping data';

    public function handle(Compatibility $compatibility, MemoryConnection $memory, SchemaInstaller $schema, CursorConfiguration $cursor): int
    {
        $report = [
            'package' => PackageInfo::NAME,
            'version' => PackageInfo::VERSION,
            'compatibility' => $compatibility->check(),
            'config' => null,
            'database' => null,
            'schema' => null,
            'cursor' => null,
        ];

        if ($report['compatibility']['compatible'] !== true) {
            return $this->finish($report, self::FAILURE);
        }

        $configPath = config_path('project-memory.php');
        if (is_file($configPath)) {
            $report['config'] = 'Left the existing configuration unchanged.';
        } else {
            $this->callSilent('vendor:publish', [
                '--tag' => 'project-memory-config',
                '--no-interaction' => true,
            ]);
            $report['config'] = is_file($configPath)
                ? 'Published config/project-memory.php.'
                : 'Configuration could not be published.';
        }

        try {
            $schema->prepare();
            $connection = app('db')->connection($memory->name());
            $connection->getPdo();
            $report['database'] = [
                'ok' => true,
                'connection' => $memory->name(),
                'driver' => $connection->getDriverName(),
                'database' => $memory->databaseName(),
                'detail' => 'Memory connection is reachable. External databases were not created or altered.',
            ];
        } catch (\Throwable $exception) {
            $report['database'] = [
                'ok' => false,
                'connection' => $memory->name(),
                'database' => $memory->databaseName(),
                'detail' => $exception->getMessage().' Create a dedicated database yourself when using MySQL or MariaDB, then rerun project-memory:install. This command does not create server databases.',
            ];

            return $this->finish($report, self::FAILURE);
        }

        $existing = $schema->existingTables();
        $ready = $schema->ready();
        if ($ready) {
            $report['schema'] = 'Schema is already current. No migrations were run.';
        } elseif ($existing === [] || $this->option('migrate')) {
            $schema->install();
            $report['schema'] = $schema->ready()
                ? 'Applied pending additive migrations on the memory connection. Existing rows were not deleted.'
                : 'Migrations ran, but the schema is still incomplete. Inspect the memory connection before scanning.';
        } else {
            $report['schema'] = 'Existing tables need a newer additive migration. Back up the memory database, then rerun with --migrate. No tables were dropped.';
        }

        if ($this->option('cursor')) {
            $mcp = $cursor->mergeMcpServer(base_path());
            $rule = $cursor->publishRule(base_path());
            $report['cursor'] = [
                'mcp' => $mcp['status'],
                'rule' => $rule['status'],
            ];
        } else {
            $report['cursor'] = 'Skipped. Pass --cursor to add a portable MCP entry without replacing existing servers or rules.';
        }

        $failed = ($report['database']['ok'] ?? false) !== true
            || ! $schema->ready();

        return $this->finish($report, $failed ? self::FAILURE : self::SUCCESS);
    }

    /** @param array<string, mixed> $report */
    private function finish(array $report, int $code): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return $code;
        }

        $this->line(PackageInfo::NAME.' '.PackageInfo::VERSION);
        $this->line((string) $report['compatibility']['detail']);
        if (is_string($report['config'])) {
            $this->line($report['config']);
        }
        if (is_array($report['database'])) {
            $this->line((string) $report['database']['detail']);
        }
        if (is_string($report['schema'])) {
            $this->line($report['schema']);
        }
        if (is_string($report['cursor'])) {
            $this->line($report['cursor']);
        } elseif (is_array($report['cursor'])) {
            $this->line('MCP '.$report['cursor']['mcp'].'; rule '.$report['cursor']['rule'].'.');
        }

        return $code;
    }
}
