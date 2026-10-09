<?php

namespace App\View\Components;

use App\Models\DefinitionChange;
use App\Services\Definitions\DefinitionChangeLinks;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\View\Component;

/**
 * Tanım sayfalarındaki kısa değişiklik geçmişi (R-49): son birkaç değişiklik ve günlüğün bu
 * tanıma süzülmüş haline bağlantı. Prosedürde versiyon, faz ve adım değişiklikleri de görünür.
 *
 *   <x-definition-history :definition="$machine" />
 */
class DefinitionHistory extends Component
{
    public const LIMIT = 5;

    public function __construct(
        public Model $definition,
        public int $limit = self::LIMIT,
    ) {}

    public function render(): View
    {
        $type = DefinitionChange::typeOf($this->definition);
        $id = (int) $this->definition->getKey();

        $changes = DefinitionChange::query()
            ->ofDefinition($type, $id)
            ->with('actor:id,name')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($this->limit)
            ->get();

        return view('admin.definition-changes.history', [
            'changes' => $changes,
            'total' => DefinitionChange::query()->ofDefinition($type, $id)->count(),
            'links' => app(DefinitionChangeLinks::class)->for($changes),
            'type' => $type,
            'id' => $id,
            'url' => route('admin.definition-changes.index', ['root_type' => $type, 'root_id' => $id]),
        ]);
    }
}
