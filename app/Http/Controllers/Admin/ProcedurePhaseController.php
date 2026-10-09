<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Procedures\ProcedurePhaseRequest;
use App\Models\Procedure;
use App\Models\ProcedurePhase;
use App\Models\ProcedureVersion;
use App\Services\Definitions\ProcedureVersioning;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Taslak versiyonun fazları (R-03, R-06, K-02): ekleme, düzenleme, silme ve sıra değiştirme.
 */
class ProcedurePhaseController extends Controller
{
    public function __construct(private readonly ProcedureVersioning $versioning) {}

    public function store(ProcedurePhaseRequest $request, Procedure $procedure, ProcedureVersion $version): RedirectResponse
    {
        $phase = $this->versioning->addPhase($version, $request->phaseAttributes());

        return $this->backToEditor($procedure, $version, $phase, "“{$phase->name}” fazı eklendi.");
    }

    public function edit(Procedure $procedure, ProcedureVersion $version, ProcedurePhase $phase): View
    {
        $this->versioning->assertDraft($version);

        return view('admin.procedures.phases.edit', [
            'procedure' => $procedure,
            'version' => $version,
            'phase' => $phase,
        ]);
    }

    public function update(ProcedurePhaseRequest $request, Procedure $procedure, ProcedureVersion $version, ProcedurePhase $phase): RedirectResponse
    {
        $this->versioning->updatePhase($phase, $request->phaseAttributes());

        return $this->backToEditor($procedure, $version, $phase, "“{$phase->name}” fazı güncellendi.");
    }

    public function destroy(Procedure $procedure, ProcedureVersion $version, ProcedurePhase $phase): RedirectResponse
    {
        $this->versioning->deletePhase($phase);

        return $this->backToEditor($procedure, $version, null, "“{$phase->name}” fazı adımlarıyla birlikte silindi.");
    }

    public function move(Procedure $procedure, ProcedureVersion $version, ProcedurePhase $phase, string $direction): RedirectResponse
    {
        $this->versioning->movePhase($phase, $direction);

        return $this->backToEditor($procedure, $version, $phase);
    }

    private function backToEditor(Procedure $procedure, ProcedureVersion $version, ?ProcedurePhase $phase, ?string $status = null): RedirectResponse
    {
        $url = route('admin.procedures.versions.show', [$procedure, $version]).($phase !== null ? "#phase-{$phase->id}" : '');

        return redirect()->to($url)->with('status', $status);
    }
}
