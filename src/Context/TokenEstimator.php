<?php

namespace ProjectMemory\Context;

class TokenEstimator
{
    public function estimate(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        $chars = $this->charsPerToken();

        return (int) ceil(strlen($text) / $chars);
    }

    public function charsPerToken(): int
    {
        return max(1, (int) config('project-memory.context.chars_per_token', 4));
    }

    public function truncate(string $text, int $bytes): string
    {
        if ($bytes <= 0) {
            return '';
        }

        return mb_strcut($text, 0, $bytes, 'UTF-8');
    }
}
