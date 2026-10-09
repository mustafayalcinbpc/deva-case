<?php

namespace App\Services\Definitions;

use App\Models\DefinitionChange;
use App\Models\Facility;
use App\Models\Line;
use App\Models\Machine;
use App\Models\Material;
use App\Models\Procedure;
use App\Models\ProcedurePhase;
use App\Models\ProcedureStep;
use App\Models\ProcedureVersion;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Değişiklik günlüğü satırlarından tanımın yönetim sayfasına bağlantı. Faz ve adım, versiyon
 * sayfasındaki yerine bağlanır. Tanım silinmişse (taslak faz, adım, versiyon) kökünün, ör.
 * prosedürün sayfasına; o da yoksa bağlantı verilmez. Bir sayfadaki satırlar için tür başına
 * tek sorgu atılır.
 */
final class DefinitionChangeLinks
{
    /** Kendi kimliğiyle açılan sayfalar: tür → [model, route]. */
    private const PAGES = [
        'facility' => [Facility::class, 'admin.facilities.edit'],
        'line' => [Line::class, 'admin.lines.edit'],
        'machine' => [Machine::class, 'admin.machines.show'],
        'procedure' => [Procedure::class, 'admin.procedures.show'],
        'material' => [Material::class, 'admin.materials.edit'],
        'work_order' => [WorkOrder::class, 'admin.work-orders.edit'],
        'user' => [User::class, 'admin.users.edit'],
    ];

    /**
     * @param  Collection<int, DefinitionChange>  $changes
     * @return array<int, ?string> satır kimliği → adres
     */
    public function for(Collection $changes): array
    {
        $wanted = [];

        foreach ($changes as $change) {
            $wanted[$change->subject_type][$change->subject_id] = true;
            $wanted[$change->root_type][$change->root_id] = true;
        }

        $urls = [];

        foreach ($wanted as $type => $ids) {
            foreach ($this->urls($type, array_keys($ids)) as $id => $url) {
                $urls["{$type}:{$id}"] = $url;
            }
        }

        return $changes->mapWithKeys(fn (DefinitionChange $change) => [
            $change->id => $urls["{$change->subject_type}:{$change->subject_id}"]
                ?? $urls["{$change->root_type}:{$change->root_id}"]
                ?? null,
        ])->all();
    }

    /**
     * Tek bir tanımın sayfası; tanım yoksa NULL.
     */
    public function definition(string $type, int $id): ?string
    {
        return $this->urls($type, [$id])[$id] ?? null;
    }

    /**
     * Var olan tanımların adresleri.
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function urls(string $type, array $ids): array
    {
        if (isset(self::PAGES[$type])) {
            [$model, $route] = self::PAGES[$type];

            return $model::query()->whereKey($ids)->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => route($route, $id)])
                ->all();
        }

        return match ($type) {
            'procedure_version' => ProcedureVersion::query()->whereKey($ids)->get(['id', 'procedure_id'])
                ->mapWithKeys(fn (ProcedureVersion $version) => [$version->id => $this->versionUrl($version)])
                ->all(),
            'procedure_phase' => ProcedurePhase::query()->whereKey($ids)
                ->with('version:id,procedure_id')
                ->get(['id', 'procedure_version_id'])
                ->mapWithKeys(fn (ProcedurePhase $phase) => [$phase->id => $this->versionUrl($phase->version, "phase-{$phase->id}")])
                ->all(),
            'procedure_step' => ProcedureStep::query()->whereKey($ids)
                ->with('phase:id,procedure_version_id', 'phase.version:id,procedure_id')
                ->get(['id', 'procedure_phase_id'])
                ->mapWithKeys(fn (ProcedureStep $step) => [$step->id => $this->versionUrl($step->phase->version, "step-{$step->id}")])
                ->all(),
            default => [],
        };
    }

    private function versionUrl(Model $version, ?string $anchor = null): string
    {
        $url = route('admin.procedures.versions.show', [$version->procedure_id, $version->id]);

        return $anchor === null ? $url : "{$url}#{$anchor}";
    }
}
