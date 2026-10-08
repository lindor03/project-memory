<?php

namespace ProjectMemory\Indexing;

use ProjectMemory\Support\NodeKeys;

class SourceScanner
{
    private array $warnings = [];

    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @return list<array{path: string, absolute: string, bytes: int}> */
    public function discover(): array
    {
        $this->warnings = [];
        $configured = (string) config('project-memory.scan.base_path', base_path());
        $resolved = realpath($configured);
        if ($resolved === false || ! is_dir($resolved)) {
            throw new \RuntimeException('Scan base directory is unavailable: '.$configured);
        }
        $base = NodeKeys::absolute($resolved);
        $exclude = config('project-memory.scan.exclude', []);
        $maxBytes = (int) config('project-memory.scan.max_bytes', 512000);
        $found = [];
        foreach (config('project-memory.scan.roots', []) as $root) {
            $root = trim(str_replace('\\', '/', (string) $root), '/');
            if ($root === '' || in_array('..', explode('/', $root), true) || str_contains($root, ':')) {
                throw new \RuntimeException('Scan roots must be nonempty paths relative to the repository: '.$root);
            }
            $absolute = $base.'/'.$root;
            if (is_link($absolute) || $this->excluded($root, $exclude)) {
                continue;
            }
            $canonical = realpath($absolute);
            $canonicalPath = $canonical === false ? null : NodeKeys::absolute($canonical);
            $inside = $canonicalPath === null || (PHP_OS_FAMILY === 'Windows'
                ? str_starts_with(strtolower($canonicalPath), strtolower($base.'/'))
                : str_starts_with($canonicalPath, $base.'/'));
            if (! $inside) {
                throw new \RuntimeException('Scan root resolves outside the repository: '.$root);
            }
            if (is_file($absolute)) {
                $this->consider($absolute, $base, $exclude, $maxBytes, $found);

                continue;
            }
            if (! is_dir($absolute)) {
                $this->warnings[] = 'Scan root is absent: '.$root;

                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS),
                fn (\SplFileInfo $file) => ! $file->isLink() && ! $this->excluded(NodeKeys::relative($base, $file->getPathname()), $exclude)
            ));
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $this->consider($file->getPathname(), $base, $exclude, $maxBytes, $found);
                }
            }
        }
        ksort($found);

        return array_values($found);
    }

    private function consider(string $absolute, string $base, array $exclude, int $maxBytes, array &$found): void
    {
        $relative = NodeKeys::relative($base, $absolute);
        if ($this->excluded($relative, $exclude) || ! $this->accepted($relative)) {
            return;
        }
        $bytes = filesize($absolute);
        if ($bytes === false || ! is_readable($absolute)) {
            throw new \RuntimeException('Source file is unreadable: '.$relative);
        }
        if ($bytes > $maxBytes) {
            $this->warnings[] = 'Source exceeds scan.max_bytes and was retained in the previous index: '.$relative;

            return;
        }
        $found[$relative] = ['path' => $relative, 'absolute' => $absolute, 'bytes' => $bytes];
    }

    private function accepted(string $relative): bool
    {
        if (str_starts_with(basename($relative), '.env')) {
            return false;
        }
        foreach (config('project-memory.scan.extensions', ['php', 'blade.php']) as $extension) {
            if (str_ends_with($relative, '.'.ltrim((string) $extension, '.'))) {
                return true;
            }
        }

        return false;
    }

    private function excluded(string $relative, array $exclude): bool
    {
        $relative = trim(str_replace('\\', '/', $relative), '/');
        foreach ($exclude as $pattern) {
            $pattern = trim(str_replace('\\', '/', (string) $pattern), '/');
            if ($pattern !== '' && ($relative === $pattern || str_starts_with($relative, $pattern.'/') || (! str_contains($pattern, '/') && in_array($pattern, explode('/', $relative), true)))) {
                return true;
            }
        }

        return false;
    }
}
