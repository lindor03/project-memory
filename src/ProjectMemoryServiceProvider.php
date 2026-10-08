<?php

namespace ProjectMemory;

use Illuminate\Support\ServiceProvider;
use ProjectMemory\Console\ContextCommand;
use ProjectMemory\Console\DoctorCommand;
use ProjectMemory\Console\EvaluateCommand;
use ProjectMemory\Console\ImpactCommand;
use ProjectMemory\Console\InstallCommand;
use ProjectMemory\Console\InstallHooksCommand;
use ProjectMemory\Console\LearnCommand;
use ProjectMemory\Console\McpCommand;
use ProjectMemory\Console\ModulesCommand;
use ProjectMemory\Console\OverviewCommand;
use ProjectMemory\Console\RevalidateCommand;
use ProjectMemory\Console\RouteCommand;
use ProjectMemory\Console\RulesCommand;
use ProjectMemory\Console\ScanCommand;
use ProjectMemory\Console\StatusCommand;
use ProjectMemory\Console\SymbolCommand;
use ProjectMemory\Console\SyncCommand;
use ProjectMemory\Console\UnresolvedCommand;
use ProjectMemory\Support\MemoryConnection;

class ProjectMemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/config/project-memory.php', 'project-memory');
        $this->app->singleton(MemoryConnection::class);
        $this->registerMemoryConnection();
    }

    public function boot(): void
    {
        if ($this->app->runningUnitTests()) {
            $this->loadMigrationsFrom(dirname(__DIR__).'/database/migrations');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                ScanCommand::class,
                SyncCommand::class,
                StatusCommand::class,
                OverviewCommand::class,
                RulesCommand::class,
                ModulesCommand::class,
                ContextCommand::class,
                SymbolCommand::class,
                ImpactCommand::class,
                LearnCommand::class,
                DoctorCommand::class,
                InstallHooksCommand::class,
                McpCommand::class,
                UnresolvedCommand::class,
                RevalidateCommand::class,
                EvaluateCommand::class,
                RouteCommand::class,
                InstallCommand::class,
            ]);
        }

        $this->publishes([
            dirname(__DIR__).'/config/project-memory.php' => config_path('project-memory.php'),
        ], 'project-memory-config');
    }

    private function registerMemoryConnection(): void
    {
        $name = config('project-memory.database.connection', 'project_memory');
        if (! is_string($name) || $name === '' || $name === 'default' || $name === config('database.default')) {
            return;
        }

        if (config('database.connections.'.$name) !== null) {
            return;
        }

        $driver = (string) config('project-memory.database.driver', 'sqlite');
        $database = (string) config('project-memory.database.database', storage_path('app/project-memory.sqlite'));

        if ($driver === 'sqlite') {
            config()->set('database.connections.'.$name, [
                'driver' => 'sqlite',
                'database' => $database,
                'prefix' => '',
                'foreign_key_constraints' => true,
                'busy_timeout' => null,
                'journal_mode' => null,
                'synchronous' => null,
            ]);

            return;
        }

        config()->set('database.connections.'.$name, [
            'driver' => $driver,
            'host' => config('project-memory.database.host', '127.0.0.1'),
            'port' => config('project-memory.database.port', '3306'),
            'database' => $database,
            'username' => (string) config('project-memory.database.username', ''),
            'password' => (string) config('project-memory.database.password', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                \PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ]);
    }
}
