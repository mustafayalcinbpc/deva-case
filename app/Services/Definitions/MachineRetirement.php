<?php

namespace App\Services\Definitions;

use App\Enums\CleaningStatus;
use App\Models\Cleaning;
use App\Models\Machine;
use App\Models\ProcedureVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Makineyi kullanımdan kaldırma ve yeniden kullanıma alma (R-12, K-16, K-18).
 *
 * Kullanımdan kaldırılan makine silinmez: yeni kayıt formunda görünmez (is_active), geçmiş
 * kayıtlarda görünmeye devam eder. İş kuralı ihlali, forma mesajla dönmesi için
 * ValidationException olarak (`machine` anahtarıyla) fırlatılır.
 */
final class MachineRetirement
{
    /**
     * K-16: başlamış ya da başlamamış açık kaydı olan makine kullanımdan kaldırılamaz.
     *
     * @throws ValidationException
     */
    public function retire(Machine $machine): void
    {
        DB::transaction(function () use ($machine) {
            // Makine satırı ve açık kayıt aralığı kilitlenir: aynı anda iki işlem aynı kararı veremez.
            $locked = Machine::query()->lockForUpdate()->findOrFail($machine->id);

            if (! $locked->is_active) {
                throw ValidationException::withMessages([
                    'machine' => "{$locked->code} makinesi zaten kullanımdan kaldırılmış.",
                ]);
            }

            $blocking = $this->openCleaningsQuery($locked)->lockForUpdate()->get(['id', 'record_no']);

            if ($blocking->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'machine' => "{$locked->code} makinesi kullanımdan kaldırılamaz; açık kaydı var: "
                        .$blocking->pluck('record_no')->implode(', ')
                        .'. Kayıtların tamamlanmasını bekleyin ya da kayıtları iptal edin (K-16).',
                ]);
            }

            $locked->update(['is_active' => false]);
        });

        $machine->refresh();
    }

    /**
     * K-18: kullanıma alınan makinede kayıt açılabilmeli; bunun için prosedürünün yayımlanmış
     * geçerli bir versiyonu olmalı.
     *
     * @throws ValidationException
     */
    public function reinstate(Machine $machine): void
    {
        DB::transaction(function () use ($machine) {
            $locked = Machine::query()->lockForUpdate()->findOrFail($machine->id);

            if ($locked->is_active) {
                throw ValidationException::withMessages([
                    'machine' => "{$locked->code} makinesi zaten kullanımda.",
                ]);
            }

            if (! $this->hasPublishedProcedure($locked)) {
                throw ValidationException::withMessages([
                    'machine' => "{$locked->code} makinesi kullanıma alınamaz; yayımlanmış versiyonu olan bir prosedür atanmamış. "
                        .'Önce makineyi düzenleyip prosedür seçin (K-18).',
                ]);
            }

            $locked->update(['is_active' => true]);
        });

        $machine->refresh();
    }

    /**
     * Kullanımdan kaldırmayı engelleyen kayıtlar: başlamamış ya da devam eden (K-16).
     *
     * @return Collection<int, Cleaning>
     */
    public function blockingCleanings(Machine $machine): Collection
    {
        return $this->openCleaningsQuery($machine)->with('owner:id,name')->get();
    }

    private function hasPublishedProcedure(Machine $machine): bool
    {
        return $machine->procedure_id !== null && ProcedureVersion::query()
            ->where('procedure_id', $machine->procedure_id)
            ->where('published_at', '<=', now())
            ->exists();
    }

    /**
     * @return Builder<Cleaning>
     */
    private function openCleaningsQuery(Machine $machine): Builder
    {
        return Cleaning::query()
            ->where('machine_id', $machine->id)
            ->whereIn('status', [CleaningStatus::Created, CleaningStatus::InProgress])
            ->orderBy('created_at')
            ->orderBy('id');
    }
}
