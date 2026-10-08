<?php

namespace ProjectMemory\Context;

use ProjectMemory\Data\ContextItem;

class ContextRanker
{
    /**
     * @param  list<ContextItem>  $items
     * @return list<ContextItem>
     */
    public function sort(array $items): array
    {
        usort($items, function (ContextItem $left, ContextItem $right) {
            return $right->score <=> $left->score ?: strcmp($left->id, $right->id);
        });

        $unique = [];
        $texts = [];
        foreach ($items as $item) {
            $fingerprint = hash('sha256', preg_replace('/\s+/u', ' ', trim($item->text)) ?? $item->text);
            if (isset($unique[$item->id]) || isset($texts[$fingerprint])) {
                continue;
            }
            $unique[$item->id] = $item;
            $texts[$fingerprint] = true;
        }

        return array_values($unique);
    }

    /** @param list<string> $fields */
    public function relevance(string $query, array $fields): int
    {
        $terms = $this->terms($query);
        if ($terms === []) {
            return 0;
        }

        $haystack = $this->terms(implode(' ', $fields));
        $matches = count(array_intersect($terms, $haystack));
        $score = (int) round(60 * $matches / count($terms));
        foreach ($fields as $field) {
            if (strcasecmp(trim($query), trim($field)) === 0) {
                return $score + 80;
            }
        }

        return $score;
    }

    /** @return list<string> */
    public function terms(string $query): array
    {
        $query = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $query) ?? $query;
        $terms = preg_split('/[^\pL\pN]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_slice($terms, 0, 12)));
    }
}
