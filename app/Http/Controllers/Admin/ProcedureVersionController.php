<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Material;
use App\Models\Procedure;
use App\Models\ProcedureVersion;
use App\Services\Definitions\ProcedureVersioning;
use App\Services\Definitions\ProcedureVersionStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Prosedür versiyonu: taslak açma, taslak düzenleyici, yayımlanmış versiyonun salt okunur
 * görünümü ve taslağı silme (K-15, R-11, R-13). Malzeme zorunluluğu elle ayarlanmaz; beklenen
 * malzemeler listesinden türetilir (ProcedureVersionMaterialController, K-13).
 */
class ProcedureVersionController extends Controller
{
    public function __construct(private readonly ProcedureVersioning $versioning) {}

    /**
     * "Yeni taslak": en son versiyonun fazları ve adımları kopyalanır.
     */
    public function store(Procedure $procedure): RedirectResponse
    {
        $draft = $this->versioning->createDraft($procedure);

        return redirect()
            ->route('admin.procedures.versions.show', [$procedure, $draft])
            ->with('status', "v{$draft->version} taslağı oluşturuldu.");
    }

    public function show(Procedure $procedure, ProcedureVersion $version): View
    {
        $version->load(['phases.steps', 'materials.material'])->loadCount('cleanings');
        $procedure->load(['currentPublishedVersion', 'draftVersion']);

        return view('admin.procedures.versions.show', [
            'procedure' => $procedure,
            'version' => $version,
            // Taslağa eklenebilecek malzemeler: kullanımda olup listede olmayanlar (K-13).
            'materialChoices' => $version->isDraft()
                ? Material::query()->active()->whereNotIn('id', $version->materials->pluck('material_id'))->orderBy('code')->get()
                : collect(),
            'status' => ProcedureVersionStatus::of($version, $procedure->currentPublishedVersion?->id),
            'latestPublication' => $procedure->versions()->whereNotNull('published_at')->orderByDesc('published_at')->first(),
        ]);
    }

    public function destroy(Procedure $procedure, ProcedureVersion $version): RedirectResponse
    {
        $this->versioning->deleteDraft($version);

        return redirect()
            ->route('admin.procedures.show', $procedure)
            ->with('status', "v{$version->version} taslağı silindi.");
    }
}
