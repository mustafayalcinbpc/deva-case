<?php

namespace Tests\Feature\Reports;

use App\Enums\CancelReason;
use App\Enums\CleaningType;
use App\Models\Cleaning;
use App\Models\CleaningEvent;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\Feature\Reports\Concerns\BuildsReportScenarios;
use Tests\TestCase;

/**
 * Kayıt bazında denetim raporu (R-45–R-49): R-45'teki her sorunun cevabı ve olay zincirinin
 * tamamı hash'leriyle; zincir rapor açılırken baştan doğrulanır.
 */
class AuditReportTest extends TestCase
{
    use BuildsCleaningFixtures, BuildsReportScenarios, InteractsWithCleaningWorkflow, RefreshDatabase;

    private User $ahmet;

    private User $mehmet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('07:00:00');
        $this->ahmet = $this->operator('Ahmet');
        $this->mehmet = $this->operator('Mehmet');
    }

    public function test_report_answers_every_r45_question(): void
    {
        $cleaning = $this->completedScenario();

        $response = $this->report($cleaning);

        $response->assertSee('<title>Denetim raporu '.$cleaning->record_no, false);
        $response->assertDontSee('Bir adım hâlâ çalışıyor');
        $this->assertSame([
            "Kayıt no {$cleaning->record_no}",
            'Saha defteri referansı IST-SD-260001',
            'Tür Planlı temizlik',
            'Durum Tamamlandı',
            'Tesis IST — İstanbul Tesisi',                                 // 1
            'Hat H01 — Hat 1',                                             // 2
            'Makine M03 — Makine M03',
            'Sorumlu (kaydı açan) Ahmet',                                  // 3
            'İlk adımı başlatan Ahmet',
            'Prosedür PRC-M03 — M03 temizlik prosedürü',                  // 11
            'Prosedür versiyonu 1 (yayın: 09.10.2026 10:00:00)',
            'Malzeme kullanımı Zorunlu değil',
            'Üretim iş emri WO-2026-001 — Ürün değişimi',                 // 12
            'Açıklama Alerjen sonrası temizlik',
            'Açılış 09.10.2026 11:00:00',
            'Başlangıç (ilk adım) 09.10.2026 11:15:00',
            'Tamamlanma 09.10.2026 12:00:00',                              // 13
        ], $this->texts($response, '#audit-record tr'));

        // 7, 8: net 10+5+10 dk, brüt 11:15–12:00, efor 20+5+10 dk.
        $this->assertSame([
            'Net çalışma süresi 25 dk 00 sn Adımlarda gerçekten çalışılan süre; duraklamalar ve adımlar arası boşluklar sayılmaz.',
            'Brüt süre 45 dk 00 sn İlk adımın başlangıcından son çalışmanın bitişine kadar; aradaki boşluklar dahil.',
            'İnsan eforu 35 dk 00 sn Her çalışma diliminde süre × çalışan kişi sayısı.',
            'Çalışma dilimi 4 Adımın kesintisiz çalışılan her bölümü; duraklatma ya da görevli değişikliği yeni dilim açar.',
        ], $this->texts($response, '#audit-totals tr'));

        // 4, 5, 6: kimler hangi adımda, ne zaman başladı ve bitti.
        $this->assertSame([
            '1. faz — Faz 1 · Tamamlandı · Minimum 10 dk 00 sn · Ölçülen (net): 15 dk 00 sn',
            '1. Faz 1 adım 1 Tamamlandı 09.10.2026 11:15:00 09.10.2026 11:30:00 10 dk 00 sn 20 dk 00 sn Ahmet, Mehmet',
            '2. Faz 1 adım 2 Tamamlandı 09.10.2026 11:40:00 09.10.2026 11:45:00 5 dk 00 sn 5 dk 00 sn Mehmet',
            '2. faz — Faz 2 · Tamamlandı · Minimum 0 sn · Ölçülen (net): 10 dk 00 sn',
            '3. Faz 2 adım 1 Tamamlandı 09.10.2026 11:50:00 09.10.2026 12:00:00 10 dk 00 sn 10 dk 00 sn Ahmet',
        ], $this->texts($response, '#audit-steps tbody tr'));

        $this->assertSame([
            '1. adım 09.10.2026 11:15:00 09.10.2026 11:20:00 5 dk 00 sn 2 Ahmet, Mehmet Duraklatıldı',
            '1. adım 09.10.2026 11:25:00 09.10.2026 11:30:00 5 dk 00 sn 2 Ahmet, Mehmet Adım tamamlandı',
            '2. adım 09.10.2026 11:40:00 09.10.2026 11:45:00 5 dk 00 sn 1 Mehmet Adım tamamlandı',
            '3. adım 09.10.2026 11:50:00 09.10.2026 12:00:00 10 dk 00 sn 1 Ahmet Adım tamamlandı',
        ], $this->texts($response, '#audit-slices tbody tr'));

        // 9, 10: malzemeler ve lotlar; yanlış giriş gerekçesiyle görünür.
        $this->assertSame([
            'DET-01 — Malzeme DET-01 LOT-77 31.12.2027 Ahmet, 09.10.2026 11:00:00 Geçerli',
            'DET-01 — Malzeme DET-01 LOT-WRONG 31.12.2027 Ahmet, 09.10.2026 11:00:00 Geçersiz kılındı: Lot yanlış yazıldı (Ahmet, 09.10.2026 11:05:00)',
        ], $this->texts($response, '#audit-materials tbody tr'));
    }

    public function test_report_lists_the_full_event_trail_with_hashes_and_verifies_the_chain(): void
    {
        $cleaning = $this->completedScenario();
        $events = CleaningEvent::query()->where('cleaning_id', $cleaning->id)->orderBy('sequence')->get();

        $response = $this->report($cleaning);

        $integrity = $this->text($this->one($response, '#audit-integrity'));
        $this->assertStringContainsString("Olay zinciri doğrulandı. {$events->count()} olay, oluştukları andan beri değiştirilmemiş", $integrity);
        $this->assertStringContainsString("Zincirin son hash'i: {$events->last()->hash}", $integrity);

        $rows = $this->texts($response, '#audit-events tbody tr');
        $this->assertCount($events->count(), $rows);

        foreach ($events as $index => $event) {
            $this->assertStringStartsWith("{$event->sequence} ", $rows[$index]);
            $this->assertStringContainsString("Hash: {$event->hash}", $rows[$index]);
            $this->assertStringContainsString('Önceki hash: '.($event->previous_hash ?? '— (ilk olay)'), $rows[$index]);
        }

        $this->assertStringStartsWith('1 09.10.2026 11:00:00 Ahmet Kayıt açıldı Tür: Planlı temizlik Prosedür: PRC-M03 — M03 temizlik prosedürü (versiyon 1) Yardımcı personel: Mehmet Üretim iş emri: WO-2026-001', $rows[0]);
        $this->assertStringStartsWith('4 09.10.2026 11:05:00 Ahmet Malzeme geçersiz kılındı Malzeme: DET-01 — Malzeme DET-01 Lot: LOT-WRONG Gerekçe: Lot yanlış yazıldı', $rows[3]);
        $this->assertStringContainsString('Temizlik tamamlandı Net çalışma süresi: 25 dk 00 sn Brüt süre: 45 dk 00 sn İnsan eforu: 35 dk 00 sn', end($rows));
    }

    public function test_tampered_chain_is_reported_as_broken(): void
    {
        $cleaning = $this->completedScenario();
        $last = CleaningEvent::query()->where('cleaning_id', $cleaning->id)->orderByDesc('sequence')->firstOrFail();

        // Araya, hash'i zincire uymayan bir olay eklenir (güncelleme ve silme trigger'larla engelli).
        DB::table('cleaning_events')->insert([
            'cleaning_id' => $cleaning->id,
            'sequence' => $last->sequence + 1,
            'type' => 'step.completed',
            'actor_id' => $this->ahmet->id,
            'payload' => '{}',
            'occurred_at' => '2026-10-09 09:30:00',
            'previous_hash' => $last->hash,
            'hash' => str_repeat('0', 64),
        ]);

        $integrity = $this->text($this->one($this->report($cleaning), '#audit-integrity'));

        $this->assertStringStartsWith('Olay zinciri doğrulanamadı.', $integrity);
    }

    public function test_report_of_a_cancelled_record_shows_who_cancelled_and_why(): void
    {
        $machine = $this->makeMachine([['steps' => 2]]);
        $cleaning = $this->openCleaning($this->ahmet, $machine, type: CleaningType::Unplanned);
        $this->at('08:00:00');
        $this->workflow()->startStep($this->ahmet, $this->stepOf($cleaning, 1));
        $this->at('08:20:00');
        $this->workflow()->cancel($this->manager('Zeynep'), $cleaning, CancelReason::PersonnelLeft, 'Ahmet başka bölüme geçti');

        $response = $this->report($cleaning);
        $facts = $this->texts($response, '#audit-record tr');

        $this->assertContains('Saha defteri referansı Yok (plansız müdahale)', $facts);
        $this->assertContains('Durum İptal', $facts);
        $this->assertContains('İptal zamanı 09.10.2026 11:20:00', $facts);
        $this->assertContains('İptal eden Zeynep', $facts);
        $this->assertContains('İptal gerekçesi Personel ayrıldı: Ahmet başka bölüme geçti', $facts);
        $this->assertSame(
            ['1. adım 09.10.2026 11:00:00 09.10.2026 11:20:00 20 dk 00 sn 1 Ahmet Kayıt iptal edildi'],
            $this->texts($response, '#audit-slices tbody tr'),
        );
    }

    public function test_report_of_an_open_record_counts_the_running_slice_until_now(): void
    {
        $cleaning = $this->openCleaning($this->ahmet, $this->makeMachine([['steps' => 2]]));
        $this->at('08:00:00');
        $this->workflow()->startStep($this->ahmet, $this->stepOf($cleaning, 1));
        $this->at('08:12:00');

        $response = $this->report($cleaning);

        $this->assertContains('Net çalışma süresi 12 dk 00 sn Adımlarda gerçekten çalışılan süre; duraklamalar ve adımlar arası boşluklar sayılmaz.', $this->texts($response, '#audit-totals tr'));
        $this->assertSame(
            ['1. adım 09.10.2026 11:00:00 Devam ediyor 12 dk 00 sn 1 Ahmet —'],
            $this->texts($response, '#audit-slices tbody tr'),
        );
        $response->assertSee('Bir adım hâlâ çalışıyor; açık çalışma dilimi rapor anına kadar geçen süreyle sayıldı.');
    }

    public function test_print_page_offers_print_pdf_and_back_actions(): void
    {
        $cleaning = $this->openCleaning($this->ahmet, $this->makeMachine());

        $response = $this->report($cleaning);

        $this->one($response, '.audit-toolbar a[href="'.route('cleanings.show', $cleaning).'"]');
        $this->one($response, '.audit-toolbar [data-audit-print]');
        $this->assertSame('POST', $this->one($response, '.audit-toolbar form[action="'.route('reports.audit.pdf', $cleaning).'"]')->getAttribute('method'));
        $this->assertStringContainsString('@media print', $response->getContent());
    }

    public function test_unknown_record_returns_404(): void
    {
        $this->actingAs($this->manager())->get('/reports/cleanings/999999/audit')->assertNotFound();
    }

    /**
     * Ahmet (sahip) + Mehmet (yardımcı). 1. faz (2 adım, minimum 10 dk, net), 2. faz (1 adım).
     *   1. adım: Ahmet + Mehmet 08:15–08:20, duraklatıldı, 08:25–08:30
     *   2. adım: yalnız Mehmet 08:40–08:45
     *   3. adım: yalnız Ahmet 08:50–09:00
     * Malzeme: LOT-77 geçerli; LOT-WRONG 08:05'te geçersiz kılındı.
     */
    private function completedScenario(): Cleaning
    {
        $machine = $this->makeMachine([['steps' => 2, 'min_seconds' => 600], ['steps' => 1]]);
        $material = $this->makeMaterial('DET-01');
        $workOrder = WorkOrder::create(['code' => 'WO-2026-001', 'machine_id' => $machine->id, 'description' => 'Ürün değişimi']);

        $this->at('08:00:00');
        $cleaning = $this->workflow()->open(
            $this->ahmet,
            $machine,
            CleaningType::Planned,
            [$this->mehmet->id],
            [$this->entry($material, 'LOT-77'), $this->entry($material, 'LOT-WRONG')],
            $workOrder,
            'Alerjen sonrası temizlik',
        );
        $this->at('08:05:00');
        $this->workflow()->voidMaterial($this->ahmet, $cleaning->materials()->where('lot_no', 'LOT-WRONG')->firstOrFail(), 'Lot yanlış yazıldı');

        $this->at('08:15:00');
        $this->workflow()->startStep($this->ahmet, $this->stepOf($cleaning, 1));
        $this->at('08:20:00');
        $this->workflow()->pauseStep($this->ahmet, $this->stepOf($cleaning, 1));
        $this->at('08:25:00');
        $this->workflow()->resumeStep($this->ahmet, $this->stepOf($cleaning, 1));
        $this->at('08:30:00');
        $this->workflow()->completeStep($this->ahmet, $this->stepOf($cleaning, 1));

        $this->workflow()->setWorkers($this->ahmet, $this->stepOf($cleaning, 2), [$this->mehmet->id]);
        $this->at('08:40:00');
        $this->workflow()->startStep($this->mehmet, $this->stepOf($cleaning, 2));
        $this->at('08:45:00');
        $this->workflow()->completeStep($this->mehmet, $this->stepOf($cleaning, 2));

        $this->workflow()->setWorkers($this->ahmet, $this->stepOf($cleaning, 3), [$this->ahmet->id]);
        $this->at('08:50:00');
        $this->workflow()->startStep($this->ahmet, $this->stepOf($cleaning, 3));
        $this->at('09:00:00');
        $this->workflow()->completeStep($this->ahmet, $this->stepOf($cleaning, 3));

        return $cleaning->refresh();
    }

    private function report(Cleaning $cleaning): TestResponse
    {
        return $this->actingAs($this->manager())->get(route('reports.audit', $cleaning))->assertOk();
    }
}
