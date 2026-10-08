<?php

namespace ProjectMemory\Integration;

class CursorConfiguration
{
    /**
     * @return array{status: string, path: string}
     */
    public function mergeMcpServer(string $projectRoot): array
    {
        $directory = rtrim($projectRoot, '\\/').DIRECTORY_SEPARATOR.'.cursor';
        $path = $directory.DIRECTORY_SEPARATOR.'mcp.json';
        $entry = [
            'command' => 'php',
            'args' => ['artisan', 'ai:mcp'],
        ];

        if (! is_file($path)) {
            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
            $this->write($path, ['mcpServers' => ['project-memory' => $entry]]);

            return ['status' => 'created', 'path' => $path];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return ['status' => 'unchanged_invalid', 'path' => $path];
        }

        $servers = $decoded['mcpServers'] ?? [];
        if (! is_array($servers)) {
            return ['status' => 'unchanged_invalid', 'path' => $path];
        }

        if (array_key_exists('project-memory', $servers)) {
            return ['status' => 'unchanged_existing', 'path' => $path];
        }

        $servers['project-memory'] = $entry;
        $decoded['mcpServers'] = $servers;
        $this->write($path, $decoded);

        return ['status' => 'added', 'path' => $path];
    }

    /**
     * @return array{status: string, path: string}
     */
    public function publishRule(string $projectRoot): array
    {
        $directory = rtrim($projectRoot, '\\/').DIRECTORY_SEPARATOR.'.cursor'.DIRECTORY_SEPARATOR.'rules';
        $path = $directory.DIRECTORY_SEPARATOR.'project-memory.mdc';
        if (is_file($path)) {
            return ['status' => 'unchanged_existing', 'path' => $path];
        }

        $stub = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'stubs'.DIRECTORY_SEPARATOR.'project-memory.mdc';
        if (! is_file($stub)) {
            return ['status' => 'missing_stub', 'path' => $path];
        }

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        copy($stub, $path);

        return ['status' => 'created', 'path' => $path];
    }

    /** @param array<string, mixed> $payload */
    private function write(string $path, array $payload): void
    {
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    }
}
