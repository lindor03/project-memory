<?php

namespace ProjectMemory\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use ProjectMemory\ProjectMemoryServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ProjectMemoryServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('project-memory.database.connection', 'sqlite');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('project-memory.modules', []);
        $app['config']->set('project-memory.knowledge.catalog', []);
        $app['config']->set('project-memory.evaluation.scenarios', []);
    }
}
