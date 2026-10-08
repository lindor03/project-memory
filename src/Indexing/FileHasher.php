<?php

namespace ProjectMemory\Indexing;

class FileHasher
{
    private ?string $lineSource = null;

    /** @var list<string> */
    private array $sourceLines = [];

    public function file(string $absolutePath): string
    {
        $hash = hash_file('sha256', $absolutePath);

        if ($hash === false) {
            throw new \RuntimeException('Unable to hash source file ['.$absolutePath.'].');
        }

        return $hash;
    }

    public function content(string $contents): string
    {
        return hash('sha256', $contents);
    }

    public function lines(string $contents, ?int $start, ?int $end): string
    {
        if ($start === null || $end === null || $end < $start) {
            return $this->content($contents);
        }

        // Retain only the current file, never a repository-sized source cache.
        if ($this->lineSource !== $contents) {
            $this->lineSource = $contents;
            $this->sourceLines = preg_split("/\r\n|\n|\r/", $contents) ?: [];
        }
        $slice = array_slice($this->sourceLines, max(0, $start - 1), max(1, $end - $start + 1));

        return $this->content(implode("\n", $slice));
    }
}
