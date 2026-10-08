<?php

return [

    /*
    | Each application keeps its own index. The default is a SQLite file under
    | this application's storage directory, separate from the application
    | database. Set PROJECT_MEMORY_DB_DRIVER=mysql (or mariadb) only after you
    | create that database yourself. This package does not create server databases.
    */
    'database' => [
        'connection' => env('PROJECT_MEMORY_DB_CONNECTION', 'project_memory'),
        'driver' => env('PROJECT_MEMORY_DB_DRIVER', 'sqlite'),
        'host' => env('PROJECT_MEMORY_DB_HOST', '127.0.0.1'),
        'port' => env('PROJECT_MEMORY_DB_PORT', '3306'),
        'database' => env('PROJECT_MEMORY_DB_DATABASE', storage_path('app/project-memory.sqlite')),
        'username' => env('PROJECT_MEMORY_DB_USERNAME'),
        'password' => env('PROJECT_MEMORY_DB_PASSWORD'),
        'blocked_databases' => [],
    ],

    'scan' => [
        'base_path' => base_path(),
        'roots' => [
            'app',
            'routes',
            'database/migrations',
            'resources/views',
            'config',
            'tests',
        ],
        'extensions' => ['php', 'blade.php'],
        'exclude' => [
            'vendor',
            'node_modules',
            '.git',
            'storage',
            'bootstrap/cache',
            'public',
            'tests/Fixtures',
        ],
        'max_bytes' => 512000,
    ],

    /*
    | Optional module map. Keys are module identifiers. Each module may define
    | name, paths (relative to the scan base), and root_path. Unlisted files
    | are grouped from their relative path.
    */
    'modules' => [],

    /*
    | Host-authored drafts. The package does not ship application knowledge.
    | catalog entries are suggestions only; synchronization never approves them.
    */
    'knowledge' => [
        'catalog' => [],
        'module_summaries' => [],
    ],

    /*
    | Reviewed retrieval scenarios for ai:evaluate. Leave empty until this
    | application has expectations checked against its own source.
    */
    'evaluation' => [
        'scenarios' => [],
    ],

    'index' => [
        'lock_seconds' => 3600,
        'cache_retention_days' => 30,
    ],

    'impact' => [
        'max_depth' => 2,
        'max_nodes' => 30,
    ],

    'context' => [
        'budget' => 4000,
        'chars_per_token' => 4,
        'max_budget' => 32000,
        'candidate_limit' => 160,
        'knowledge_limit' => 160,
    ],

    'mcp' => [
        'allow_write' => false,
    ],

];
