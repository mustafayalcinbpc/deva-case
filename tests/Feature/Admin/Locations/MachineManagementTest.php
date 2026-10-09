<?php

namespace Tests\Feature\Admin\Locations;

use App\Models\Facility;
use App\Models\Line;
use App\Models\Machine;
use App\Models\Procedure;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Testing\TestResponse;

/**
 * Makine tanımları (R-02, R-43, K-17, K-18): liste ve filtreler, ekleme, düzenleme, ayrıntı.
 */
class MachineManagementTest extends LocationsTestCase
{
    public function test_index_lists_machines_with_location_procedure_and_state(): void
    {
        $active = $this->makeMachine(code: 'M01');
        $retired = $this->makeMachine(code: 'M02');
        $retired->update(['is_active' => false]);
        Machine::create(['line_id' => $active->line_id, 'code' => 'M03', 'name' => 'Prosedürsüz']);
        $this->openCleaning($this->operator(), $active);

        $response = $this->actingAs($this->manager)->get(route('admin.machines.index'))
            ->assertOk()
            ->assertSee('<title>Makineler', false)
            ->assertSee(route('admin.machines.create'), false);

        $this->assertSame([
            ['M01 Makine M01', 'IST / H01', 'PRC-M01 — M01 temizlik prosedürü v1', 'Kullanımda', '1'],
            ['M02 Makine M02', 'IST / H01', 'PRC-M02 — M02 temizlik prosedürü v1', 'Kullanımdan kaldırıldı', '—'],
            ['M03 Prosedürsüz', 'IST / H01', 'Prosedür atanmamış', 'Kullanımda', '—'],
        ], $this->rows($response));
    }

    public function test_index_filters_by_line_and_state(): void
    {
        $m1 = $this->makeMachine(code: 'M01');
        $this->makeMachine(code: 'M02')->update(['is_active' => false]);
        $otherLine = Line::create(['facility_id' => $m1->line->facility_id, 'code' => 'H02', 'name' => 'Hat 2']);
        $this->makeMachine(code: 'M05')->update(['line_id' => $otherLine->id]);

        $codes = fn (array $query) => array_column($this->rows(
            $this->actingAs($this->manager)->get(route('admin.machines.index', $query))->assertOk()
        ), 0);

        $this->assertSame(['M01 Makine M01', 'M02 Makine M02', 'M05 Makine M05'], $codes([]));
        $this->assertSame(['M05 Makine M05'], $codes(['line_id' => $otherLine->id]));
        $this->assertSame(['M01 Makine M01', 'M05 Makine M05'], $codes(['status' => 'active']));
        $this->assertSame(['M02 Makine M02'], $codes(['status' => 'retired']));
        $this->assertSame(['M01 Makine M01'], $codes(['status' => 'active', 'line_id' => $m1->line_id]));
        // Geçersiz filtre yok sayılır.
        $this->assertSame(['M01 Makine M01', 'M02 Makine M02', 'M05 Makine M05'], $codes(['status' => 'x', 'line_id' => 'abc']));

        $this->actingAs($this->manager)->get(route('admin.machines.index', ['status' => 'retired', 'line_id' => $otherLine->id]))
            ->assertOk()
            ->assertSee('Filtreye uyan makine yok.');
    }

    public function test_create_form_offers_lines_and_only_procedures_with_a_published_version(): void
    {
        $machine = $this->makeMachine(code: 'M01');
        $this->draftProcedure('PRC-TASLAK');
        $future = Procedure::create(['code' => 'PRC-GELECEK', 'name' => 'Gelecek']);
        // İleri tarihli yayın, tarihi gelene kadar geçerli versiyon sayılmaz (K-15).
        $this->draftVersion($future)->update(['published_at' => now()->addDay()]);

        $page = $this->page($this->actingAs($this->manager)
            ->get(route('admin.machines.create', ['line_id' => $machine->line_id]))
            ->assertOk()
            ->assertSee('<title>Yeni makine', false));

        $procedures = array_map(fn ($option) => $this->text($option), iterator_to_array($page->querySelectorAll('#procedure_id option')));
        $this->assertSame(['Prosedür seçin', 'PRC-M01 — M01 temizlik prosedürü (v1)'], $procedures);

        $selectedLine = $page->querySelector('#line_id option[selected]');
        $this->assertSame((string) $machine->line_id, $selectedLine->getAttribute('value'));
        $this->assertSame('IST — İstanbul Tesisi', $page->querySelector('#line_id optgroup')->getAttribute('label'));
    }

    public function test_manager_creates_a_machine(): void
    {
        $line = Line::create([
            'facility_id' => Facility::create(['code' => 'IST', 'name' => 'İstanbul'])->id,
            'code' => 'H01',
            'name' => 'Hat 1',
        ]);
        $procedure = $this->publishedProcedure();

        $response = $this->actingAs($this->manager)->post(route('admin.machines.store'), [
            'line_id' => $line->id,
            'code' => 'm07',
            'name' => 'Dolum Makinesi',
            'procedure_id' => $procedure->id,
        ]);

        $machine = Machine::sole();
        $response->assertRedirect(route('admin.machines.show', $machine))
            ->assertSessionHas('status', 'M07 makinesi eklendi.');

        $this->assertSame($line->id, $machine->line_id);
        $this->assertSame('M07', $machine->code);
        $this->assertSame('Dolum Makinesi', $machine->name);
        $this->assertSame($procedure->id, $machine->procedure_id);
        $this->assertTrue($machine->is_active);

        // Yeni makine kayıt formunda hemen seçilebilir (R-02, K-18).
        $this->actingAs($this->operator())->get(route('cleanings.create'))
            ->assertOk()
            ->assertSee('M07 — Dolum Makinesi');
    }

    public function test_machine_validation(): void
    {
        $existing = $this->makeMachine(code: 'M01');
        $draft = $this->draftProcedure();
        $otherLine = Line::create(['facility_id' => $existing->line->facility_id, 'code' => 'H02', 'name' => 'Hat 2']);

        $this->actingAs($this->manager)->from(route('admin.machines.create'))
            ->post(route('admin.machines.store'), [])
            ->assertRedirect(route('admin.machines.create'))
            ->assertSessionHasErrors([
                'line_id' => 'hat zorunludur.',
                'code' => 'kod zorunludur.',
                'name' => 'ad zorunludur.',
                'procedure_id' => 'Kullanımdaki makinenin bir prosedürü olmalı (K-18).',
            ]);

        $valid = ['line_id' => $existing->line_id, 'code' => 'M02', 'name' => 'Yeni', 'procedure_id' => $existing->procedure_id];

        $this->actingAs($this->manager)->post(route('admin.machines.store'), ['code' => 'M01'] + $valid)
            ->assertSessionHasErrors(['code' => 'Bu hatta aynı kodlu bir makine zaten var.']);

        $this->actingAs($this->manager)->post(route('admin.machines.store'), ['procedure_id' => $draft->id] + $valid)
            ->assertSessionHasErrors(['procedure_id' => 'Seçilen prosedürün yayımlanmış bir versiyonu yok; önce prosedürü yayımlayın (K-18).']);

        $this->actingAs($this->manager)->post(route('admin.machines.store'), ['procedure_id' => 999, 'line_id' => 999] + $valid)
            ->assertSessionHasErrors(['procedure_id' => 'Seçilen prosedür geçersiz.', 'line_id' => 'Seçilen hat geçersiz.']);

        $this->actingAs($this->manager)->post(route('admin.machines.store'), ['code' => 'M 2'] + $valid)
            ->assertSessionHasErrors(['code' => 'Kod yalnızca büyük harf (A–Z) ve rakamlardan oluşmalıdır; boşluk ve tire kullanılamaz.']);

        $this->assertSame(1, Machine::count());

        // Aynı kod başka hatta kullanılabilir.
        $this->actingAs($this->manager)->post(route('admin.machines.store'), ['code' => 'M01', 'line_id' => $otherLine->id] + $valid)
            ->assertSessionHasNoErrors();
        $this->assertSame(2, Machine::count());
    }

    public function test_manager_edits_a_machine_without_records(): void
    {
        $machine = $this->makeMachine(code: 'M01');
        $this->makeMachine(code: 'M02');
        $otherLine = Line::create(['facility_id' => $machine->line->facility_id, 'code' => 'H02', 'name' => 'Hat 2']);
        $procedure = $this->publishedProcedure('PRC-B');

        $this->actingAs($this->manager)->get(route('admin.machines.edit', $machine))
            ->assertOk()
            ->assertSee('M01 makinesini düzenle')
            ->assertDontSee('id="code-locked"', false);

        $this->actingAs($this->manager)
            ->put(route('admin.machines.update', $machine), ['line_id' => $machine->line_id, 'code' => 'M02', 'name' => 'X', 'procedure_id' => $procedure->id])
            ->assertSessionHasErrors(['code' => 'Bu hatta aynı kodlu bir makine zaten var.']);

        $this->actingAs($this->manager)
            ->put(route('admin.machines.update', $machine), ['line_id' => $machine->line_id, 'code' => 'M01', 'name' => 'X', 'procedure_id' => ''])
            ->assertSessionHasErrors(['procedure_id' => 'Kullanımdaki makinenin bir prosedürü olmalı (K-18).']);

        // Kaydı olmayan makinenin kodu ve hattı değişebilir.
        $this->actingAs($this->manager)
            ->put(route('admin.machines.update', $machine), ['line_id' => $otherLine->id, 'code' => 'M02', 'name' => 'Paketleme', 'procedure_id' => $procedure->id])
            ->assertRedirect(route('admin.machines.show', $machine))
            ->assertSessionHas('status', 'M02 makinesi güncellendi.');

        $machine->refresh();
        $this->assertSame($otherLine->id, $machine->line_id);
        $this->assertSame('M02', $machine->code);
        $this->assertSame('Paketleme', $machine->name);
        $this->assertSame($procedure->id, $machine->procedure_id);
    }

    public function test_code_and_line_are_locked_once_a_record_exists(): void
    {
        $machine = $this->makeMachine(code: 'M01');
        $otherLine = Line::create(['facility_id' => $machine->line->facility_id, 'code' => 'H02', 'name' => 'Hat 2']);
        $procedure = $this->publishedProcedure('PRC-B');
        $this->openCleaning($this->operator(), $machine);
        $reason = 'Bu makinede temizlik kaydı açılmış. Makine kodu ve hattı kayıt numaralarında kullanıldığı için değiştirilemez (K-17).';

        $page = $this->page($this->actingAs($this->manager)->get(route('admin.machines.edit', $machine))
            ->assertOk()
            ->assertSee($reason));
        $this->assertTrue($page->querySelector('#code')->hasAttribute('readonly'));
        $this->assertTrue($page->querySelector('#line_id')->hasAttribute('readonly'));
        $this->assertFalse($page->querySelector('#line_id')->hasAttribute('name'));

        $this->actingAs($this->manager)
            ->put(route('admin.machines.update', $machine), ['line_id' => $otherLine->id, 'code' => 'M09', 'name' => 'X', 'procedure_id' => $procedure->id])
            ->assertSessionHasErrors(['line_id' => $reason, 'code' => $reason]);
        $machine->refresh();
        $this->assertSame('M01', $machine->code);
        $this->assertNotSame($otherLine->id, $machine->line_id);

        // Formun gönderdiği hali (kod aynı, hat yok): ad ve prosedür değişir. Prosedür değişimi açık kaydı etkilemez (K-15).
        $this->actingAs($this->manager)
            ->put(route('admin.machines.update', $machine), ['code' => 'M01', 'name' => 'Yeni ad', 'procedure_id' => $procedure->id])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.machines.show', $machine));

        $machine->refresh();
        $this->assertSame('M01', $machine->code);
        $this->assertNotSame($otherLine->id, $machine->line_id);
        $this->assertSame('Yeni ad', $machine->name);
        $this->assertSame($procedure->id, $machine->procedure_id);
    }

    public function test_show_page_lists_procedure_open_records_and_history_link(): void
    {
        $machine = $this->makeMachine(code: 'M01');
        $owner = $this->operator('Ayşe');
        $done = $this->openCleaning($owner, $machine);
        $this->completeRemainingSteps($owner, $done);
        $created = $this->openCleaning($owner, $machine);
        $running = $this->openCleaning($owner, $machine);
        $this->workflow()->startStep($owner, $this->stepOf($running, 1));

        $this->actingAs($this->manager)->get(route('admin.machines.show', $machine))
            ->assertOk()
            ->assertSee('<title>M01 makinesi', false)
            ->assertSee('İstanbul Tesisi / Hat 1')
            ->assertSee('PRC-M01')
            ->assertSee('Yeni kayıtlar v1 versiyonuna bağlanır')
            ->assertSee('Açılabilir.')
            ->assertSee('3 kayıt')
            ->assertSee(route('cleanings.index', ['machine_id' => $machine->id]), false)
            ->assertSeeInOrder(['Açık kayıtlar', $created->record_no, 'Başlamadı', $running->record_no, 'Devam ediyor', 'Kullanım durumu'])
            ->assertDontSee($done->record_no)
            ->assertSee('Bu makinede 2 açık kayıt var');
    }

    public function test_show_page_explains_why_a_machine_cannot_open_records(): void
    {
        $machine = $this->makeMachine(code: 'M01');
        $bare = Machine::create(['line_id' => $machine->line_id, 'code' => 'M02', 'name' => 'Prosedürsüz']);

        $this->actingAs($this->manager)->get(route('admin.machines.show', $bare))
            ->assertOk()
            ->assertSee('Prosedür atanmamış')
            ->assertSee('Açılamaz: makinenin yayımlanmış versiyonu olan bir prosedürü yok (K-18).')
            ->assertSee('Bu makinede başlamamış ya da devam eden kayıt yok.');

        $this->actingAs($this->manager)->get(route('admin.machines.show', 999))->assertNotFound();
    }

    private function page(TestResponse $response): HTMLDocument
    {
        return HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR);
    }

    private function text(Element $element): string
    {
        return trim(preg_replace('/\s+/u', ' ', $element->textContent));
    }

    /**
     * Liste tablosunun satırları; son (işlem) sütunu hariç.
     *
     * @return list<list<string>>
     */
    private function rows(TestResponse $response): array
    {
        $rows = [];

        foreach ($this->page($response)->querySelectorAll('.machine-list__table tbody tr') as $row) {
            $cells = array_map(fn ($cell) => $this->text($cell), iterator_to_array($row->querySelectorAll('td')));
            $rows[] = array_slice($cells, 0, -1);
        }

        return $rows;
    }
}
