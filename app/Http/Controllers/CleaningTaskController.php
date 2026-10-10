<?php

namespace App\Http\Controllers;

use App\Enums\CleaningTaskStatus;
use App\Models\CleaningTask;
use App\Services\Planning\CleaningTaskGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Yapılması gereken temizliğin (görevin) iptali (K-23). Yalnızca yönetici, yalnızca açık görevi
 * ve gerekçe yazarak iptal eder; görev silinmez, planın sıradaki görevi açılır. Kayıt açılmış görev
 * iptal edilmez: önce kayıt iptal edilir, görev yeniden açık olur.
 */
class CleaningTaskController extends Controller
{
    public function cancel(Request $request, CleaningTask $task, CleaningTaskGenerator $tasks): RedirectResponse
    {
        $validated = $request->validate([
            'cancel_reason' => ['required', 'string', 'max:2000'],
        ], attributes: ['cancel_reason' => 'gerekçe']);

        $cancelled = DB::transaction(function () use ($request, $task, $validated) {
            // Görev satırı kilitlenir: aynı anda görevden kayıt açılıyorsa (CleaningWorkflow::open) sıraya girer.
            $task = CleaningTask::query()->lockForUpdate()->findOrFail($task->id);

            if ($task->status !== CleaningTaskStatus::Open) {
                return false;
            }

            $task->transitionTo(CleaningTaskStatus::Cancelled);
            $task->closed_at = now();
            $task->cancelled_by = $request->user()->id;
            $task->cancel_reason = trim($validated['cancel_reason']);
            $task->save();

            return true;
        });

        if (! $cancelled) {
            return back()->withErrors(['task' => 'Görev artık açık değil; iptal edilemez.']);
        }

        // K-24: planın sıradaki görevi hemen "ileride" görünür.
        $tasks->generate(now());

        return redirect()->route('dashboard')->with('status', "{$task->machine->code} makinesinin görevi iptal edildi.");
    }
}
