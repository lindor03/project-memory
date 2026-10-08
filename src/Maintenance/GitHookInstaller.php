<?php

namespace ProjectMemory\Maintenance;

class GitHookInstaller
{
    public function install(): string
    {
        $git = base_path('.git');
        if (! is_dir($git)) {
            return 'No .git directory was found. Hooks were not installed.';
        }

        $hook = $git.DIRECTORY_SEPARATOR.'hooks'.DIRECTORY_SEPARATOR.'post-commit';
        $marker = 'project-memory ai:sync';
        $snippet = "\n# {$marker}\nphp artisan ai:sync --quiet || true\n";

        if (! is_dir(dirname($hook))) {
            mkdir(dirname($hook), 0755, true);
        }

        if (is_file($hook) && str_contains((string) file_get_contents($hook), $marker)) {
            return 'The post-commit hook already calls project memory.';
        }

        if (! is_file($hook)) {
            file_put_contents($hook, "#!/bin/sh\n".$snippet);
        } else {
            file_put_contents($hook, rtrim((string) file_get_contents($hook)).$snippet);
        }

        @chmod($hook, 0755);

        return 'Appended ai:sync to the post-commit hook without removing existing hook commands.';
    }
}
