<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'line_id', 'machine_id', 'description'])]
class WorkOrder extends Model
{
    /**
     * İş emri makineye ya da hatta bağlıysa, yalnızca o makinedeki temizliklerde kullanılabilir.
     */
    public function isUsableFor(Machine $machine): bool
    {
        return ($this->machine_id === null || $this->machine_id === $machine->id)
            && ($this->line_id === null || $this->line_id === $machine->line_id);
    }
}
