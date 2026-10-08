<?php

namespace ProjectMemory\Indexing;

use ProjectMemory\Models\AiModule;

class ModuleDiscoverer
{
    /**
     * @return array{key: string, name: string, root_path: ?string}
     */
    public function assign(string $relativePath): array
    {
        $relativePath = str_replace('\\', '/', $relativePath);
        $best = null;
        $bestLength = -1;

        foreach (config('project-memory.modules', []) as $key => $module) {
            $paths = $module['paths'] ?? [];
            foreach ($paths as $path) {
                $path = trim(str_replace('\\', '/', (string) $path), '/');
                if ($path === '') {
                    continue;
                }

                $matches = $relativePath === $path || str_starts_with($relativePath, $path.'/');
                if ($matches && strlen($path) > $bestLength) {
                    $bestLength = strlen($path);
                    $best = [
                        'key' => (string) $key,
                        'name' => (string) ($module['name'] ?? $key),
                        'root_path' => isset($module['root_path']) ? (string) $module['root_path'] : $this->commonRoot($paths),
                    ];
                }
            }
        }

        if ($best !== null) {
            return $best;
        }

        $parts = explode('/', $relativePath);
        $key = $parts[0].(isset($parts[1]) ? '-'.$parts[1] : '');

        return [
            'key' => $key,
            'name' => str_replace('-', ' ', $key),
            'root_path' => implode('/', array_slice($parts, 0, min(2, count($parts) - 1))) ?: $parts[0],
        ];
    }

    public function ensure(string $relativePath): AiModule
    {
        $assigned = $this->assign($relativePath);

        return AiModule::query()->updateOrCreate(
            ['key' => $assigned['key']],
            [
                'name' => $assigned['name'],
                'root_path' => $assigned['root_path'],
                'metadata' => array_merge(AiModule::query()->where('key', $assigned['key'])->value('metadata') ?? [], [
                    'paths' => config('project-memory.modules.'.$assigned['key'].'.paths', [$assigned['root_path']]),
                ]),
            ],
        );
    }

    private function commonRoot(array $paths): ?string
    {
        $parts = null;
        foreach ($paths as $path) {
            $segments = explode('/', trim(str_replace('\\', '/', (string) $path), '/'));
            if (str_contains(end($segments), '.')) {
                array_pop($segments);
            }
            if ($parts === null) {
                $parts = $segments;
            } else {
                foreach ($parts as $i => $part) {
                    if (($segments[$i] ?? null) !== $part) {
                        $parts = array_slice($parts, 0, $i);
                        break;
                    }
                }
            }
        }

        return $parts === null || $parts === [] ? null : implode('/', $parts);
    }
}
