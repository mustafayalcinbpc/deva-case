<?php

namespace App\Http\Controllers;

use App\Http\Requests\Cleaning\CancelCleaningRequest;
use App\Models\Cleaning;
use App\Services\Cleaning\CleaningWorkflow;
use Illuminate\Http\RedirectResponse;

/**
 * Kaydı iptal etme (K-08, K-09). Kimin hangi gerekçeyle iptal edebileceğini workflow denetler.
 */
class CleaningCancellationController extends Controller
{
    public function __construct(private readonly CleaningWorkflow $workflow) {}

    public function store(CancelCleaningRequest $request, Cleaning $cleaning): RedirectResponse
    {
        $this->workflow->cancel($request->user(), $cleaning, $request->reason(), $request->note());

        return redirect()->route('cleanings.show', $cleaning)->with('status', 'Kayıt iptal edildi.');
    }
}
