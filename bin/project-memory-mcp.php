#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Portable MCP launcher. It boots artisan in the consuming application and
 * does not embed an absolute PHP path. Set PROJECT_MEMORY_APP_ROOT when the
 * process working directory is not the application (or a parent of it).
 */
$root = getenv('PROJECT_MEMORY_APP_ROOT');
$root = is_string($root) ? $root : '';

if ($root === '' || ! is_file($root.DIRECTORY_SEPARATOR.'artisan')) {
    $root = getcwd() ?: '';
    $guard = 0;
    while ($root !== '' && $root !== dirname($root) && ! is_file($root.DIRECTORY_SEPARATOR.'artisan') && $guard < 25) {
        $root = dirname($root);
        $guard++;
    }
}

$artisan = $root.DIRECTORY_SEPARATOR.'artisan';
if (! is_file($artisan)) {
    fwrite(STDERR, "project-memory MCP could not find artisan. Run it from the application root or set PROJECT_MEMORY_APP_ROOT.\n");
    exit(1);
}

$php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
$command = escapeshellarg($php).' '.escapeshellarg($artisan).' ai:mcp';
passthru($command, $exitCode);
exit((int) $exitCode);
