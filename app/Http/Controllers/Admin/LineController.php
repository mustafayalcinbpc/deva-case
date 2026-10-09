<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Locations\LineRequest;
use App\Models\Facility;
use App\Models\Line;
use App\Services\Definitions\IssuedCodeLock;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Bir tesisin hatları (R-43). Hat başka tesise taşınmaz ve silinmez (K-17).
 */
class LineController extends Controller
{
    public function create(Facility $facility): View
    {
        return view('admin.locations.line-form', [
            'facility' => $facility,
            'line' => new Line,
            'codeLocked' => false,
        ]);
    }

    public function store(LineRequest $request, Facility $facility): RedirectResponse
    {
        $line = $facility->lines()->create($request->validated());

        return redirect()
            ->route('admin.facilities.index')
            ->with('status', "{$facility->code} / {$line->code} hattı eklendi.");
    }

    public function edit(Line $line, IssuedCodeLock $locks): View
    {
        return view('admin.locations.line-form', [
            'facility' => $line->facility,
            'line' => $line,
            'codeLocked' => $locks->lineLocked($line),
        ]);
    }

    public function update(LineRequest $request, Line $line): RedirectResponse
    {
        $line->update($request->validated());

        return redirect()
            ->route('admin.facilities.index')
            ->with('status', "{$line->facility->code} / {$line->code} hattı güncellendi.");
    }
}
