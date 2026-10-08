<?php

namespace ProjectMemory\Support;

class NodeKeys
{
    public static function normalize(string $key): string
    {
        $key = str_replace(["\r", "\n"], '', $key);

        if (strlen($key) <= 250) {
            return $key;
        }

        return substr($key, 0, 190).'#'.substr(hash('sha256', $key), 0, 16);
    }

    public static function absolute(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    public static function relative(string $base, string $path): string
    {
        $base = self::absolute($base);
        $path = self::absolute($path);
        $prefix = $base.'/';

        if (stripos($path, $prefix) === 0) {
            return substr($path, strlen($prefix));
        }

        return ltrim($path, '/');
    }

    public static function pathHash(string $relativePath): string
    {
        return hash('sha256', str_replace('\\', '/', $relativePath));
    }

    public static function file(string $relativePath): string
    {
        return self::normalize('file:'.str_replace('\\', '/', $relativePath));
    }

    public static function viewName(string $relativePath): ?string
    {
        $path = str_replace('\\', '/', $relativePath);
        $marker = 'resources/views/';
        $position = strpos($path, $marker);

        if ($position === false || ! str_ends_with($path, '.blade.php')) {
            return null;
        }

        $view = substr($path, $position + strlen($marker), -strlen('.blade.php'));

        if (str_starts_with($view, 'components/')) {
            return null;
        }

        return str_replace('/', '.', $view);
    }

    public static function componentName(string $relativePath): ?string
    {
        $path = str_replace('\\', '/', $relativePath);
        $marker = 'resources/views/components/';
        $position = strpos($path, $marker);

        if ($position === false || ! str_ends_with($path, '.blade.php')) {
            return null;
        }

        $name = substr($path, $position + strlen($marker), -strlen('.blade.php'));

        return str_replace('/', '.', $name);
    }
}
