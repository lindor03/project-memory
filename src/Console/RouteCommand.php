<?php

namespace ProjectMemory\Console;

use ProjectMemory\Context\ContextRouter;

class RouteCommand extends ProjectMemoryCommand
{
    protected $signature = 'ai:route {task* : The development task to route} {--json : Machine-readable output}';

    protected $description = 'Choose project memory, Laravel Boost, source inspection, or a combination';

    public function handle(ContextRouter $router): int
    {
        $task = trim(implode(' ', (array) $this->argument('task')));
        if ($task === '') {
            return $this->emit([
                'error' => 'missing_task',
                'text' => "Provide a task sentence.\n",
            ], self::FAILURE);
        }
        $route = $router->route($task);

        return $this->emit($route + ['task' => $task, 'text' => $route['reason']."\n"]);
    }
}
