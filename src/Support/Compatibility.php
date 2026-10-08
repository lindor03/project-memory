<?php

namespace ProjectMemory\Support;

use Composer\InstalledVersions;
use ProjectMemory\PackageInfo;

class Compatibility
{
    /**
     * @return array{php: string, laravel: string|null, compatible: bool, detail: string}
     */
    public function check(): array
    {
        $laravel = $this->laravelVersion();
        $phpOk = PHP_VERSION_ID >= 80200;
        $laravelOk = is_string($laravel) && str_starts_with(ltrim($laravel, 'v'), '12.');

        if (! $phpOk) {
            $detail = 'PHP 8.2 or newer is required. This process is '.PHP_VERSION.'.';
        } elseif (! $laravelOk) {
            $detail = 'Laravel 12 is required. Detected '.($laravel ?? 'no illuminate/support package').'.';
        } else {
            $detail = 'PHP '.PHP_VERSION.' and Laravel '.$laravel.' satisfy '.PackageInfo::NAME.' '.PackageInfo::VERSION.'.';
        }

        return [
            'php' => PHP_VERSION,
            'laravel' => $laravel,
            'compatible' => $phpOk && $laravelOk,
            'detail' => $detail,
        ];
    }

    public function laravelVersion(): ?string
    {
        if (InstalledVersions::isInstalled('laravel/framework')) {
            return InstalledVersions::getPrettyVersion('laravel/framework');
        }

        if (InstalledVersions::isInstalled('illuminate/support')) {
            return InstalledVersions::getPrettyVersion('illuminate/support');
        }

        return null;
    }
}
