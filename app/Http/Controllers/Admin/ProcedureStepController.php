<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Procedures\ProcedureStepRequest;
use App\Models\Procedure;
use App\Models\ProcedurePhase;
use App\Models\ProcedureStep;
use App\Models\ProcedureVersion;
use App\Services\Definitions\ProcedureVersioning;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Taslak versiyonun bir fazındaki adımlar (R-03, R-04) ve adım medyası (R-05): ekleme,
 * düzenleme, medyayı değiştirme ya da kaldırma, silme ve sıra değiştirme.
 */
class ProcedureStepController extends Controller
{
    public function __construct(private readonly ProcedureVersioning $versioning) {}

    public function create(Procedure $procedure, ProcedureVersion $version, ProcedurePhase $phase): View
    {
        $this->versioning->assertDraft($version);

        return view('admin.procedures.steps.form', [
            'procedure' => $procedure,
            'version' => $version,
            'phase' => $phase,
            'step' => new ProcedureStep,
        ]);
    }

    public function store(ProcedureStepRequest $request, Procedure $procedure, ProcedureVersion $version, ProcedurePhase $phase): RedirectResponse
    {
        $step = $this->versioning->addStep($phase, $request->stepAttributes(), $request->media());

        return $this->backToEditor($procedure, $version, $step, "“{$step->title}” adımı eklendi.");
    }

    public function edit(Procedure $procedure, ProcedureVersion $version, ProcedurePhase $phase, ProcedureStep $step): View
    {
        $this->versioning->assertDraft($version);

        return view('admin.procedures.steps.form', [
            'procedure' => $procedure,
            'version' => $version,
            'phase' => $phase,
            'step' => $step,
        ]);
    }

    public function update(ProcedureStepRequest $request, Procedure $procedure, ProcedureVersion $version, ProcedurePhase $phase, ProcedureStep $step): RedirectResponse
    {
        $this->versioning->updateStep($step, $request->stepAttributes(), $request->media(), $request->removeMedia());

        return $this->backToEditor($procedure, $version, $step, "“{$step->title}” adımı güncellendi.");
    }

    public function destroy(Procedure $procedure, ProcedureVersion $version, ProcedurePhase $phase, ProcedureStep $step): RedirectResponse
    {
        $this->versioning->deleteStep($step);

        return redirect()
            ->to(route('admin.procedures.versions.show', [$procedure, $version])."#phase-{$phase->id}")
            ->with('status', "“{$step->title}” adımı silindi.");
    }

    public function move(Procedure $procedure, ProcedureVersion $version, ProcedurePhase $phase, ProcedureStep $step, string $direction): RedirectResponse
    {
        $this->versioning->moveStep($step, $direction);

        return $this->backToEditor($procedure, $version, $step);
    }

    private function backToEditor(Procedure $procedure, ProcedureVersion $version, ProcedureStep $step, ?string $status = null): RedirectResponse
    {
        return redirect()
            ->to(route('admin.procedures.versions.show', [$procedure, $version])."#step-{$step->id}")
            ->with('status', $status);
    }
}
