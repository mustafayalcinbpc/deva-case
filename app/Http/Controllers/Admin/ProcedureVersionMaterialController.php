<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Procedures\ProcedureVersionMaterialRequest;
use App\Models\Procedure;
use App\Models\ProcedureVersion;
use App\Models\ProcedureVersionMaterial;
use App\Services\Definitions\ProcedureVersioning;
use Illuminate\Http\RedirectResponse;

/**
 * Taslak versiyonun beklediği malzemeler (K-12, K-13): ekleme, zorunlu/isteğe bağlı yapma,
 * çıkarma ve sıra değiştirme. Yayımlanmış versiyonun listesi değişmez (K-15).
 */
class ProcedureVersionMaterialController extends Controller
{
    public function __construct(private readonly ProcedureVersioning $versioning) {}

    public function store(ProcedureVersionMaterialRequest $request, Procedure $procedure, ProcedureVersion $version): RedirectResponse
    {
        $material = $request->material();
        $this->versioning->addMaterial($version, $material, $request->isRequired());

        return $this->backToEditor($procedure, $version, "{$material->code} listeye eklendi.");
    }

    public function update(ProcedureVersionMaterialRequest $request, Procedure $procedure, ProcedureVersion $version, ProcedureVersionMaterial $material): RedirectResponse
    {
        $required = $request->isRequired();
        $this->versioning->updateMaterial($material, $required);

        return $this->backToEditor($procedure, $version, "{$material->material->code} artık ".($required ? 'zorunlu.' : 'isteğe bağlı.'));
    }

    public function destroy(Procedure $procedure, ProcedureVersion $version, ProcedureVersionMaterial $material): RedirectResponse
    {
        $this->versioning->removeMaterial($material);

        return $this->backToEditor($procedure, $version, "{$material->material->code} listeden çıkarıldı.");
    }

    public function move(Procedure $procedure, ProcedureVersion $version, ProcedureVersionMaterial $material, string $direction): RedirectResponse
    {
        $this->versioning->moveMaterial($material, $direction);

        return $this->backToEditor($procedure, $version);
    }

    private function backToEditor(Procedure $procedure, ProcedureVersion $version, ?string $status = null): RedirectResponse
    {
        return redirect()
            ->to(route('admin.procedures.versions.show', [$procedure, $version]).'#procedure-materials')
            ->with('status', $status);
    }
}
