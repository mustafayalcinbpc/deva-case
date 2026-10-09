<?php

namespace App\Http\Controllers;

use App\Http\Requests\Cleaning\StoreMaterialRequest;
use App\Http\Requests\Cleaning\VoidMaterialRequest;
use App\Models\Cleaning;
use App\Models\CleaningMaterial;
use App\Services\Cleaning\CleaningWorkflow;
use Illuminate\Http\RedirectResponse;

/**
 * Kayıt detayındaki malzeme aksiyonları (K-12–K-14). Kuralları ve yetkiyi workflow denetler.
 * Malzeme route'ta kayda bağlıdır (scoped binding): başka kaydın malzemesi 404 verir.
 */
class CleaningMaterialController extends Controller
{
    public function __construct(private readonly CleaningWorkflow $workflow) {}

    public function store(StoreMaterialRequest $request, Cleaning $cleaning): RedirectResponse
    {
        $this->workflow->addMaterial($request->user(), $cleaning, $request->entry());

        return redirect()->route('cleanings.show', $cleaning)->with('status', 'Malzeme eklendi.');
    }

    public function void(VoidMaterialRequest $request, Cleaning $cleaning, CleaningMaterial $material): RedirectResponse
    {
        $this->workflow->voidMaterial($request->user(), $material, $request->reason());

        return redirect()->route('cleanings.show', $cleaning)->with('status', 'Malzeme geçersiz kılındı.');
    }
}
