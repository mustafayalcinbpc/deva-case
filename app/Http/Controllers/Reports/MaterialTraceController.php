<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Material;
use App\Services\Reports\MaterialTrace;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Malzeme izlenebilirliği (R-10): malzeme ve/veya lot numarasıyla arama; o malzemenin
 * kullanıldığı kayıtlar, geçerli ya da gerekçesiyle geçersiz kılınmış olarak.
 */
class MaterialTraceController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(private readonly MaterialTrace $trace) {}

    public function index(Request $request): View
    {
        $materialId = filter_var($request->query('material_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $materialId = $materialId === false ? null : $materialId;
        $lot = $request->query('lot');
        $lot = is_string($lot) && trim($lot) !== '' ? Str::limit(trim($lot), 100, '') : null;
        $searched = $materialId !== null || $lot !== null;

        return view('reports.materials', [
            'catalog' => Material::query()->orderBy('code')->get(),
            'materialId' => $materialId,
            'lot' => $lot,
            'searched' => $searched,
            'results' => $searched ? $this->trace->search($materialId, $lot, self::PER_PAGE) : null,
        ]);
    }
}
