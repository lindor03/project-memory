<?php

namespace ProjectMemory\Console;

use ProjectMemory\Models\AiCodeNode;
use ProjectMemory\Models\AiModule;
use ProjectMemory\Schema\SchemaInstaller;

class ModulesCommand extends ProjectMemoryCommand
{
    protected $signature = 'ai:modules {--json : Machine-readable output}';

    protected $description = 'List modules recognized by project memory';

    public function handle(SchemaInstaller $schema): int
    {
        if (! $schema->ready()) {
            return $this->emit(['text' => "Project memory is not initialized.\n", 'modules' => []], self::FAILURE);
        }

        $counts = AiCodeNode::query()->where('node_type', '!=', 'file')
            ->selectRaw('module_id, COUNT(*) AS aggregate')->groupBy('module_id')->pluck('aggregate', 'module_id');
        $modules = AiModule::query()->orderBy('key')->get()->map(fn (AiModule $module) => [
            'key' => $module->key,
            'name' => $module->name,
            'root_path' => $module->root_path,
            'symbols' => (int) $counts->get($module->id, 0),
        ])->all();

        $lines = array_map(fn (array $module) => $module['key'].'  '.$module['name'].'  '.$module['symbols'], $modules);

        return $this->emit([
            'text' => ($lines === [] ? 'No modules indexed.' : implode("\n", $lines))."\n",
            'modules' => $modules,
        ]);
    }
}
