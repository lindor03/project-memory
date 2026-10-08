<?php

namespace ProjectMemory\Indexing;

use ProjectMemory\Data\ExtractedEdge;
use ProjectMemory\Data\FileExtraction;
use ProjectMemory\Support\NodeKeys;

class DependencyIndexer
{
    private const RELATIONSHIPS = [
        'calls',
        'extends',
        'implements',
        'uses_trait',
        'depends_on',
        'renders_view',
        'uses_model',
        'references_route',
        'dispatches_event',
        'listens_to_event',
        'uses_component',
        'defines_table',
        'uses_table',
        'defines',
        'contains',
        'binds',
        'contextual_binding',
        'observes',
        'uses_middleware',
    ];

    public function enrich(FileExtraction $extraction): void
    {
        $fileKey = NodeKeys::file($extraction->relativePath);

        foreach ($extraction->nodes as $node) {
            if ($node->shared) {
                continue;
            }

            $extraction->addEdge(new ExtractedEdge(
                $fileKey,
                $node->key,
                $node->type,
                $node->symbol,
                'defines',
                1.0,
            ));
        }

        foreach ($extraction->edges as $id => $edge) {
            if (! in_array($edge->relationship, self::RELATIONSHIPS, true)) {
                unset($extraction->edges[$id]);
            }
        }
    }
}
