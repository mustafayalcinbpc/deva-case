<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Catalog\SaveMaterialLotRequest;
use App\Models\Material;
use App\Models\MaterialLot;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * Malzeme lotları (K-14, R-07, R-10). SKT lotun özelliğidir ve burada bir kez tanımlanır
 * (gerçekte depo/ERP); operatör kayıtta yalnızca kullanımdaki ve SKT'si geçmemiş lotu seçer.
 * Lot silinmez, kullanımdan kaldırılır (ör. geri çağrılan parti). Kayıtlarda kullanılan lotun
 * numarası değişmez; SKT düzeltilebilir, çünkü kayıtlar seçildiği andaki kopyayı taşır.
 */
class MaterialLotController extends Controller
{
    public function store(SaveMaterialLotRequest $request, Material $material): RedirectResponse
    {
        $lot = $material->lots()->create($request->validated());

        return $this->backToMaterial($material, "{$lot->lot_no} lotu eklendi.");
    }

    public function edit(Material $material, MaterialLot $lot): View
    {
        return view('admin.materials.lot-form', [
            'material' => $material,
            'lot' => $lot,
            'usage' => $this->usage($lot),
        ]);
    }

    public function update(SaveMaterialLotRequest $request, Material $material, MaterialLot $lot): RedirectResponse
    {
        $lot->fill($request->validated());

        // R-10: geçmiş kayıtlar lotu numarasıyla gösterir; kullanılmış lotun numarası değişmez.
        if ($lot->isDirty('lot_no') && $lot->cleaningMaterials()->exists()) {
            throw ValidationException::withMessages([
                'lot_no' => 'Bu lot kayıtlarda kullanıldığı için numarası değiştirilemez; son kullanma tarihi düzeltilebilir.',
            ]);
        }

        $lot->save();

        return $this->backToMaterial($material, "{$lot->lot_no} lotu güncellendi.");
    }

    public function deactivate(Material $material, MaterialLot $lot): RedirectResponse
    {
        $lot->update(['is_active' => false]);

        return $this->backToMaterial($material, "{$lot->lot_no} lotu kullanımdan kaldırıldı. Yeni girişlerde seçilemez; girildiği kayıtlarda görünmeye devam eder.");
    }

    public function activate(Material $material, MaterialLot $lot): RedirectResponse
    {
        $lot->update(['is_active' => true]);

        return $this->backToMaterial($material, "{$lot->lot_no} lotu yeniden kullanımda.");
    }

    private function backToMaterial(Material $material, string $status): RedirectResponse
    {
        return redirect()
            ->to(route('admin.materials.edit', $material).'#material-lots')
            ->with('status', $status);
    }

    private function usage(MaterialLot $lot): int
    {
        return $lot->cleaningMaterials()->distinct()->count('cleaning_id');
    }
}
