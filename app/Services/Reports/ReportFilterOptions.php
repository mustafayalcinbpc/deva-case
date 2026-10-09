<?php

namespace App\Services\Reports;

use App\Enums\CleaningType;
use App\Models\Facility;
use App\Models\Line;
use App\Models\Machine;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;

/**
 * Rapor filtrelerindeki seçenekler. Geçmiş kayıtlar raporlandığı için kullanımdan kaldırılmış
 * makineler de listelenir (K-16).
 */
final class ReportFilterOptions
{
    /**
     * @return array{facilities: Collection<int, Facility>, lineGroups: BaseCollection<string, Collection<int, Line>>, machineGroups: BaseCollection<string, Collection<int, Machine>>, types: list<CleaningType>}
     */
    public function all(): array
    {
        $facilities = Facility::query()->orderBy('code')->get();

        $lines = Line::query()
            ->join('facilities', 'facilities.id', '=', 'lines.facility_id')
            ->select('lines.*')
            ->orderBy('facilities.code')
            ->orderBy('lines.code')
            ->with('facility')
            ->get();

        $machines = Machine::query()
            ->join('lines', 'lines.id', '=', 'machines.line_id')
            ->join('facilities', 'facilities.id', '=', 'lines.facility_id')
            ->select('machines.*')
            ->orderBy('facilities.code')
            ->orderBy('lines.code')
            ->orderBy('machines.code')
            ->with('line.facility')
            ->get();

        return [
            'facilities' => $facilities,
            'lineGroups' => $lines->groupBy(fn (Line $line) => $line->facility->name),
            'machineGroups' => $machines->groupBy(fn (Machine $machine) => "{$machine->line->facility->name} / {$machine->line->name}"),
            'types' => CleaningType::cases(),
        ];
    }
}
