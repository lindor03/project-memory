<?php

namespace ProjectMemory\Models;

use Illuminate\Database\Eloquent\Model;
use ProjectMemory\Support\MemoryConnection;

abstract class MemoryModel extends Model
{
    public function getConnectionName(): ?string
    {
        $memory = app(MemoryConnection::class);
        $memory->assertSafe();

        return $memory->name();
    }
}
