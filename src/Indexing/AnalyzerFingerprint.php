<?php

namespace ProjectMemory\Indexing;

use Composer\InstalledVersions;

class AnalyzerFingerprint
{
    public function current(): string
    {
        $parts = ['format' => 2, 'modules' => config('project-memory.modules', []), 'extensions' => config('project-memory.scan.extensions', [])];
        $files = glob(__DIR__.'/*.php') ?: [];
        $files = array_merge($files, glob(dirname(__DIR__).'/Data/Extracted*.php') ?: [], [dirname(__DIR__).'/Data/FileExtraction.php', dirname(__DIR__).'/Support/NodeKeys.php']);
        sort($files);
        foreach ($files as $file) {
            $parts[basename($file)] = hash_file('sha256', $file);
        }
        $parts['parser'] = InstalledVersions::getVersion('nikic/php-parser');

        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }
}
