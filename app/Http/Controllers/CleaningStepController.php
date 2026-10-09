<?php

namespace App\Http\Controllers;

use App\Enums\CleaningStatus;
use App\Enums\PhaseStatus;
use App\Http\Requests\Cleaning\CompleteStepRequest;
use App\Http\Requests\Cleaning\UpdateStepWorkersRequest;
use App\Models\Cleaning;
use App\Models\CleaningStep;
use App\Services\Cleaning\CleaningWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Kayıt detayındaki adım aksiyonları. Kuralların ve yetkinin tek sahibi CleaningWorkflow'dur;
 * ihlal (CleaningRuleViolation) bootstrap/app.php'de merkezi olarak ele alınır. Adım route'ta
 * kayda bağlıdır (scoped binding): başka kaydın adımı 404 verir.
 */
class CleaningStepController extends Controller
{
    public function __construct(private readonly CleaningWorkflow $workflow) {}

    public function start(Request $request, Cleaning $cleaning, CleaningStep $step): RedirectResponse
    {
        $this->workflow->startStep($request->user(), $step);

        return $this->backToNow($cleaning, "{$step->sequence}. adım başlatıldı.");
    }

    public function pause(Request $request, Cleaning $cleaning, CleaningStep $step): RedirectResponse
    {
        $this->workflow->pauseStep($request->user(), $step);

        return $this->backToNow($cleaning, "{$step->sequence}. adım duraklatıldı.");
    }

    public function resume(Request $request, Cleaning $cleaning, CleaningStep $step): RedirectResponse
    {
        $this->workflow->resumeStep($request->user(), $step);

        return $this->backToNow($cleaning, "{$step->sequence}. adım devam ediyor.");
    }

    /**
     * Fazın son adımı fazı, son fazın son adımı temizliği de kapatır (R-25); mesaj bunu söyler.
     * Faz minimum sürenin altındaysa ve gerekçe yoksa workflow reddeder (K-01).
     */
    public function complete(CompleteStepRequest $request, Cleaning $cleaning, CleaningStep $step): RedirectResponse
    {
        $this->workflow->completeStep($request->user(), $step, $request->deviationReason());

        $phase = $step->phase()->firstOrFail();
        $message = "{$step->sequence}. adım tamamlandı.";

        if ($phase->status === PhaseStatus::Completed) {
            $message .= " {$phase->sequence}. faz tamamlandı.";
        }

        if ($cleaning->refresh()->status === CleaningStatus::Completed) {
            $message .= ' Temizlik tamamlandı.';
        }

        return $this->backToNow($cleaning, $message);
    }

    public function workers(UpdateStepWorkersRequest $request, Cleaning $cleaning, CleaningStep $step): RedirectResponse
    {
        $this->workflow->setWorkers($request->user(), $step, $request->userIds());

        return $this->backToNow($cleaning, 'Görevliler güncellendi.');
    }

    /**
     * Operatör bir sonraki işlemi aynı yerden yapsın diye "Şimdi" kartına döner (saha önceliği).
     */
    private function backToNow(Cleaning $cleaning, string $status): RedirectResponse
    {
        return redirect()
            ->route('cleanings.show', $cleaning)
            ->withFragment('now')
            ->with('status', $status);
    }
}
