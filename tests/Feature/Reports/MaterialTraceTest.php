<?php

namespace Tests\Feature\Reports;

use App\Models\Cleaning;
use App\Models\Material;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\Feature\Reports\Concerns\BuildsReportScenarios;
use Tests\TestCase;

/**
 * Malzeme izlenebilirliği (R-10): malzeme ve/veya lot ile arama; geçersiz kılınan girişler
 * gerekçesiyle görünür (K-12).
 */
class MaterialTraceTest extends TestCase
{
    use BuildsCleaningFixtures, BuildsReportScenarios, InteractsWithCleaningWorkflow, RefreshDatabase;

    private User $ahmet;

    private Material $detergent;

    private Material $disinfectant;

    private Cleaning $first;

    private Cleaning $second;

    private Cleaning $third;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('08:00:00');

        $this->ahmet = $this->operator('Ahmet');
        $this->detergent = $this->makeMaterial('DET-01');
        $this->disinfectant = $this->makeMaterial('DEZ-02');
        $m03 = $this->makeMachine(code: 'M03');
        $m04 = $this->makeMachine(code: 'M04');

        // 1. kayıt: DET-01 / LOT-2026-A1 geçerli.
        $this->first = $this->openCleaning($this->ahmet, $m03, materials: [$this->entry($this->detergent, 'LOT-2026-A1')]);
        $this->completeRemainingSteps($this->ahmet, $this->first);

        // 2. kayıt: DET-01 / LOT-2026-A1 yanlış girildi, yardımcı Zeynep geçersiz kıldı; yerine LOT-2026-A2.
        $this->at('09:00:00');
        $zeynep = $this->operator('Zeynep');
        $this->second = $this->openCleaning($this->ahmet, $m04, [$zeynep], [$this->entry($this->detergent, 'LOT-2026-A1')]);
        $this->at('09:02:00');
        $this->workflow()->voidMaterial($zeynep, $this->second->materials()->firstOrFail(), 'Lot etiketi yanlış okundu');
        $this->workflow()->addMaterial($this->ahmet, $this->second, $this->entry($this->detergent, 'LOT-2026-A2'));

        // 3. kayıt: DEZ-02 / LOT-9_X.
        $this->at('10:00:00');
        $this->third = $this->openCleaning($this->ahmet, $m03, materials: [$this->entry($this->disinfectant, 'LOT-9_X')]);
    }

    public function test_no_search_shows_only_the_form(): void
    {
        $response = $this->search([]);

        $this->assertNull($this->page($response)->querySelector('#material-results'));
    }

    public function test_lot_search_lists_every_record_that_used_the_lot_including_voided_entries(): void
    {
        $response = $this->search(['lot' => 'lot-2026-a1']);

        $this->assertSame('2 giriş', $this->text($this->one($response, '#material-results .card-tools')));
        $this->assertSame(
            [
                $this->second->record_no.' IST / H01 / M04 Başlamadı 09.10.2026 12:00 — DET-01 — Malzeme DET-01 LOT-2026-A1 31.12.2027 Ahmet 09.10.2026 12:00:00 Geçersiz kılındı: Lot etiketi yanlış okundu Zeynep, 09.10.2026 12:02:00 Denetim raporu',
                $this->first->record_no.' IST / H01 / M03 Tamamlandı 09.10.2026 11:00 09.10.2026 11:02 DET-01 — Malzeme DET-01 LOT-2026-A1 31.12.2027 Ahmet 09.10.2026 11:00:00 Geçerli Denetim raporu',
            ],
            $this->texts($response, '#material-results tbody tr'),
        );
        $this->one($response, '#material-results a[href="'.route('cleanings.show', $this->first).'"]');
        $this->one($response, '#material-results a[href="'.route('reports.audit', $this->second).'"]');
    }

    public function test_material_search_and_combined_search(): void
    {
        $this->assertSame(
            [[$this->second->id, 'LOT-2026-A2'], [$this->second->id, 'LOT-2026-A1'], [$this->first->id, 'LOT-2026-A1']],
            $this->rows($this->search(['material_id' => $this->detergent->id])),
        );
        $this->assertSame(
            [[$this->third->id, 'LOT-9_X']],
            $this->rows($this->search(['material_id' => $this->disinfectant->id])),
        );
        $this->assertSame(
            [[$this->second->id, 'LOT-2026-A2']],
            $this->rows($this->search(['material_id' => $this->detergent->id, 'lot' => 'A2'])),
        );
        $this->assertSame([], $this->rows($this->search(['material_id' => $this->disinfectant->id, 'lot' => 'A2'])));
    }

    public function test_lot_search_treats_wildcards_literally(): void
    {
        $this->assertSame([[$this->third->id, 'LOT-9_X']], $this->rows($this->search(['lot' => '9_X'])));
        $this->assertSame([], $this->rows($this->search(['lot' => '%'])));
        $this->assertSame([], $this->rows($this->search(['lot' => 'LOT_2026'])));
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function search(array $query): TestResponse
    {
        return $this->actingAs($this->manager())->get(route('reports.materials', $query))->assertOk();
    }

    /**
     * Sonuç satırları: [kayıt id, lot]. Satırdaki kayıt bağlantısından kayıt bulunur.
     *
     * @return list<array{0: int, 1: string}>
     */
    private function rows(TestResponse $response): array
    {
        $records = Cleaning::query()->pluck('id', 'record_no');
        $rows = [];

        foreach ($this->page($response)->querySelectorAll('#material-results tbody tr') as $row) {
            $cells = $row->querySelectorAll('td');
            $rows[] = [$records[$this->text($cells[0])], $this->text($cells[6])];
        }

        return $rows;
    }
}
