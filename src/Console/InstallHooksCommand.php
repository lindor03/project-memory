<?php

namespace ProjectMemory\Console;

use ProjectMemory\Maintenance\GitHookInstaller;

class InstallHooksCommand extends ProjectMemoryCommand
{
    protected $signature = 'ai:install-hooks {--json : Machine-readable output}';

    protected $description = 'Append an incremental project-memory sync to the Git post-commit hook';

    public function handle(GitHookInstaller $installer): int
    {
        $message = $installer->install();

        return $this->emit(['text' => $message."\n", 'message' => $message]);
    }
}
