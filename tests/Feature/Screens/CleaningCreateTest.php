<?php

namespace Tests\Feature\Screens;

use App\Enums\CleaningStatus;
use App\Enums\CleaningType;
use App\Models\Cleaning;
use App\Models\CleaningStep;
use App\Models\Facility;
use App\Models\Line;
use App\Models\Machine;
use App\Models\Procedure;
use App\Models\User;
use App\Models\WorkOrder;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\TestCase;

/**
 * Kayıt açma formu ve kaydın açılması (R-14–R-20). Form yalnızca seçilebilecek olanı gösterir
 * (R-02, K-18); kural ihlalleri workflow'dan gelir ve forma `workflow` hatasıyla döner.
 * Detay sayfası başka bir ekranın işi: yalnızca yönlendirme adresi doğrulanır.
 */
class CleaningCreateTest extends TestCase
{
    use BuildsCleaningFixtures, InteractsWithCleaningWorkflow, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('08:00:00');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('cleanings.create'))->assertRedirect(route('login'));
        $this->post(route('cleanings.store'), [])->assertRedirect(route('login'));
        $this->assertSame(0, Cleaning::count());
    }

    public function test_only_usable_machines_are_offered_grouped_by_facility_and_line(): void
    {
        $usable = $this->makeMachine(code: 'M01');
        $this->makeMachine(code: 'M02')->update(['is_active' => false]);

        $line = $usable->line;
        Machine::create(['line_id' => $line->id, 'code' => 'M03', 'name' => 'Prosedürsüz makine']);

        // İleri tarihli yayımlanan versiyon henüz geçerli değil (K-15).
        $future = Procedure::create(['code' => 'PRC-FUT', 'name' => 'Gelecek prosedür']);
        $this->publishVersion($future, [['steps' => 1]])->update(['published_at' => now()->addDay()]);
        Machine::create(['line_id' => $line->id, 'procedure_id' => $future->id, 'code' => 'M04', 'name' => 'Yeni makine']);

        $otherLine = Line::create(['facility_id' => $line->facility_id, 'code' => 'H02', 'name' => 'Paketleme Hattı']);
        $other = $this->makeMachine(code: 'M05');
        $other->update(['line_id' => $otherLine->id]);

        $ankara = Facility::create(['code' => 'ANK', 'name' => 'Ankara Tesisi']);
        $ankaraLine = Line::create(['facility_id' => $ankara->id, 'code' => 'H01', 'name' => 'Dolum Hattı']);
        $ankaraMachine = $this->makeMachine(code: 'M06');
        $ankaraMachine->update(['line_id' => $ankaraLine->id]);

        $page = $this->page($this->actingAs($this->operator())->get(route('cleanings.create'))->assertOk()
            ->assertSee('<title>Yeni Kayıt', false));

        $groups = [];
        foreach ($page->querySelectorAll('#machine_id optgroup') as $group) {
            $groups[$group->getAttribute('label')] = array_map(
                fn (Element $option) => $this->text($option),
                iterator_to_array($group->querySelectorAll('option')),
            );
        }

        $this->assertSame([
            'Ankara Tesisi / Dolum Hattı' => ['M06 — Makine M06'],
            'İstanbul Tesisi / Hat 1' => ['M01 — Makine M01'],
            'İstanbul Tesisi / Paketleme Hattı' => ['M05 — Makine M05'],
        ], $groups);

        $placeholder = $page->querySelector('#machine_id option:not([value]), #machine_id option[value=""]');
        $this->assertSame('Makine seçin', $this->text($placeholder));
        $this->assertTrue($page->querySelector('#machine_id')->hasAttribute('required'));
    }

    public function test_machine_summary_shows_procedure_phases_material_requirement_and_pending_records(): void
    {
        $this->at('07:30:00');
        $machine = $this->makeMachine([['steps' => 3, 'min_seconds' => 900], ['steps' => 2]], materialRequired: true, code: 'M01');
        $procedure = $machine->procedure;
        $this->publishVersion($procedure, [['steps' => 4, 'min_seconds' => 1200], ['steps' => 2], ['steps' => 1, 'min_seconds' => 300]], materialRequired: true);
        $free = $this->makeMachine(code: 'M02');

        // K-05: aynı makinede başlamamış kayıt varsa uyarılır; başlamış ya da kapanmış kayıtlar sayılmaz.
        $pending = $this->openCleaning($this->operator('Mehmet'), $machine);
        $running = $this->openCleaning($this->operator('Ayşe'), $free);
        $this->workflow()->startStep($running->owner, $this->stepOf($running, 1));
        $this->at('08:00:00');

        $page = $this->page($this->actingAs($this->operator('Ahmet'))->get(route('cleanings.create'))->assertOk());

        $option = $page->querySelector("#machine_id option[value=\"{$machine->id}\"]");
        $this->assertSame('M01 — Makine M01 · 1 başlamamış kayıt var', $this->text($option));
        $this->assertSame('1', $option->getAttribute('data-material-required'));
        $this->assertSame((string) $machine->line_id, $option->getAttribute('data-line-id'));
        $this->assertSame('0', $page->querySelector("#machine_id option[value=\"{$free->id}\"]")->getAttribute('data-material-required'));
        $this->assertSame('M02 — Makine M02', $this->text($page->querySelector("#machine_id option[value=\"{$free->id}\"]")));

        // Özet, prosedürün geçerli (en son yayımlanmış) versiyonunu anlatır (K-15, K-18).
        $summary = $page->querySelector("[data-machine-summary=\"{$machine->id}\"]");
        $this->assertTrue($summary->hasAttribute('hidden'), 'Özet JS ile gösterilir.');
        $text = $this->text($summary);
        $this->assertStringContainsString('Prosedür M01 temizlik prosedürü PRC-M01', $text);
        $this->assertStringContainsString('Versiyon v2', $text);
        $this->assertStringContainsString('Kapsam 3 faz, 7 adım', $text);
        $this->assertStringContainsString('Malzeme Zorunlu: ilk adım malzeme girilmeden başlatılamaz', $text);
        $this->assertSame(
            ['Faz 1 4 adım · en az 20 dk 00 sn', 'Faz 2 2 adım · minimum süre yok', 'Faz 3 1 adım · en az 5 dk 00 sn'],
            array_map(fn (Element $phase) => $this->text($phase), iterator_to_array($summary->querySelectorAll('.machine-summary__phase'))),
        );

        $warning = $summary->querySelector('.machine-summary__pending');
        $this->assertNotNull($warning, 'Başlamamış kayıt uyarısı olmalı (K-05).');
        $this->assertStringContainsString('başlamamış 1 kayıt var', $this->text($warning));
        $this->assertStringContainsString("{$pending->record_no} — Mehmet, 09.10.2026 10:30", $this->text($warning));
        $this->assertSame(route('cleanings.show', $pending), $warning->querySelector('a')->getAttribute('href'));

        $freeSummary = $page->querySelector("[data-machine-summary=\"{$free->id}\"]");
        $this->assertStringContainsString('Malzeme Zorunlu değil', $this->text($freeSummary));
        $this->assertNull($freeSummary->querySelector('.machine-summary__pending'), 'Devam eden kayıt uyarı sebebi değildir.');
    }

    public function test_form_offers_types_helpers_work_orders_and_material_catalog(): void
    {
        $machine = $this->makeMachine(code: 'M01');
        $ahmet = $this->operator('Ahmet');
        $this->operator('Zeki');
        $this->operator('Can');
        User::factory()->inactive()->create(['name' => 'Ayrılan Personel']);
        $this->manager('Yönetici Zeynep');

        $this->makeMaterial('DEZ-02');
        $this->makeMaterial('DET-01');

        WorkOrder::create(['code' => 'IE-2', 'line_id' => $machine->line_id, 'description' => 'Şurup hazırlama']);
        WorkOrder::create(['code' => 'IE-1', 'line_id' => $machine->line_id, 'machine_id' => $machine->id, 'description' => 'Şurup dolum']);
        WorkOrder::create(['code' => 'IE-3']);

        $page = $this->page($this->actingAs($ahmet)->get(route('cleanings.create'))->assertOk());

        // Tür: varsayılan seçim yok, açıklamalı (K-18, R-19).
        $types = $page->querySelectorAll('input[name="type"]');
        $this->assertSame(['planned', 'unplanned'], array_map(fn (Element $input) => $input->getAttribute('value'), iterator_to_array($types)));
        $this->assertNull($page->querySelector('input[name="type"][checked]'));
        $this->assertStringContainsString('Saha defterine işlenmez', $this->text($page->querySelector('#type-unplanned-help')));
        $this->assertSame('Plansız müdahale', $this->text($page->querySelector('label[for="type-unplanned"]')));

        // Yardımcılar: aktif kullanıcılar, kaydı açan hariç, ada göre sıralı.
        $this->assertSame(['Can', 'Yönetici Zeynep', 'Zeki'], array_map(
            fn (Element $input) => $this->text($page->querySelector("label[for=\"{$input->getAttribute('id')}\"]")),
            iterator_to_array($page->querySelectorAll('input[name="helper_ids[]"]')),
        ));
        $this->assertStringContainsString('Sorumlu: Ahmet (siz)', $this->text($page->querySelector('.cleaning-form__owner')));

        // İş emirleri: hepsi listelenir, makine/hat bilgisi etiket ve data-* özniteliklerinde.
        $workOrders = [];
        foreach ($page->querySelectorAll('#work_order_id option') as $option) {
            $workOrders[] = [$this->text($option), $option->getAttribute('data-machine-id'), $option->getAttribute('data-line-id')];
        }
        $this->assertSame([
            ['İş emri yok', null, null],
            ['IE-1 — Şurup dolum (IST / H01 / M01)', (string) $machine->id, (string) $machine->line_id],
            ['IE-2 — Şurup hazırlama (IST / H01)', '', (string) $machine->line_id],
            ['IE-3 (bütün makineler)', '', ''],
        ], $workOrders);

        // Malzeme: katalogdan seçim, JS olmadan da gönderilebilen tek boş satır ve satır şablonu.
        $rows = $page->querySelectorAll('.material-rows [data-material-row]');
        $this->assertCount(1, $rows);
        $this->assertSame(
            ['Malzeme seçin', 'DET-01 — Malzeme DET-01', 'DEZ-02 — Malzeme DEZ-02'],
            array_map(fn (Element $option) => $this->text($option), iterator_to_array($rows[0]->querySelectorAll('select[name="materials[0][material_id]"] option'))),
        );
        $this->assertNotNull($rows[0]->querySelector('input[name="materials[0][lot_no]"]'));
        $this->assertSame('date', $rows[0]->querySelector('input[name="materials[0][expiry_date]"]')->getAttribute('type'));
        $this->assertStringContainsString('name="materials[__INDEX__][lot_no]"', $page->querySelector('template[data-material-template]')->innerHTML);
        $this->assertTrue($page->querySelector('[data-material-add]')->hasAttribute('hidden'), '"Malzeme ekle" yalnızca JS ile görünür.');

        $this->assertSame('cleaning-form', $page->querySelector('form.cleaning-form')->getAttribute('data-module'));
        $this->assertSame(route('cleanings.store'), $page->querySelector('form.cleaning-form')->getAttribute('action'));
    }

    public function test_store_opens_the_record_and_redirects_to_its_page(): void
    {
        $machine = $this->makeMachine([['steps' => 2], ['steps' => 1]], materialRequired: true, code: 'M03');
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $ayse = $this->operator('Ayşe');
        $detergent = $this->makeMaterial('DET-01');
        $disinfectant = $this->makeMaterial('DEZ-02');
        $workOrder = WorkOrder::create(['code' => 'IE-1', 'line_id' => $machine->line_id, 'machine_id' => $machine->id]);

        $response = $this->actingAs($ahmet)->from(route('cleanings.create'))->post(route('cleanings.store'), [
            'machine_id' => $machine->id,
            'type' => 'planned',
            'helper_ids' => [$mehmet->id, $ayse->id, $ahmet->id],
            'work_order_id' => $workOrder->id,
            'notes' => '  Ürün değişimi: şurup → süspansiyon  ',
            'materials' => [
                ['material_id' => $detergent->id, 'lot_no' => 'LOT-A1', 'expiry_date' => '2027-01-31'],
                ['material_id' => $disinfectant->id, 'lot_no' => 'LOT-B2', 'expiry_date' => '2026-10-09'],
            ],
        ]);

        $cleaning = Cleaning::query()->sole();
        $response->assertRedirect(route('cleanings.show', $cleaning))
            ->assertSessionHas('status', "Kayıt açıldı: {$cleaning->record_no}")
            ->assertSessionHasNoErrors();

        $this->assertSame('IST-H01-M03-T-2026-0001', $cleaning->record_no);
        $this->assertSame(CleaningType::Planned, $cleaning->type);
        $this->assertSame(CleaningStatus::Created, $cleaning->status, 'Kayıt açmak işe başlamak değildir (R-20).');
        $this->assertNull($cleaning->started_at);
        $this->assertSame($ahmet->id, $cleaning->owner_id);
        $this->assertSame($machine->id, $cleaning->machine_id);
        $this->assertSame($workOrder->id, $cleaning->work_order_id);
        $this->assertSame('Ürün değişimi: şurup → süspansiyon', $cleaning->notes);

        // R-23: sahibi ve yardımcılar her adıma görevli.
        $steps = CleaningStep::query()->where('cleaning_id', $cleaning->id)->orderBy('sequence')->get();
        $this->assertCount(3, $steps);
        foreach ($steps as $step) {
            $this->assertSame($this->sortedIds($ahmet, $mehmet, $ayse), $this->activeAssigneeIds($step));
        }

        $materials = $cleaning->materials()->with('material')->orderBy('id')->get();
        $this->assertSame([
            ['DET-01', 'LOT-A1', '2027-01-31', $ahmet->id],
            ['DEZ-02', 'LOT-B2', '2026-10-09', $ahmet->id],
        ], $materials->map(fn ($item) => [$item->material->code, $item->lot_no, $item->expiry_date->toDateString(), $item->added_by])->all());
    }

    public function test_store_opens_an_unplanned_intervention_without_optional_fields(): void
    {
        $machine = $this->makeMachine(code: 'M03');
        $ahmet = $this->operator();

        $this->actingAs($ahmet)->post(route('cleanings.store'), [
            'machine_id' => $machine->id,
            'type' => 'unplanned',
            'work_order_id' => '',
            'notes' => '',
        ])->assertSessionHasNoErrors();

        $cleaning = Cleaning::query()->sole();
        $this->assertSame(CleaningType::Unplanned, $cleaning->type);
        $this->assertSame('IST-H01-M03-M-2026-0001', $cleaning->record_no);
        $this->assertNull($cleaning->field_ref);
        $this->assertNull($cleaning->work_order_id);
        $this->assertNull($cleaning->notes);
        $this->assertSame(0, $cleaning->materials()->count());
        $this->assertSame([$ahmet->id], $this->activeAssigneeIds($this->stepOf($cleaning, 1)));
    }

    public function test_blank_material_rows_are_ignored(): void
    {
        $machine = $this->makeMachine(code: 'M03');
        $material = $this->makeMaterial();
        $ahmet = $this->operator();

        $this->actingAs($ahmet)->post(route('cleanings.store'), [
            'machine_id' => $machine->id,
            'type' => 'planned',
            'materials' => [
                ['material_id' => '', 'lot_no' => '', 'expiry_date' => ''],
                ['material_id' => $material->id, 'lot_no' => 'LOT-7', 'expiry_date' => '2027-05-01'],
                ['material_id' => '', 'lot_no' => '   ', 'expiry_date' => ''],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(['LOT-7'], Cleaning::query()->sole()->materials()->pluck('lot_no')->all());

        // Yalnızca boş satır gönderilirse kayıt malzemesiz açılır.
        $this->actingAs($ahmet)->post(route('cleanings.store'), [
            'machine_id' => $machine->id,
            'type' => 'planned',
            'materials' => [['material_id' => '', 'lot_no' => '', 'expiry_date' => '']],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(0, Cleaning::query()->latest('id')->first()->materials()->count());
    }

    public function test_validation_errors_are_turkish_and_the_form_is_repopulated(): void
    {
        $machine = $this->makeMachine(code: 'M03');
        $material = $this->makeMaterial();
        $mehmet = $this->operator('Mehmet');
        $ahmet = $this->operator('Ahmet');

        $this->actingAs($ahmet)->from(route('cleanings.create'))->post(route('cleanings.store'), [])
            ->assertRedirect(route('cleanings.create'))
            ->assertSessionHasErrors([
                'machine_id' => 'makine zorunludur.',
                'type' => 'tür zorunludur.',
            ]);

        $invalid = [
            'machine_id' => 999999,
            'type' => 'periyodik',
            'helper_ids' => [$mehmet->id, 999999],
            'work_order_id' => 999999,
            'notes' => str_repeat('a', 2001),
            'materials' => [
                ['material_id' => '', 'lot_no' => '', 'expiry_date' => ''],
                ['material_id' => $material->id, 'lot_no' => '', 'expiry_date' => '31.12.2027'],
            ],
        ];

        $this->actingAs($ahmet)->from(route('cleanings.create'))->post(route('cleanings.store'), $invalid)
            ->assertRedirect(route('cleanings.create'))
            ->assertSessionHasErrors([
                'machine_id' => 'Seçilen makine geçersiz.',
                'type' => 'Seçilen tür geçersiz.',
                'helper_ids.1' => 'Seçilen yardımcı personel geçersiz.',
                'work_order_id' => 'Seçilen iş emri geçersiz.',
                'notes' => 'açıklama en fazla 2000 karakter olabilir.',
                'materials.1.lot_no' => 'lot numarası zorunludur.',
                'materials.1.expiry_date' => 'son kullanma tarihi Y-m-d biçiminde olmalıdır.',
            ]);
        $this->assertSame(0, Cleaning::count());

        // Form, hatalarla ve girilen değerlerle (malzeme satırları dahil) yeniden gösterilir.
        $page = $this->page($this->actingAs($ahmet)->from(route('cleanings.create'))->followingRedirects()
            ->post(route('cleanings.store'), $invalid)
            ->assertOk());

        $this->assertStringContainsString('Kayıt açılamadı', $this->text($page->querySelector('.cleaning-form__errors')));
        $this->assertSame('Seçilen makine geçersiz.', $this->text($page->getElementById('machine_id-error')));
        $this->assertSame('Seçilen yardımcı personel geçersiz.', $this->text($page->getElementById('helper_ids-error')));
        $this->assertTrue($page->querySelector("#helper-{$mehmet->id}")->hasAttribute('checked'));
        $this->assertSame(str_repeat('a', 2001), $page->querySelector('#notes')->textContent);

        $rows = $page->querySelectorAll('.material-rows [data-material-row]');
        $this->assertCount(2, $rows, 'Gönderilen satırlar aynı anahtarlarla geri gelir.');
        $this->assertSame((string) $material->id, $rows[1]->querySelector('select[name="materials[1][material_id]"] option[selected]')->getAttribute('value'));
        $this->assertSame('31.12.2027', $rows[1]->querySelector('input[name="materials[1][expiry_date]"]')->getAttribute('value'));

        $lot = $rows[1]->querySelector('input[name="materials[1][lot_no]"]');
        $this->assertTrue($lot->classList->contains('is-invalid'));
        $this->assertSame('lot numarası zorunludur.', $this->text($page->getElementById($lot->getAttribute('aria-describedby'))));
        $this->assertFalse($rows[0]->querySelector('input[name="materials[0][lot_no]"]')->classList->contains('is-invalid'));
        $this->assertSame('2', $page->querySelector('[data-material-rows]')->getAttribute('data-next-index'), 'JS yeni satıra çakışmayan anahtar verir.');
    }

    public function test_expired_material_comes_back_with_the_workflow_error(): void
    {
        $machine = $this->makeMachine(code: 'M03');
        $material = $this->makeMaterial();
        $ahmet = $this->operator();

        // K-14: son kullanma tarihi bugünden önceyse kaydedilemez; kural workflow'da.
        $expired = [
            'machine_id' => $machine->id,
            'type' => 'planned',
            'materials' => [
                ['material_id' => $material->id, 'lot_no' => 'LOT-OLD', 'expiry_date' => '2026-10-08'],
            ],
        ];

        $this->actingAs($ahmet)->from(route('cleanings.create'))->post(route('cleanings.store'), $expired)
            ->assertRedirect(route('cleanings.create'))
            ->assertSessionHasErrors(['workflow' => 'LOT-OLD lotunun son kullanma tarihi (2026-10-08) geçmiş.'])
            ->assertSessionHas('violation.rule', 'material_expired');
        $this->assertSame(0, Cleaning::count());

        // Mesaj merkezi olarak (flash partial) gösterilir, form girilen değerlerle geri gelir.
        $page = $this->page($this->actingAs($ahmet)->from(route('cleanings.create'))->followingRedirects()
            ->post(route('cleanings.store'), $expired)
            ->assertOk()
            ->assertSee('LOT-OLD lotunun son kullanma tarihi (2026-10-08) geçmiş.'));
        $this->assertSame((string) $machine->id, $page->querySelector('#machine_id option[selected]')->getAttribute('value'));
        $this->assertSame('planned', $page->querySelector('input[name="type"][checked]')->getAttribute('value'));
        $this->assertSame('LOT-OLD', $page->querySelector('input[name="materials[0][lot_no]"]')->getAttribute('value'));
        $this->assertNull($page->querySelector('.cleaning-form__errors'), 'İş kuralı hatası alan hatası değildir.');
    }

    public function test_work_order_of_another_machine_comes_back_with_the_workflow_error(): void
    {
        $machine = $this->makeMachine(code: 'M03');
        $other = $this->makeMachine(code: 'M04');
        $workOrder = WorkOrder::create(['code' => 'IE-9', 'line_id' => $other->line_id, 'machine_id' => $other->id]);

        // K-19: form uygun olmayan iş emrini gizler, ama asıl kontrol workflow'dadır.
        $this->actingAs($this->operator())->from(route('cleanings.create'))->post(route('cleanings.store'), [
            'machine_id' => $machine->id,
            'type' => 'planned',
            'work_order_id' => $workOrder->id,
        ])
            ->assertRedirect(route('cleanings.create'))
            ->assertSessionHasErrors(['workflow' => 'Seçilen iş emri bu makineye ait değil.'])
            ->assertSessionHasInput('work_order_id', $workOrder->id);

        $this->assertSame(0, Cleaning::count());
    }

    public function test_unusable_machine_and_inactive_helper_are_rejected_by_the_workflow(): void
    {
        $retired = $this->makeMachine(code: 'M03');
        $retired->update(['is_active' => false]);
        $machine = $this->makeMachine(code: 'M04');
        $ahmet = $this->operator();
        $former = User::factory()->inactive()->create(['name' => 'Eski Personel']);

        // R-02: listede olmayan makine elle gönderilse de kayıt açılmaz (K-16).
        $this->actingAs($ahmet)->from(route('cleanings.create'))->post(route('cleanings.store'), [
            'machine_id' => $retired->id,
            'type' => 'planned',
        ])->assertSessionHasErrors(['workflow' => 'M03 makinesi kullanımdan kaldırılmış.']);

        $this->actingAs($ahmet)->from(route('cleanings.create'))->post(route('cleanings.store'), [
            'machine_id' => $machine->id,
            'type' => 'planned',
            'helper_ids' => [$former->id],
        ])->assertSessionHasErrors(['workflow' => 'Eski Personel aktif bir kullanıcı değil.']);

        $this->assertSame(0, Cleaning::count());
    }

    // ---------------------------------------------------------------------------------------

    private function page(TestResponse $response): HTMLDocument
    {
        return HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR);
    }

    private function text(Element $element): string
    {
        return trim(preg_replace('/\s+/u', ' ', $element->textContent));
    }
}
