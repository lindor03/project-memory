# starter/project-memory

Local-first code intelligence for Laravel. The package indexes one application into that application's own database: modules, symbols, dependencies, architectural knowledge, change history, index runs, and retrieval metrics. Source code stays authoritative. The index is advisory. No LLM, embedding provider, or Laravel Boost installation is required.

Package version: **1.3.0**.

## Requirements

| Requirement | Constraint | Verified in this release |
| --- | --- | --- |
| PHP | `^8.2` | **8.2.28 on Windows 10** only. PHP 8.3 and 8.4 were not executed. |
| Laravel | Illuminate `^12.0` (`laravel/framework` 12.x) | **v12.69.1** in the development application and **v12.69.3** in this package's Testbench install. A fresh `laravel/laravel` 12 application was also installed on this Windows machine. Other 12.x patch versions were not executed. |
| Extensions | `ext-json`, `ext-pdo`, `ext-tokenizer` | Present in the verified PHP build. |
| Database | SQLite by default, or MySQL/MariaDB when you configure one | SQLite file and `:memory:` in package tests. The development application keeps its existing MySQL memory database. |

Do not treat the Composer constraint as a claim that every PHP 8.2+ or Laravel 12 patch release has been tested.

## Install from a local path

From the consuming application:

```bash
composer config repositories.project-memory path /absolute/or/relative/path/to/project-memory
composer require starter/project-memory:^1.3
```

Composer path repositories symlink or junction the package. Each application still gets its own index database.

## Install from a private Git repository

Initialize and tag the package locally before any remote exists. This repository does not publish a tag or create a remote as part of installation.

```bash
git tag -a v1.3.0 -m "Project Memory 1.3.0"
```

In the consuming application, point Composer at the private repository and require the tag:

```bash
composer config repositories.project-memory vcs git@example.com:example/project-memory.git
composer require starter/project-memory:^1.3
```

Use the real Git URL only after a remote exists. Do not commit credentials.

## Initialize

```bash
php artisan project-memory:install
php artisan ai:scan
php artisan ai:doctor
```

`project-memory:install` does the following:

1. Checks PHP 8.2+ and Laravel 12.
2. Publishes `config/project-memory.php` only when that file is absent.
3. Opens the configured memory connection. For the SQLite default it creates the local file if needed.
4. Detects an existing schema.
5. On an empty memory database, applies the package's additive migrations. On a database that already has memory tables but is missing a later column, it prints guidance and waits for `--migrate`.
6. Leaves existing rows in place. It does not call `migrate:fresh`, `migrate:rollback`, or `db:wipe`.
7. Succeeds again when the schema is already current.

```bash
php artisan project-memory:install --migrate
php artisan project-memory:install --cursor
```

`--migrate` applies pending `up` migrations on the memory connection only. `--cursor` adds a portable MCP server and a Cursor rule only when those entries are absent. Existing MCP servers, including Laravel Boost, and an existing `.cursor/rules/project-memory.mdc` are left untouched.

Host `php artisan migrate` does not load these migrations, so they are not applied to the application database. `ai:scan` and `ai:sync` apply any still-pending additive migrations on the memory connection before indexing.

## Database

The default connection name is `project_memory`. The default driver is SQLite and the default file is `storage/app/project-memory.sqlite` inside the consuming application. That file is not the application database. `MemoryConnection` refuses to use the application database, including two SQLite paths that resolve to the same file.

The package does not create or alter MySQL or MariaDB databases. To use a server, create an empty database yourself, then set:

```dotenv
PROJECT_MEMORY_DB_CONNECTION=project_memory
PROJECT_MEMORY_DB_DRIVER=mysql
PROJECT_MEMORY_DB_HOST=127.0.0.1
PROJECT_MEMORY_DB_PORT=3306
PROJECT_MEMORY_DB_DATABASE=project_memory
PROJECT_MEMORY_DB_USERNAME=
PROJECT_MEMORY_DB_PASSWORD=
```

In Docker, `PROJECT_MEMORY_DB_HOST` is the database service name reachable from the application container. One memory database belongs to one application checkout. A later scan refuses a stored repository path that does not match the current application. Use a different database for a different checkout.

`blocked_databases` is an optional extra deny list in config. It is empty in the package. Put application-specific database names there when a shared server has databases that must never receive this index.

Back up `ai_knowledge` and `ai_change_sets` before an upgrade. Migrations add the extraction cache and edge provenance columns. They do not delete knowledge rows or source hashes. `ai:doctor` is read-only.

## Modules

`config/project-memory.php` accepts a `modules` map. Each entry has a key, `name`, and `paths` relative to the scan base (the application root unless `scan.base_path` is set). Paths may be directories or files. Unmapped files are grouped from their relative path. The package does not ship a starter-kit module map or knowledge catalog.

```php
'modules' => [
    'billing' => [
        'name' => 'Billing',
        'paths' => ['app/Services/Billing', 'app/Models/Invoice.php'],
    ],
],
```

`knowledge.catalog` may suggest drafts. Synchronization never approves them.

## Indexing and retrieval

```bash
php artisan ai:doctor --json
php artisan ai:rules --json
php artisan ai:overview --budget=1200 --json
php artisan ai:modules --json
php artisan ai:context billing --query="invoice update" --budget=2000 --json
php artisan ai:symbol 'App\Services\Billing\InvoiceService::update' --json
php artisan ai:impact 'App\Services\Billing\InvoiceService::update' --json
php artisan ai:sync --json
```

`ai:scan` is the first full index. Later edits use `ai:sync`. Parse and publication failures keep the previous graph and record a failed run. `ai:learn` stores a draft unless `--approve` is present. Approval requires fresh indexed evidence. `--revise=<knowledge_key>` archives the previous version instead of overwriting it.

`ai:evaluate` measures retrieval against `evaluation.scenarios` in the published configuration. The package ships an empty list. `ai:route` and the `context_route` MCP tool choose evidence sources. They do not call Boost and they do not copy documentation into the memory database.

The benchmark script boots whichever Laravel application contains the current working directory, or `PROJECT_MEMORY_APP_ROOT`. It uses an in-memory SQLite database and generated files under the system temp directory:

```bash
php vendor/starter/project-memory/bin/benchmark.php 100
```

## MCP and Cursor

`php artisan ai:mcp` speaks newline-delimited JSON-RPC on stdin and stdout, and accepts Content-Length framing. Only protocol messages go to stdout. Writes require `project-memory.mcp.allow_write=true` and a literal boolean `confirm`. Shell execution is not exposed.

Portable project configuration, with `php` resolved from `PATH` and the working directory set to the application root:

```json
{
    "mcpServers": {
        "project-memory": {
            "command": "php",
            "args": ["artisan", "ai:mcp"]
        }
    }
}
```

`php artisan project-memory:install --cursor` writes that entry when `project-memory` is missing. It does not replace other servers.

When `php` is not on `PATH`, set `command` in the project file to the interpreter that should run this application. Do not copy that absolute path into the package. The launcher `vendor/bin/project-memory-mcp` uses the PHP binary that started it and finds `artisan` from the working directory or `PROJECT_MEMORY_APP_ROOT`:

```json
{
    "mcpServers": {
        "project-memory": {
            "command": "php",
            "args": ["vendor/bin/project-memory-mcp"]
        }
    }
}
```

On Windows the Composer bin proxy is `vendor/bin/project-memory-mcp.bat`. The server boots the application it finds. It does not read another application's files or database.

The same relative command works in Docker when the MCP client executes inside the application container and `php` is on that container's `PATH`. This release was not executed on Linux, macOS, or Docker.

## Laravel Boost

Boost is suggested, not required. Project Memory boots, indexes, and serves MCP when Boost is absent.

When Boost is installed:

- Use Boost `search-docs` for official Laravel, Fortify, and Sanctum documentation.
- Use Project Memory for this application's symbols, modules, impact, and approved knowledge.
- `context_route` reports `boost_installed` and names `search-docs` only as the documentation tool. It does not duplicate a documentation index.
- MCP tool names are `project_overview`, `module_context`, `symbol_lookup`, `impact_analysis`, `change_history`, `architecture_rules`, `index_status`, `context_route`, `sync_index`, and `record_knowledge`. They do not replace Boost tools.
- `--cursor` does not remove an existing `laravel-boost` MCP server.

## Upgrade

```bash
composer update starter/project-memory
php artisan project-memory:install --migrate
php artisan ai:doctor --json
```

`--migrate` runs pending additive migrations on the memory connection. Knowledge rows, revisions, and `source_hashes` stay stored. A changed analyzer fingerprint can mark evidence stale on the next `ai:sync`; that does not delete the assertion. Review stale knowledge and revise it with current evidence. Do not reset the memory database to repair it.

## Rollback

1. Restore the memory-database backup taken before the upgrade, especially `ai_knowledge` and `ai_change_sets`.
2. Require the previously verified package tag, for example `composer require starter/project-memory:1.3.0` once that tag exists.
3. Prefer the backup over `php artisan migrate:rollback`. The edge-provenance migration refuses to roll back when duplicate declaration relationships exist, because dropping those columns would discard evidence. The `down` method also drops the extraction cache.

There is no supported automatic downgrade that rewrites knowledge into an older shape.

## Release process

Semantic versions follow the Composer `version` field and `ProjectMemory\PackageInfo::VERSION`.

- Patch: diagnostics or indexing fixes that do not change stored schema or command contracts.
- Minor: additive schema, commands, or MCP fields. Version 1.3.0 is the portable package release.
- Major: removed commands, renamed MCP tools, or a migration that cannot preserve knowledge.

Before a tag:

1. Run `composer test` in this package.
2. Run the consuming application's focused project-memory tests and `php artisan ai:doctor --json`.
3. Confirm `README` compatibility matches versions that were actually executed.

Tag locally when the maintainer is ready. Do not push a remote or publish Packagist until that release is authorized:

```bash
git tag -a v1.3.0 -m "Project Memory 1.3.0"
```

## Troubleshooting

| Symptom | What to do |
| --- | --- |
| Install says Laravel 12 is required | The application is not on Illuminate 12. This package does not claim Laravel 11. |
| Memory connection cannot be opened | For MySQL or MariaDB, create the empty database yourself. Check host, port, and credentials. The install command will not create it. |
| Refusing to store project memory in the application database | Point `PROJECT_MEMORY_DB_DATABASE` at a different database or SQLite file. |
| Install stops on an existing partial schema | Back up the memory database and rerun `php artisan project-memory:install --migrate`. |
| `ai:doctor` reports a stale index | Run `php artisan ai:sync`. If the index is empty, run `php artisan ai:scan`. |
| Another repository owns this database | Use a separate memory database for the other checkout. |
| MCP client cannot start `php` | Put PHP on `PATH`, or set the project MCP `command` to that application's interpreter. |
| Cursor still shows an old server | `project-memory:install --cursor` will not overwrite an existing `project-memory` entry. Edit that project file directly. |

## Behavior preserved from the indexing engine

- Scans stay inside the configured base path, skip symlinks, and do not treat an excluded directory as a deletion.
- AST, route, Blade, and migration analyzers produce nodes and edges. Extraction cache stores summaries and metadata, not source bodies.
- Publication of the graph and knowledge invalidation commit together. A failed run keeps the previous graph.
- Impact analysis is a bounded reverse traversal plus containment.
- Context fits an estimated token budget. Token figures are UTF-8 byte estimates, not provider billing.
- MCP and CLI JSON keep the existing tool and field names. `metrics` remains additive.

## Package tests

```bash
composer install
composer test
```

The suite uses Orchestra Testbench and does not open the development application's MySQL database. A fresh Laravel 12 application is an additional manual check described in the release process; it is not committed inside this package.

## Compatibility matrix

| Surface | Status |
| --- | --- |
| PHP 8.2.28, Windows 10 | Verified for package tests and the development application. |
| Laravel 12.x as installed in those two applications | Verified on this machine during the 1.3.0 portability work. |
| PHP 8.3, PHP 8.4 | Allowed by `^8.2`, not executed. |
| Laravel 11 and earlier | Not supported. |
| Linux, macOS, Docker | The launcher and paths use PHP's directory separators and `PATH`. They were not executed on those systems. |
| Laravel Boost absent | Verified by the package suite, which does not install Boost. |
| Laravel Boost present | Verified on Windows with Boost v2.10.3 beside the package in a fresh Laravel 12 application: both command sets registered, memory stayed on that application's SQLite file, and `--cursor` left an existing Boost MCP entry in place. |
