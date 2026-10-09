<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Locations\FacilityRequest;
use App\Models\Facility;
use App\Services\Definitions\IssuedCodeLock;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;

/**
 * Tesisler ve hatları (R-43). Tesis silinmez: geçmiş kayıtlar ona bağlıdır.
 */
class FacilityController extends Controller
{
    public function index(): View
    {
        $facilities = Facility::query()
            ->orderBy('code')
            ->with(['lines' => fn ($query) => $query
                ->orderBy('code')
                ->withCount([
                    'machines',
                    'machines as active_machines_count' => fn (Builder $query) => $query->where('is_active', true),
                ])])
            ->get();

        return view('admin.locations.index', ['facilities' => $facilities]);
    }

    public function create(): View
    {
        return view('admin.locations.facility-form', ['facility' => new Facility, 'codeLocked' => false]);
    }

    public function store(FacilityRequest $request): RedirectResponse
    {
        $facility = Facility::create($request->validated());

        return redirect()
            ->route('admin.facilities.index')
            ->with('status', "{$facility->code} tesisi eklendi. Şimdi hatlarını ekleyebilirsiniz.");
    }

    public function edit(Facility $facility, IssuedCodeLock $locks): View
    {
        return view('admin.locations.facility-form', [
            'facility' => $facility,
            'codeLocked' => $locks->facilityLocked($facility),
        ]);
    }

    public function update(FacilityRequest $request, Facility $facility): RedirectResponse
    {
        $facility->update($request->validated());

        return redirect()
            ->route('admin.facilities.index')
            ->with('status', "{$facility->code} tesisi güncellendi.");
    }
}
