<?php

namespace App\Http\Controllers\Reports;

use App\Jobs\Reports\ReportExportJob;
use App\Models\ReportExport;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Dışa aktarma isteği: satır "bekliyor" olarak kaydedilir ve iş transaction commit edildikten
 * sonra kuyruğa gider. İstek hemen döner; dosya hazır olunca isteyene bildirim gelir.
 */
trait QueuesReportExports
{
    protected const QUEUED_MESSAGE = 'Rapor hazırlanıyor; hazır olunca bildirim gelecek.';

    /**
     * @param  array<string, mixed>  $parameters
     * @param  class-string<ReportExportJob>  $job
     */
    protected function queueExport(User $user, string $type, array $parameters, string $job): ReportExport
    {
        return DB::transaction(function () use ($user, $type, $parameters, $job) {
            $export = ReportExport::create([
                'user_id' => $user->id,
                'type' => $type,
                'parameters' => $parameters,
                'status' => ReportExport::STATUS_PENDING,
            ]);

            $job::dispatch($export);

            return $export;
        });
    }
}
