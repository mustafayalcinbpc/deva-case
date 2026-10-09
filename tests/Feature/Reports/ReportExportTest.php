<?php

namespace Tests\Feature\Reports;

use App\Enums\CleaningType;
use App\Jobs\Reports\GenerateAuditReportPdf;
use App\Jobs\Reports\GenerateDurationCsv;
use App\Models\ReportExport;
use App\Models\User;
use App\Notifications\Reports\ReportExportFailed;
use App\Notifications\Reports\ReportExportReady;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\Feature\Reports\Concerns\BuildsReportScenarios;
use Tests\TestCase;

/**
 * Kuyrukta dışa aktarma: istek hemen döner, iş commit'ten sonra kuyruğa gider (testte sync),
 * dosya özel "local" diske yazılır, satır tamamlanır ve isteyene plan sözleşmesindeki biçimde
 * veritabanı bildirimi gider. Dosyayı yalnızca isteyen ya da başka bir yönetici indirir.
 */
class ReportExportTest extends TestCase
{
    use BuildsCleaningFixtures, BuildsReportScenarios, InteractsWithCleaningWorkflow, RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->at('00:00:00', '2026-09-01');
        $this->manager = $this->manager('Zeynep');
    }

    public function test_audit_pdf_request_returns_at_once_and_the_job_renders_the_pdf(): void
    {
        $cleaning = $this->completedCleaning($this->operator('Ahmet'), $this->makeMachine(), '2026-10-09 09:00:00');
        $this->at('10:00:00');

        $this->actingAs($this->manager)
            ->from(route('reports.audit', $cleaning))
            ->post(route('reports.audit.pdf', $cleaning))
            ->assertRedirect(route('reports.audit', $cleaning))
            ->assertSessionHas('status', 'Rapor hazırlanıyor; hazır olunca bildirim gelecek.');

        $export = ReportExport::query()->sole();
        $this->assertSame(ReportExport::TYPE_AUDIT_PDF, $export->type);
        $this->assertSame($this->manager->id, $export->user_id);
        // MySQL JSON anahtar sırasını korumaz; içerik karşılaştırılır.
        $this->assertEquals(['cleaning_id' => $cleaning->id, 'record_no' => $cleaning->record_no], $export->parameters);
        $this->assertSame(ReportExport::STATUS_COMPLETED, $export->status);
        $this->assertSame("denetim-raporu_{$cleaning->record_no}.pdf", $export->file_name);
        $this->assertSame("reports/exports/{$export->id}/{$export->file_name}", $export->file_path);
        $this->assertMoment('2026-10-09 10:00:00', $export->completed_at);

        Storage::disk('local')->assertExists($export->file_path);
        $pdf = Storage::disk('local')->get($export->file_path);
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('%%EOF', substr($pdf, -64));

        $this->assertNotificationSent($export, [
            'title' => 'Rapor hazır',
            'message' => "{$cleaning->record_no} kaydının denetim raporu (PDF) indirilmeye hazır.",
            'url' => "/reports/exports/{$export->id}/download",
            'level' => 'info',
        ]);
    }

    public function test_jobs_are_queued_after_commit_and_the_request_does_not_wait(): void
    {
        Queue::fake();
        $cleaning = $this->openCleaning($this->operator(), $this->makeMachine());

        $this->actingAs($this->manager)->post(route('reports.audit.pdf', $cleaning))->assertRedirect();
        $this->actingAs($this->manager)->post(route('reports.durations.csv'))->assertRedirect();

        [$pdf, $csv] = ReportExport::query()->orderBy('id')->get()->all();
        $this->assertSame([ReportExport::STATUS_PENDING, ReportExport::STATUS_PENDING], [$pdf->status, $csv->status]);
        Queue::assertPushed(GenerateAuditReportPdf::class, fn ($job) => $job->export->is($pdf));
        Queue::assertPushed(GenerateDurationCsv::class, fn ($job) => $job->export->is($csv));
        $this->assertSame(0, $this->manager->notifications()->count());

        foreach ([new GenerateAuditReportPdf($pdf), new GenerateDurationCsv($csv)] as $job) {
            $this->assertInstanceOf(ShouldQueue::class, $job);
            $this->assertInstanceOf(ShouldQueueAfterCommit::class, $job);
        }
    }

    public function test_duration_csv_uses_the_filters_of_the_request(): void
    {
        $ahmet = $this->operator('Ahmet');
        $m03 = $this->makeMachine([['steps' => 1]]);
        $ank = $this->makeMachineAt('ANK', 'H02', 'M01');
        $this->completedCleaning($ahmet, $m03, '2026-10-08 09:00:00', 600);
        $this->completedCleaning($ahmet, $m03, '2026-10-08 10:00:00', 1200);
        $this->completedCleaning($ahmet, $m03, '2026-10-08 11:00:00', 60, CleaningType::Unplanned);
        $this->completedCleaning($ahmet, $ank, '2026-10-08 12:00:00', 900);
        $this->completedCleaning($ahmet, $ank, '2026-09-20 12:00:00', 300);
        $this->at('13:00:00');

        $this->actingAs($this->manager)
            ->post(route('reports.durations.csv'), ['from' => '2026-10-01', 'to' => '2026-10-09', 'type' => 'planned'])
            ->assertRedirect(route('reports.durations', ['from' => '2026-10-01', 'to' => '2026-10-09', 'type' => 'planned']))
            ->assertSessionHas('status', 'Rapor hazırlanıyor; hazır olunca bildirim gelecek.');

        $export = ReportExport::query()->sole();
        $this->assertEquals([
            'filters' => ['from' => '2026-10-01', 'to' => '2026-10-09', 'type' => 'planned'],
            'period' => '01.10.2026 – 09.10.2026',
        ], $export->parameters);
        $this->assertSame(ReportExport::STATUS_COMPLETED, $export->status);
        $this->assertSame('sure-ve-efor_2026-10-01_2026-10-09.csv', $export->file_name);

        $this->assertSame(
            "\u{FEFF}"
            .'Tesis;Hat;"Makine kodu";"Makine adı";"Tamamlanan kayıt";"Ort. net süre (sn)";"En kısa net süre (sn)";"En uzun net süre (sn)";"Ort. brüt süre (sn)";"Ort. insan eforu (sn)";"Ort. çalışma dilimi"'."\r\n"
            .'ANK;H02;M01;"Makine M01";1;900;900;900;900;900;1,0'."\r\n"
            .'IST;H01;M03;"Makine M03";2;900;600;1200;900;900;1,0'."\r\n",
            Storage::disk('local')->get($export->file_path),
        );

        $this->assertNotificationSent($export, [
            'title' => 'Rapor hazır',
            'message' => 'Süre ve efor raporu (CSV), 01.10.2026 – 09.10.2026 indirilmeye hazır.',
            'url' => "/reports/exports/{$export->id}/download",
            'level' => 'info',
        ]);
    }

    public function test_csv_neutralises_spreadsheet_formulas_in_names(): void
    {
        $machine = $this->makeMachine([['steps' => 1]]);
        $machine->update(['name' => '=HYPERLINK("x")']);
        $this->completedCleaning($this->operator(), $machine, '2026-10-08 09:00:00', 600);
        $this->at('13:00:00');

        $this->actingAs($this->manager)->post(route('reports.durations.csv'));

        $csv = Storage::disk('local')->get(ReportExport::query()->sole()->file_path);
        $this->assertStringContainsString(';"\'=HYPERLINK(""x"")";', $csv);
    }

    public function test_failed_job_marks_the_export_and_notifies_the_requester(): void
    {
        $cleaning = $this->openCleaning($this->operator(), $this->makeMachine());
        $export = $this->export(ReportExport::TYPE_AUDIT_PDF, ['cleaning_id' => $cleaning->id, 'record_no' => $cleaning->record_no]);

        (new GenerateAuditReportPdf($export))->failed(new RuntimeException('Yazı tipi bulunamadı'));

        $export->refresh();
        $this->assertSame(ReportExport::STATUS_FAILED, $export->status);
        $this->assertSame('Yazı tipi bulunamadı', $export->error);
        $this->assertNotNull($export->failed_at);
        $this->assertNotificationSent($export, [
            'title' => 'Rapor hazırlanamadı',
            'message' => "{$cleaning->record_no} kaydının denetim raporu (PDF) hazırlanırken bir hata oluştu. Lütfen yeniden deneyin.",
            'url' => "/reports/cleanings/{$cleaning->id}/audit",
            'level' => 'danger',
        ], ReportExportFailed::class);
    }

    public function test_download_streams_the_file_to_the_requester_and_other_managers_only(): void
    {
        $export = $this->export(ReportExport::TYPE_DURATIONS_CSV, ['filters' => []]);
        Storage::disk('local')->put('reports/exports/1/rapor.csv', "a;b\r\n");
        $export->update([
            'status' => ReportExport::STATUS_COMPLETED,
            'file_path' => 'reports/exports/1/rapor.csv',
            'file_name' => 'rapor.csv',
        ]);

        $response = $this->actingAs($this->manager)->get(route('reports.exports.download', $export))->assertOk();
        $this->assertSame("a;b\r\n", $response->streamedContent());
        $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename=rapor.csv', $response->headers->get('Content-Disposition'));

        $this->actingAs($this->manager('Başka yönetici'))->get(route('reports.exports.download', $export))->assertOk();
        $this->actingAs($this->operator())->get(route('reports.exports.download', $export))->assertForbidden();

        auth()->logout();
        $this->get(route('reports.exports.download', $export))->assertRedirect(route('login'));
    }

    public function test_download_of_an_unfinished_or_missing_file_is_404(): void
    {
        $pending = $this->export(ReportExport::TYPE_DURATIONS_CSV, ['filters' => []]);
        $missing = $this->export(ReportExport::TYPE_DURATIONS_CSV, ['filters' => []]);
        $missing->update([
            'status' => ReportExport::STATUS_COMPLETED,
            'file_path' => 'reports/exports/2/yok.csv',
            'file_name' => 'yok.csv',
        ]);

        $this->actingAs($this->manager)->get(route('reports.exports.download', $pending))->assertNotFound();
        $this->actingAs($this->manager)->get(route('reports.exports.download', $missing))->assertNotFound();
        $this->actingAs($this->manager)->get('/reports/exports/999/download')->assertNotFound();
    }

    public function test_downloaded_audit_pdf_matches_the_generated_file(): void
    {
        $cleaning = $this->completedCleaning($this->operator('Ahmet'), $this->makeMachine(), '2026-10-09 09:00:00');
        $this->at('10:00:00');
        $this->actingAs($this->manager)->post(route('reports.audit.pdf', $cleaning));
        $export = ReportExport::query()->sole();

        $response = $this->actingAs($this->manager)->get(route('reports.exports.download', $export))->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame(Storage::disk('local')->get($export->file_path), $response->streamedContent());
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function export(string $type, array $parameters): ReportExport
    {
        return ReportExport::create([
            'user_id' => $this->manager->id,
            'type' => $type,
            'parameters' => $parameters,
            'status' => ReportExport::STATUS_PENDING,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertNotificationSent(ReportExport $export, array $data, string $type = ReportExportReady::class): void
    {
        $notification = $export->user->notifications()->sole();

        $this->assertSame($type, $notification->type);
        $this->assertSame($data, $notification->data);
        $this->assertNull($notification->read_at);
    }
}
