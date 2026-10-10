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
        $this->publishVersion($future, [['steps' => 1]], publishedAt: now()->addDay());
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

    public function test_missing_machine_message_is_ready_below_the_field(): void
    {
        // Tarayıcının genel "Listeden bir öğe seçin" uyarısı yerine (cleaning-form.js), gizli gelir.
        $this->makeMachine(code: 'M01');

        $page = $this->page($this->actingAs($this->operator())->get(route('cleanings.create'))->assertOk());

        $message = $page->querySelector('#machine_id ~ #machine_id-required');
        $this->assertNotNull($message);
        $this->assertTrue($message->hasAttribute('hidden'));
        $this->assertTrue($message->classList->contains('invalid-feedback'));
        $this->assertSame('Lütfen bir makine seçin.', $this->text($message));
        $this->assertTrue($page->querySelector('#machine_id')->hasAttribute('required'));
    }

    public function test_extra_material_explains_when_there_is_no_usable_lot(): void
    {
        $this->makeMachine(code: 'M01');
        $material = $this->makeMaterial('DET-01');
        $operator = $this->operator();

        $page = $this->page($this->actingAs($operator)->get(route('cleanings.create'))->assertOk());
        $this->assertStringContainsString('Kullanımda ve son kullanma tarihi geçmemiş lot yok', $this->text($page->querySelector('.material-rows__no-lots')));

        $this->lot($material, 'DT-1', '2027-01-31');
        $page = $this->page($this->actingAs($operator)->get(route('cleanings.create'))->assertOk());
        $this->assertNull($page->querySelector('.material-rows__no-lots'));
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

        $disinfectant = $this->makeMaterial('DEZ-02');
        $detergent = $this->makeMaterial('DET-01');
        $this->makeMaterial('ALK-04'); // kullanılabilir lotu olmayan malzeme listelenmez
        $this->lot($detergent, 'DT-2', '2027-06-30');
        $this->lot($detergent, 'DT-1', '2027-01-31');
        $this->lot($detergent, 'DT-ESKI', '2026-10-08'); // SKT'si geçmiş (K-14)
        $this->lot($disinfectant, 'DZ-1', '2026-10-09'); // SKT bugün: geçerli
        $this->lot($disinfectant, 'DZ-GERI', '2027-12-31')->update(['is_active' => false]);

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

        // Üretim iş emirleri: hepsi listelenir, makine/hat bilgisi etiket ve data-* özniteliklerinde.
        $workOrders = [];
        foreach ($page->querySelectorAll('#work_order_id option') as $option) {
            $workOrders[] = [$this->text($option), $option->getAttribute('data-machine-id'), $option->getAttribute('data-line-id')];
        }
        $this->assertSame([
            ['Üretim iş emri yok', null, null],
            ['IE-1 — Şurup dolum (IST / H01 / M01)', (string) $machine->id, (string) $machine->line_id],
            ['IE-2 — Şurup hazırlama (IST / H01)', '', (string) $machine->line_id],
            ['IE-3 (bütün makineler)', '', ''],
        ], $workOrders);

        // Ek malzeme: JS olmadan da gönderilebilen tek boş satır ve satır şablonu. Lot tek seçimde,
        // malzemeye göre gruplu; yalnızca kullanımdaki ve SKT'si geçmemiş lotlar (K-14). Lot no ve
        // SKT elle girilmez.
        $rows = $page->querySelectorAll('.material-rows [data-material-row]');
        $this->assertCount(1, $rows);
        $select = $rows[0]->querySelector('select[name="materials[0][material_lot_id]"]');
        $this->assertSame('Malzeme ve lot seçin', $this->text($select->querySelector('option')));
        $groups = [];
        foreach ($select->querySelectorAll('optgroup') as $group) {
            $groups[$group->getAttribute('label')] = array_map(fn (Element $option) => $this->text($option), iterator_to_array($group->querySelectorAll('option')));
        }
        $this->assertSame([
            'DET-01 — Malzeme DET-01' => ['DT-1 · SKT 31.01.2027', 'DT-2 · SKT 30.06.2027'],
            'DEZ-02 — Malzeme DEZ-02' => ['DZ-1 · SKT 09.10.2026'],
        ], $groups);
        $this->assertNull($rows[0]->querySelector('input[name*="lot_no"], input[name*="expiry_date"]'));
        $this->assertStringContainsString('name="materials[__INDEX__][material_lot_id]"', $page->querySelector('template[data-material-template]')->innerHTML);
        $this->assertTrue($page->querySelector('[data-material-add]')->hasAttribute('hidden'), '"Ek malzeme ekle" yalnızca JS ile görünür.');

        $this->assertSame('cleaning-form', $page->querySelector('form.cleaning-form')->getAttribute('data-module'));
        $this->assertSame(route('cleanings.store'), $page->querySelector('form.cleaning-form')->getAttribute('action'));
    }

    public function test_expected_materials_of_each_machine_come_with_their_lots(): void
    {
        // K-13: makine seçilince prosedürün beklediği malzemeler satır olarak gelir; operatör yalnızca
        // lot seçer (K-14). Her makinenin satırları ayrı fieldset'te; seçili olmayanlar devre dışıdır,
        // gönderilmez.
        $detergent = $this->makeMaterial('DET-01');
        $acid = $this->makeMaterial('DUR-03');
        $machine = $this->makeMachine(code: 'M01', materials: [[$detergent, true], [$acid, false]]);
        $plain = $this->makeMachine(code: 'M02');
        $this->lot($detergent, 'DT-1', '2027-01-31');
        $this->lot($detergent, 'DT-ESKI', '2026-10-01');
        $ahmet = $this->operator('Ahmet');

        $page = $this->page($this->actingAs($ahmet)->get(route('cleanings.create'))->assertOk());

        $this->assertStringContainsString(
            'Malzeme DET-01 (zorunlu), DUR-03 (isteğe bağlı)',
            $this->text($page->querySelector("[data-machine-summary=\"{$machine->id}\"] .machine-summary__fact--materials")),
        );

        $group = $page->querySelector("fieldset[data-expected-materials=\"{$machine->id}\"]");
        $this->assertTrue($group->hasAttribute('disabled'), 'Makine seçilmeden satırlar gönderilmez.');
        $this->assertTrue($group->hasAttribute('hidden'));
        $this->assertFalse($page->querySelector('.expected-materials__empty')->hasAttribute('hidden'));

        $rows = $group->querySelectorAll('.material-row--expected');
        $this->assertCount(2, $rows);
        $this->assertSame('DET-01 — Malzeme DET-01 Zorunlu', $this->text($rows[0]->querySelector('.material-row__field--material')));
        $this->assertSame((string) $detergent->id, $rows[0]->querySelector("input[type=\"hidden\"][name=\"materials[p{$detergent->id}][material_id]\"]")->getAttribute('value'));
        $this->assertSame(
            ['Lot seçin', 'DT-1 · SKT 31.01.2027'],
            array_map(fn (Element $option) => $this->text($option), iterator_to_array($rows[0]->querySelectorAll("select[name=\"materials[p{$detergent->id}][material_lot_id]\"] option"))),
        );

        // Kullanılabilir lotu olmayan malzeme: seçim devre dışı, açıklamalı.
        $this->assertSame('DUR-03 — Malzeme DUR-03 İsteğe bağlı', $this->text($rows[1]->querySelector('.material-row__field--material')));
        $this->assertTrue($rows[1]->querySelector('select')->hasAttribute('disabled'));
        $this->assertStringContainsString('lotu yok', $this->text($rows[1]));

        $this->assertStringContainsString('beklenen malzeme yok', $this->text($page->querySelector("fieldset[data-expected-materials=\"{$plain->id}\"]")));

        // Hatayla dönülünce seçili makinenin satırları etkin ve seçilen lot korunur.
        $lot = $detergent->lots()->where('lot_no', 'DT-1')->sole();
        $page = $this->page($this->actingAs($ahmet)->from(route('cleanings.create'))->followingRedirects()
            ->post(route('cleanings.store'), [
                'machine_id' => $machine->id,
                'materials' => ["p{$detergent->id}" => ['material_id' => $detergent->id, 'material_lot_id' => $lot->id]],
            ])
            ->assertOk());

        $group = $page->querySelector("fieldset[data-expected-materials=\"{$machine->id}\"]");
        $this->assertFalse($group->hasAttribute('disabled'));
        $this->assertFalse($group->hasAttribute('hidden'));
        $this->assertTrue($page->querySelector("fieldset[data-expected-materials=\"{$plain->id}\"]")->hasAttribute('disabled'));
        $this->assertSame((string) $lot->id, $group->querySelector("select[name=\"materials[p{$detergent->id}][material_lot_id]\"] option[selected]")->getAttribute('value'));
        $this->assertSame(0, Cleaning::count(), 'Tür seçilmediği için kayıt açılmadı.');
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
                "p{$detergent->id}" => ['material_id' => $detergent->id, 'material_lot_id' => $this->lot($detergent, 'LOT-A1', '2027-01-31')->id],
                0 => ['material_lot_id' => $this->lot($disinfectant, 'LOT-B2', '2026-10-09')->id],
            ],
        ]);

        $cleaning = Cleaning::query()->sole();
        $response->assertRedirect(route('cleanings.show', $cleaning))
            ->assertSessionHas('status', "Kayıt açıldı: {$cleaning->record_no}")
            ->assertSessionHasNoErrors();

        $this->assertSame('IST-H01M03-260001', $cleaning->record_no);
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

        // K-14: lot no ve SKT lot kaydından kopyalanır.
        $materials = $cleaning->materials()->with(['material', 'lot'])->orderBy('id')->get();
        $this->assertSame([
            ['DET-01', 'LOT-A1', '2027-01-31', 'LOT-A1', $ahmet->id],
            ['DEZ-02', 'LOT-B2', '2026-10-09', 'LOT-B2', $ahmet->id],
        ], $materials->map(fn ($item) => [$item->material->code, $item->lot_no, $item->expiry_date->toDateString(), $item->lot->lot_no, $item->added_by])->all());
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
        $this->assertSame('IST-H01M03-260001', $cleaning->record_no);
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
                0 => ['material_lot_id' => ''],
                1 => ['material_lot_id' => $this->lot($material, 'LOT-7', '2027-05-01')->id],
                2 => ['material_lot_id' => '   '],
                // Prosedürden gelen satırda lot seçilmediyse yalnızca malzeme gelir; yok sayılır.
                "p{$material->id}" => ['material_id' => $material->id, 'material_lot_id' => ''],
                "p{$this->makeMaterial('DUR-03')->id}" => ['material_id' => '1'],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(['LOT-7'], Cleaning::query()->sole()->materials()->pluck('lot_no')->all());

        // Yalnızca boş satır gönderilirse kayıt malzemesiz açılır.
        $this->actingAs($ahmet)->post(route('cleanings.store'), [
            'machine_id' => $machine->id,
            'type' => 'planned',
            'materials' => [['material_lot_id' => '']],
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
                ['material_lot_id' => ''],
                ['material_lot_id' => 999999],
            ],
        ];

        $this->actingAs($ahmet)->from(route('cleanings.create'))->post(route('cleanings.store'), $invalid)
            ->assertRedirect(route('cleanings.create'))
            ->assertSessionHasErrors([
                'machine_id' => 'Seçilen makine geçersiz.',
                'type' => 'Seçilen tür geçersiz.',
                'helper_ids.1' => 'Seçilen yardımcı personel geçersiz.',
                'work_order_id' => 'Seçilen üretim iş emri geçersiz.',
                'notes' => 'açıklama en fazla 2000 karakter olabilir.',
            ])
            ->assertSessionHasErrors(['materials.1.material_lot_id'])
            ->assertSessionDoesntHaveErrors(['materials.0.material_lot_id']);
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

        $lot = $rows[1]->querySelector('select[name="materials[1][material_lot_id]"]');
        $this->assertTrue($lot->classList->contains('is-invalid'));
        $this->assertNotSame('', $this->text($page->getElementById($lot->getAttribute('aria-describedby'))));
        $this->assertFalse($rows[0]->querySelector('select[name="materials[0][material_lot_id]"]')->classList->contains('is-invalid'));
        $this->assertSame('2', $page->querySelector('[data-material-rows]')->getAttribute('data-next-index'), 'JS yeni satıra çakışmayan anahtar verir.');
    }

    public function test_expired_material_comes_back_with_the_workflow_error(): void
    {
        $machine = $this->makeMachine(code: 'M03');
        $material = $this->makeMaterial();
        $ahmet = $this->operator();

        // K-14: SKT'si bugünden önce olan lot kaydedilemez; kural workflow'da. Form bu lotu zaten
        // listelemez, ama eski bir sayfadan ya da elle gönderilebilir.
        $expired = [
            'machine_id' => $machine->id,
            'type' => 'planned',
            'materials' => [
                ['material_lot_id' => $this->lot($material, 'LOT-OLD', '2026-10-08')->id],
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
        $this->assertNull($page->querySelector('select[name="materials[0][material_lot_id]"] option[selected]'), 'Geçmiş lot seçenek olarak sunulmaz.');
        $this->assertNull($page->querySelector('.cleaning-form__errors'), 'İş kuralı hatası alan hatası değildir.');
    }

    public function test_work_order_of_another_machine_comes_back_with_the_workflow_error(): void
    {
        $machine = $this->makeMachine(code: 'M03');
        $other = $this->makeMachine(code: 'M04');
        $workOrder = WorkOrder::create(['code' => 'IE-9', 'line_id' => $other->line_id, 'machine_id' => $other->id]);

        // K-19: form uygun olmayan üretim iş emrini gizler, ama asıl kontrol workflow'dadır.
        $this->actingAs($this->operator())->from(route('cleanings.create'))->post(route('cleanings.store'), [
            'machine_id' => $machine->id,
            'type' => 'planned',
            'work_order_id' => $workOrder->id,
        ])
            ->assertRedirect(route('cleanings.create'))
            ->assertSessionHasErrors(['workflow' => 'Seçilen üretim iş emri bu makineye ait değil.'])
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
