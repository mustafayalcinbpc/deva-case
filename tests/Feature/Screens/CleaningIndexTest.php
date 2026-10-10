<?php

namespace Tests\Feature\Screens;

use App\Enums\CancelReason;
use App\Enums\CleaningType;
use App\Models\Cleaning;
use Closure;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\TestCase;

/**
 * Kayıt listesi: bütün kayıtlar en yeni önce (K-11), durum / makine / "benim kayıtlarım"
 * filtreleri ve sayfalama.
 */
class CleaningIndexTest extends TestCase
{
    use BuildsCleaningFixtures, InteractsWithCleaningWorkflow, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('08:00:00');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('cleanings.index'))->assertRedirect(route('login'));
    }

    public function test_every_user_sees_all_records_newest_first_with_links(): void
    {
        $ahmet = $this->operator('Ahmet Yılmaz');
        $ayse = $this->operator('Ayşe Demir');

        // 08:00 açıldı, 08:10–08:25 çalışıldı, tamamlandı.
        $completed = $this->openCleaning($ahmet, $this->makeMachine([['steps' => 1]], code: 'M01'));
        $this->at('08:10:00');
        $this->runStep($ahmet, $completed, 1, 15 * 60);

        $this->at('08:30:00');
        $unplanned = $this->openCleaning($ayse, $this->makeMachine(code: 'M02'), type: CleaningType::Unplanned);

        $this->at('08:40:00');
        $cancelled = $this->openCleaning($ahmet, $this->makeMachine(code: 'M03'));
        $this->workflow()->cancel($ahmet, $cancelled, CancelReason::InvalidRecord, 'Yanlış makine');

        // K-11: operatör başkasının kayıtlarını da görür; yönetici de aynı listeyi görür.
        foreach ([$this->operator('Mehmet'), $this->manager()] as $viewer) {
            $response = $this->actingAs($viewer)->get(route('cleanings.index'))
                ->assertOk()
                ->assertSee('<title>Temizlik Kayıtları', false)
                ->assertSeeInOrder([$cancelled->record_no, $unplanned->record_no, $completed->record_no]);

            foreach ([$completed, $unplanned, $cancelled] as $cleaning) {
                $link = $this->rowOf($response, $cleaning)->querySelector('a.record-no');
                $this->assertSame(route('cleanings.show', $cleaning), $link->getAttribute('href'));
            }
        }

        $completed->refresh();
        $row = $this->rowText($this->rowOf($response, $completed));
        $this->assertStringContainsString($completed->field_ref, $row);
        $this->assertStringContainsString('IST / H01 / M01', $row);
        $this->assertStringContainsString('Planlı temizlik', $row);
        $this->assertStringContainsString('Ahmet Yılmaz', $row);
        $this->assertStringContainsString('Tamamlandı', $row);
        // Zamanlar İstanbul saatiyle: açılış 11:00, başlangıç 11:10, kapanış 11:25.
        $this->assertStringContainsString('9 Ekim 11:00 9 Ekim 11:10 9 Ekim 11:25', $row);
        $this->assertStringEndsWith('15 dk 00 sn', $row);

        $row = $this->rowText($this->rowOf($response, $unplanned));
        $this->assertStringContainsString('Plansız müdahale', $row);
        $this->assertStringContainsString('Ayşe Demir', $row);
        $this->assertStringContainsString('Başlamadı', $row);
        $this->assertStringEndsWith('Başlamadı 9 Ekim 11:30 — — —', $row, 'Başlamamış kaydın başlangıç, kapanış ve net süresi yok.');
        $this->assertSame('—', $this->rowText($this->rowOf($response, $unplanned)->querySelector('.field-ref')), 'Plansız müdahalenin saha referansı yok.');

        $this->assertStringContainsString('İptal', $this->rowText($this->rowOf($response, $cancelled)));

        $create = $this->page($response)->querySelector('.cleaning-list__create');
        $this->assertSame(route('cleanings.create'), $create->getAttribute('href'));
        $this->assertSame('Yeni kayıt', trim($create->textContent));
    }

    public function test_records_can_be_filtered_by_status(): void
    {
        $ahmet = $this->operator();
        $created = $this->openCleaning($ahmet, $this->makeMachine(code: 'M01'));
        $inProgress = $this->openCleaning($ahmet, $this->makeMachine(code: 'M02'));
        $this->workflow()->startStep($ahmet, $this->stepOf($inProgress, 1));

        $response = $this->actingAs($ahmet)->get(route('cleanings.index', ['status' => 'in_progress']))->assertOk();

        $this->assertSame([$inProgress->record_no], $this->listedRecordNos($response));
        $this->assertSame('in_progress', $this->selectedValue($response, 'status'));

        $response = $this->actingAs($ahmet)->get(route('cleanings.index', ['status' => 'created']))->assertOk();
        $this->assertSame([$created->record_no], $this->listedRecordNos($response));
    }

    public function test_records_can_be_filtered_by_machine(): void
    {
        $ahmet = $this->operator();
        $m01 = $this->makeMachine(code: 'M01');
        $m02 = $this->makeMachine(code: 'M02');
        $retired = $this->makeMachine(code: 'M03');
        $first = $this->openCleaning($ahmet, $m01);
        $this->openCleaning($ahmet, $m02);
        $this->openCleaning($ahmet, $retired);
        $this->at('08:05:00');
        $second = $this->openCleaning($ahmet, $m01);

        // Kullanımdan kaldırılmış makinenin geçmiş kayıtları da filtrelenebilir (K-16).
        $this->workflow()->cancel($ahmet, Cleaning::query()->where('machine_id', $retired->id)->sole(), CancelReason::InvalidRecord, 'Yanlış makine');
        $retired->update(['is_active' => false]);

        $response = $this->actingAs($ahmet)->get(route('cleanings.index', ['machine_id' => $m01->id]))->assertOk();

        $this->assertSame([$second->record_no, $first->record_no], $this->listedRecordNos($response));
        $this->assertSame((string) $m01->id, $this->selectedValue($response, 'machine_id'));

        $options = $this->page($response)->querySelectorAll('#filter-machine optgroup[label="İstanbul Tesisi / Hat 1"] option');
        $this->assertSame(['M01 — Makine M01', 'M02 — Makine M02', 'M03 — Makine M03 (kullanımdan kaldırıldı)'], array_map(
            fn (Element $option) => $this->rowText($option),
            iterator_to_array($options),
        ));
    }

    public function test_mine_filter_shows_records_owned_or_assigned_on_any_step(): void
    {
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $ayse = $this->operator('Ayşe');

        $owned = $this->openCleaning($mehmet, $this->makeMachine(code: 'M01'));
        $helper = $this->openCleaning($ahmet, $this->makeMachine(code: 'M02'), helpers: [$mehmet]);

        // Mehmet 1. adımdan çıkarıldı ama 2. adımda hâlâ görevli: listelenir.
        $laterStep = $this->openCleaning($ayse, $this->makeMachine(code: 'M03'), helpers: [$mehmet]);
        $this->workflow()->setWorkers($ayse, $this->stepOf($laterStep, 1), [$ayse->id]);

        // Mehmet bütün adımlardan çıkarıldı: listelenmez.
        $removed = $this->openCleaning($ayse, $this->makeMachine(code: 'M04'), helpers: [$mehmet]);
        $this->workflow()->setWorkers($ayse, $this->stepOf($removed, 1), [$ayse->id]);
        $this->workflow()->setWorkers($ayse, $this->stepOf($removed, 2), [$ayse->id]);

        $other = $this->openCleaning($ahmet, $this->makeMachine(code: 'M05'));

        $response = $this->actingAs($mehmet)->get(route('cleanings.index', ['mine' => 1]))->assertOk();

        $this->assertEqualsCanonicalizing(
            [$owned->record_no, $helper->record_no, $laterStep->record_no],
            $this->listedRecordNos($response),
        );
        $this->assertTrue($this->page($response)->querySelector('#filter-mine')->hasAttribute('checked'));

        // Filtresiz liste hepsini gösterir.
        $response = $this->actingAs($mehmet)->get(route('cleanings.index'))->assertOk();
        $this->assertCount(5, $this->listedRecordNos($response));
        $this->assertContains($other->record_no, $this->listedRecordNos($response));
        $this->assertContains($removed->record_no, $this->listedRecordNos($response));
    }

    public function test_filters_can_be_combined_and_invalid_values_are_ignored(): void
    {
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $machine = $this->makeMachine(code: 'M01');
        $mine = $this->openCleaning($ahmet, $machine);
        $this->openCleaning($mehmet, $machine);
        $this->openCleaning($ahmet, $this->makeMachine(code: 'M02'));

        $response = $this->actingAs($ahmet)
            ->get(route('cleanings.index', ['status' => 'created', 'machine_id' => $machine->id, 'mine' => 1]))
            ->assertOk();
        $this->assertSame([$mine->record_no], $this->listedRecordNos($response));

        $response = $this->actingAs($ahmet)
            ->get(route('cleanings.index', ['status' => 'bilinmeyen', 'machine_id' => 'abc']))
            ->assertOk();
        $this->assertCount(3, $this->listedRecordNos($response));
        $this->assertSame('', $this->selectedValue($response, 'status'));

        $this->actingAs($ahmet)
            ->get(route('cleanings.index').'?status[]=created&machine_id[]=1')
            ->assertOk();
    }

    public function test_records_are_paginated_and_filters_are_kept_in_page_links(): void
    {
        $ahmet = $this->operator();
        $machine = $this->makeMachine();

        // K-05: aynı makinede birden fazla başlamamış kayıt olabilir.
        $cleanings = [];
        foreach (range(1, 26) as $minute) {
            $this->at(sprintf('08:%02d:00', $minute));
            $cleanings[] = $this->openCleaning($ahmet, $machine);
        }

        $first = $this->actingAs($ahmet)->get(route('cleanings.index', ['status' => 'created']))->assertOk();
        $listed = $this->listedRecordNos($first);
        $this->assertCount(25, $listed);
        $this->assertSame($cleanings[25]->record_no, $listed[0]);
        $this->assertNotContains($cleanings[0]->record_no, $listed);

        $next = $this->page($first)->querySelector('.cleaning-list__pagination a[rel="next"]');
        $this->assertNotNull($next, 'Sonraki sayfa bağlantısı olmalı.');
        $this->assertStringContainsString('status=created', $next->getAttribute('href'));
        $this->assertStringContainsString('page=2', $next->getAttribute('href'));

        $second = $this->actingAs($ahmet)->get(route('cleanings.index', ['status' => 'created', 'page' => 2]))->assertOk();
        $this->assertSame([$cleanings[0]->record_no], $this->listedRecordNos($second));
    }

    public function test_empty_states(): void
    {
        $ahmet = $this->operator();

        $response = $this->actingAs($ahmet)->get(route('cleanings.index'))
            ->assertOk()
            ->assertSee('Henüz temizlik kaydı yok.')
            ->assertDontSee('Filtreyi temizle');
        $this->assertNull($this->page($response)->querySelector('.cleaning-list__table'));
        $this->assertNotNull($this->page($response)->querySelector('.cleaning-list__create'));

        $this->openCleaning($ahmet, $this->makeMachine());

        $this->actingAs($ahmet)->get(route('cleanings.index', ['status' => 'completed']))
            ->assertOk()
            ->assertSee('Filtreye uyan kayıt yok.')
            ->assertSee('Filtreyi temizle')
            ->assertDontSee('Henüz temizlik kaydı yok.');
    }

    public function test_query_count_does_not_grow_with_the_number_of_records(): void
    {
        $viewer = $this->operator('İzleyici');
        $this->openCleaning($this->operator('Ahmet'), $this->makeMachine(code: 'M01'), helpers: [$viewer]);

        $mine = fn () => $this->actingAs($viewer)->get(route('cleanings.index', ['mine' => 1]))->assertOk();
        $all = fn () => $this->actingAs($viewer)->get(route('cleanings.index'))->assertOk();
        $baselineMine = $this->countQueries($mine);
        $baselineAll = $this->countQueries($all);

        foreach (['M02', 'M03', 'M04'] as $code) {
            $owner = $this->operator("Sahip {$code}");
            $this->openCleaning($owner, $this->makeMachine(code: $code), helpers: [$viewer]);
            $running = $this->openCleaning($owner, $this->makeMachine(code: "{$code}B"));
            $this->runStep($owner, $running, 1);
            $this->workflow()->startStep($owner, $this->stepOf($running, 2));
        }

        $this->assertCount(4, $this->listedRecordNos($mine()));
        $this->assertCount(7, $this->listedRecordNos($all()));
        $this->assertSame($baselineMine, $this->countQueries($mine));
        $this->assertSame($baselineAll, $this->countQueries($all));
    }

    // ---------------------------------------------------------------------------------------

    private function page(TestResponse $response): HTMLDocument
    {
        return HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR);
    }

    private function rowOf(TestResponse $response, Cleaning $cleaning): Element
    {
        foreach ($this->page($response)->querySelectorAll('.cleaning-list__row') as $row) {
            if (trim($row->querySelector('.record-no')->textContent) === $cleaning->record_no) {
                return $row;
            }
        }

        $this->fail("{$cleaning->record_no} kayıt listesinde yok.");
    }

    /**
     * @return list<string>
     */
    private function listedRecordNos(TestResponse $response): array
    {
        return array_map(
            fn (Element $link) => trim($link->textContent),
            iterator_to_array($this->page($response)->querySelectorAll('.cleaning-list__row .record-no')),
        );
    }

    private function selectedValue(TestResponse $response, string $name): string
    {
        $option = $this->page($response)->querySelector(".cleaning-filters select[name=\"{$name}\"] option[selected]");

        return $option?->getAttribute('value') ?? '';
    }

    private function rowText(Element $element): string
    {
        return trim(preg_replace('/\s+/u', ' ', $element->textContent));
    }

    private function countQueries(Closure $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
