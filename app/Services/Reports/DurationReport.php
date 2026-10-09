<?php

namespace App\Services\Reports;

use App\Enums\CleaningStatus;
use App\Models\Cleaning;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Süre ve efor raporu (R-26, R-28, K-02, K-04): "bu makinenin temizliği 30 dakika mı, 50
 * dakika mı, hangi faz uzun sürüyor?"
 *
 * Yalnızca tamamlanmış kayıtlar sayılır; tarih aralığı tamamlanma zamanına (closed_at)
 * uygulanır. Hesap veritabanında yapılır, kayıtlar tek tek yüklenmez:
 *   dilim  → süre = TIMESTAMPDIFF(başlangıç, bitiş), kişi sayısı = dilimdeki görevliler
 *   kayıt  → net = Σ süre, brüt = ilk başlangıç → son bitiş, efor = Σ (süre × kişi), dilim sayısı
 *   makine → kayıt sayısı, ortalama / en kısa / en uzun net, ortalama brüt, efor ve dilim sayısı
 * Tamamlanmış kayıtta açık dilim kalmaz (tamamlama son dilimi kapatır).
 */
final class DurationReport
{
    /**
     * Makine başına özet, tesis / hat / makine koduna göre sıralı.
     *
     * @return Collection<int, array{machine_id: int, facility_code: string, facility_name: string, line_code: string, line_name: string, machine_code: string, machine_name: string, machine_active: bool, completed_count: int, avg_net_seconds: int, min_net_seconds: int, max_net_seconds: int, avg_gross_seconds: int, avg_effort_seconds: int, avg_slice_count: float}>
     */
    public function machines(ReportFilters $filters): Collection
    {
        return DB::query()
            ->fromSub($this->cleaningTotals($filters), 't')
            ->join('machines as m', 'm.id', '=', 't.machine_id')
            ->join('lines as l', 'l.id', '=', 'm.line_id')
            ->join('facilities as f', 'f.id', '=', 'l.facility_id')
            ->select([
                'm.id as machine_id', 'm.code as machine_code', 'm.name as machine_name', 'm.is_active',
                'l.code as line_code', 'l.name as line_name', 'f.code as facility_code', 'f.name as facility_name',
            ])
            ->selectRaw('count(*) as completed_count')
            ->selectRaw('avg(t.net_seconds) as avg_net_seconds')
            ->selectRaw('min(t.net_seconds) as min_net_seconds')
            ->selectRaw('max(t.net_seconds) as max_net_seconds')
            ->selectRaw('avg(t.gross_seconds) as avg_gross_seconds')
            ->selectRaw('avg(t.effort_seconds) as avg_effort_seconds')
            ->selectRaw('avg(t.slice_count) as avg_slice_count')
            ->groupBy('m.id', 'm.code', 'm.name', 'm.is_active', 'l.code', 'l.name', 'f.code', 'f.name')
            ->orderBy('f.code')
            ->orderBy('l.code')
            ->orderBy('m.code')
            ->get()
            ->map(fn (object $row) => [
                'machine_id' => (int) $row->machine_id,
                'facility_code' => $row->facility_code,
                'facility_name' => $row->facility_name,
                'line_code' => $row->line_code,
                'line_name' => $row->line_name,
                'machine_code' => $row->machine_code,
                'machine_name' => $row->machine_name,
                'machine_active' => (bool) $row->is_active,
                'completed_count' => (int) $row->completed_count,
                'avg_net_seconds' => $this->seconds($row->avg_net_seconds),
                'min_net_seconds' => $this->seconds($row->min_net_seconds),
                'max_net_seconds' => $this->seconds($row->max_net_seconds),
                'avg_gross_seconds' => $this->seconds($row->avg_gross_seconds),
                'avg_effort_seconds' => $this->seconds($row->avg_effort_seconds),
                'avg_slice_count' => round((float) $row->avg_slice_count, 1),
            ]);
    }

    /**
     * Seçili makinenin fazları (K-01, K-02): ölçülen süre (fazın ayarına göre net ya da brüt)
     * minimumla karşılaştırılır. Faz tanımı prosedür versiyonuna aittir (minimum süre versiyonla
     * değişebilir, R-11); bu yüzden satırlar prosedür versiyonu ve faz bazındadır.
     *
     * @return Collection<int, array{procedure_code: string, procedure_name: string, version: int, sequence: int, name: string, minimum_seconds: int, include_gaps: bool, phase_count: int, avg_measured_seconds: int, min_measured_seconds: int, max_measured_seconds: int, below_minimum_count: int, avg_net_seconds: int, avg_effort_seconds: int}>
     */
    public function phases(ReportFilters $filters): Collection
    {
        $phaseTotals = DB::query()
            ->fromSub($this->sliceRows($filters), 's')
            ->select('s.cleaning_phase_id')
            ->selectRaw('sum(s.seconds) as net_seconds')
            ->selectRaw('sum(s.seconds * s.workers) as effort_seconds')
            ->groupBy('s.cleaning_phase_id');

        $query = DB::table('cleaning_phases as p')
            ->join('cleanings as c', 'c.id', '=', 'p.cleaning_id')
            ->join('procedure_phases as pp', 'pp.id', '=', 'p.procedure_phase_id')
            ->join('procedure_versions as pv', 'pv.id', '=', 'pp.procedure_version_id')
            ->join('procedures as pr', 'pr.id', '=', 'pv.procedure_id')
            ->leftJoinSub($phaseTotals, 'pt', 'pt.cleaning_phase_id', '=', 'p.id')
            ->where('c.status', CleaningStatus::Completed->value)
            ->select([
                'pr.code as procedure_code', 'pr.name as procedure_name', 'pv.version', 'pp.id',
                'pp.sequence', 'pp.name', 'pp.min_duration_seconds', 'pp.include_gaps',
            ])
            ->selectRaw('count(*) as phase_count')
            ->selectRaw('avg(p.measured_seconds) as avg_measured_seconds')
            ->selectRaw('min(p.measured_seconds) as min_measured_seconds')
            ->selectRaw('max(p.measured_seconds) as max_measured_seconds')
            ->selectRaw('sum(case when p.below_minimum then 1 else 0 end) as below_minimum_count')
            ->selectRaw('avg(coalesce(pt.net_seconds, 0)) as avg_net_seconds')
            ->selectRaw('avg(coalesce(pt.effort_seconds, 0)) as avg_effort_seconds')
            ->groupBy('pr.code', 'pr.name', 'pv.version', 'pp.id', 'pp.sequence', 'pp.name', 'pp.min_duration_seconds', 'pp.include_gaps')
            ->orderBy('pr.code')
            ->orderBy('pv.version')
            ->orderBy('pp.sequence');

        $filters->applyToCleanings($query, 'c');
        $filters->applyDateRange($query, 'c.closed_at');

        return $query->get()->map(fn (object $row) => [
            'procedure_code' => $row->procedure_code,
            'procedure_name' => $row->procedure_name,
            'version' => (int) $row->version,
            'sequence' => (int) $row->sequence,
            'name' => $row->name,
            'minimum_seconds' => (int) $row->min_duration_seconds,
            'include_gaps' => (bool) $row->include_gaps,
            'phase_count' => (int) $row->phase_count,
            'avg_measured_seconds' => $this->seconds($row->avg_measured_seconds),
            'min_measured_seconds' => $this->seconds($row->min_measured_seconds),
            'max_measured_seconds' => $this->seconds($row->max_measured_seconds),
            'below_minimum_count' => (int) $row->below_minimum_count,
            'avg_net_seconds' => $this->seconds($row->avg_net_seconds),
            'avg_effort_seconds' => $this->seconds($row->avg_effort_seconds),
        ]);
    }

    /**
     * Filtreye uyan tamamlanmış kayıtlar, kayıt başına süre ve eforla; en son tamamlanan önce.
     * Seçili makinenin kayıtlarını tek tek görmek (aykırı uzun temizlikler) içindir.
     *
     * @return LengthAwarePaginator<int, Cleaning>
     */
    public function cleanings(ReportFilters $filters, int $perPage, string $pageName = 'page'): LengthAwarePaginator
    {
        return Cleaning::query()
            ->joinSub($this->cleaningTotals($filters), 't', 't.cleaning_id', '=', 'cleanings.id')
            ->select('cleanings.*', 't.net_seconds', 't.gross_seconds', 't.effort_seconds', 't.slice_count')
            ->with(['facility', 'line', 'machine', 'owner'])
            ->orderByDesc('cleanings.closed_at')
            ->orderByDesc('cleanings.id')
            ->paginate($perPage, pageName: $pageName)
            ->withQueryString();
    }

    /**
     * Kayıt başına toplamlar (K-02, K-04).
     */
    private function cleaningTotals(ReportFilters $filters): Builder
    {
        return DB::query()
            ->fromSub($this->sliceRows($filters), 's')
            ->select('s.cleaning_id', 's.machine_id')
            ->selectRaw('sum(s.seconds) as net_seconds')
            ->selectRaw('timestampdiff(second, min(s.started_at), max(s.ended_at)) as gross_seconds')
            ->selectRaw('sum(s.seconds * s.workers) as effort_seconds')
            ->selectRaw('count(*) as slice_count')
            ->groupBy('s.cleaning_id', 's.machine_id');
    }

    /**
     * Filtreye uyan tamamlanmış kayıtların çalışma dilimleri: dilim başına süre ve kişi sayısı.
     */
    private function sliceRows(ReportFilters $filters): Builder
    {
        $query = DB::table('work_slices as ws')
            ->join('cleaning_steps as cs', 'cs.id', '=', 'ws.cleaning_step_id')
            ->join('cleanings as c', 'c.id', '=', 'cs.cleaning_id')
            ->leftJoin('work_slice_workers as w', 'w.work_slice_id', '=', 'ws.id')
            ->where('c.status', CleaningStatus::Completed->value)
            ->select('ws.id', 'cs.cleaning_id', 'cs.cleaning_phase_id', 'c.machine_id', 'ws.started_at', 'ws.ended_at')
            ->selectRaw('timestampdiff(second, ws.started_at, ws.ended_at) as seconds')
            ->selectRaw('count(w.id) as workers')
            ->groupBy('ws.id', 'cs.cleaning_id', 'cs.cleaning_phase_id', 'c.machine_id', 'ws.started_at', 'ws.ended_at');

        $filters->applyToCleanings($query, 'c');
        $filters->applyDateRange($query, 'c.closed_at');

        return $query;
    }

    private function seconds(mixed $value): int
    {
        return (int) round((float) $value);
    }
}
