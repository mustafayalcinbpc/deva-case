<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Catalog\SaveMaterialRequest;
use App\Models\Material;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Malzeme kataloğu (K-13, R-07, R-10). Kayıtlar malzemeye bağlı olduğu için malzeme silinmez;
 * kullanımdan kaldırılır. Kullanımdan kaldırılan malzeme ve lotları yeni kayıtta ve malzeme
 * ekleme formunda seçilemez, geçmiş kayıtlarda görünmeye devam eder. Lotlar malzemenin
 * sayfasında yönetilir (MaterialLotController, K-14).
 */
class MaterialController extends Controller
{
    private const PER_PAGE = 50;

    public function index(): View
    {
        return view('admin.materials.index', [
            'materials' => Material::query()
                // Kullanıldığı kayıt sayısı: aynı kayda iki kez girilen malzeme bir kez sayılır.
                ->withCount(['cleaningMaterials as cleanings_count' => fn (Builder $query) => $query
                    ->select(DB::raw('count(distinct cleaning_materials.cleaning_id)'))])
                // K-14: lot sayısı ve bugün seçilebilen (kullanımda, SKT'si geçmemiş) lot sayısı.
                ->withCount(['lots', 'lots as usable_lots_count' => fn (Builder $query) => $query
                    ->where('is_active', true)
                    ->whereDate('expiry_date', '>=', now()->toDateString())])
                ->orderByDesc('is_active')
                ->orderBy('code')
                ->paginate(self::PER_PAGE),
        ]);
    }

    public function create(): View
    {
        return view('admin.materials.form', [
            'material' => new Material(['is_active' => true]),
            'usage' => 0,
        ]);
    }

    public function store(SaveMaterialRequest $request): RedirectResponse
    {
        $material = Material::create($request->validated());

        return redirect()
            ->route('admin.materials.index')
            ->with('status', "Malzeme eklendi: {$material->code}");
    }

    public function edit(Material $material): View
    {
        return view('admin.materials.form', [
            'material' => $material,
            'usage' => $this->usage($material),
            // Lotlar SKT sırasıyla; her lotun girildiği kayıt sayısı (aynı kayda iki kez girilen bir sayılır).
            'lots' => $material->lots()
                ->withCount(['cleaningMaterials as cleanings_count' => fn (Builder $query) => $query
                    ->select(DB::raw('count(distinct cleaning_materials.cleaning_id)'))])
                ->get(),
        ]);
    }

    public function update(SaveMaterialRequest $request, Material $material): RedirectResponse
    {
        $material->fill($request->validated());

        // R-10: geçmiş kayıtlar malzemeyi koduyla gösterir; kullanılmış malzemenin kodu değişmez.
        if ($material->isDirty('code') && $material->cleaningMaterials()->exists()) {
            throw ValidationException::withMessages([
                'code' => 'Bu malzeme kayıtlarda kullanıldığı için kodu değiştirilemez.',
            ]);
        }

        $material->save();

        return redirect()
            ->route('admin.materials.index')
            ->with('status', "Malzeme güncellendi: {$material->code}");
    }

    public function deactivate(Material $material): RedirectResponse
    {
        $material->update(['is_active' => false]);

        return redirect()
            ->route('admin.materials.index')
            ->with('status', "{$material->code} kullanımdan kaldırıldı. Yeni girişlerde seçilemez; geçmiş kayıtlarda görünmeye devam eder.");
    }

    public function activate(Material $material): RedirectResponse
    {
        $material->update(['is_active' => true]);

        return redirect()
            ->route('admin.materials.index')
            ->with('status', "{$material->code} yeniden kullanımda.");
    }

    private function usage(Material $material): int
    {
        return $material->cleaningMaterials()->distinct()->count('cleaning_id');
    }
}
