<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Procedures\ProcedureRequest;
use App\Models\Procedure;
use App\Models\ProcedureVersion;
use App\Services\Definitions\ProcedureVersioning;
use App\Services\Definitions\ProcedureVersionStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Prosedürler (R-01, R-02, K-18). Prosedür silinmez: makineler ve geçmiş kayıtlar ona
 * bağlıdır. Fazlar ve adımlar versiyonda tanımlanır (ProcedureVersionController).
 */
class ProcedureController extends Controller
{
    private const PER_PAGE = 25;

    public function index(): View
    {
        $procedures = Procedure::query()
            ->with([
                'currentPublishedVersion',
                'upcomingVersion',
                'draftVersion',
                'machines' => fn ($query) => $query->with('line')->orderBy('code'),
            ])
            ->withCount('cleanings')
            ->orderBy('code')
            ->paginate(self::PER_PAGE);

        return view('admin.procedures.index', ['procedures' => $procedures]);
    }

    public function create(): View
    {
        return view('admin.procedures.form', ['procedure' => new Procedure]);
    }

    public function store(ProcedureRequest $request, ProcedureVersioning $versioning): RedirectResponse
    {
        $procedure = $versioning->createProcedure($request->validated('code'), $request->validated('name'));
        $draft = $procedure->versions()->sole();

        return redirect()
            ->route('admin.procedures.versions.show', [$procedure, $draft])
            ->with('status', "{$procedure->code} prosedürü eklendi. v1 taslağına fazları ve adımları ekleyip yayımlayın.");
    }

    public function show(Procedure $procedure): View
    {
        $procedure->load([
            'machines' => fn ($query) => $query->with('line.facility')->orderBy('code'),
            'versions' => fn ($query) => $query
                ->withCount(['phases', 'steps', 'cleanings'])
                ->orderByDesc('version'),
        ]);

        $current = $procedure->currentPublishedVersion()->first();

        return view('admin.procedures.show', [
            'procedure' => $procedure,
            'current' => $current,
            'draft' => $procedure->versions->first(fn (ProcedureVersion $version) => $version->isDraft()),
            'statuses' => $procedure->versions->mapWithKeys(fn (ProcedureVersion $version) => [
                $version->id => ProcedureVersionStatus::of($version, $current?->id),
            ]),
            'cleaningsCount' => $procedure->versions->sum('cleanings_count'),
        ]);
    }

    public function edit(Procedure $procedure): View
    {
        return view('admin.procedures.form', ['procedure' => $procedure]);
    }

    public function update(ProcedureRequest $request, Procedure $procedure): RedirectResponse
    {
        $procedure->update($request->validated());

        return redirect()
            ->route('admin.procedures.show', $procedure)
            ->with('status', "{$procedure->code} prosedürü güncellendi.");
    }
}
