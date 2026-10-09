<?php

namespace Tests\Feature\Admin\Locations;

use App\Models\Facility;
use App\Models\Line;
use App\Models\Machine;

/**
 * Tesis ve hat tanımları (R-43). Kodlar kayıt numarasının parçasıdır (K-17): biçimi denetlenir
 * ve tanım için kayıt açıldıktan sonra değiştirilemez.
 */
class FacilityAndLineTest extends LocationsTestCase
{
    public function test_index_lists_facilities_with_lines_and_machine_counts(): void
    {
        $machine = $this->makeMachine(code: 'M01');
        $this->makeMachine(code: 'M02')->update(['is_active' => false]);
        $empty = Line::create(['facility_id' => $machine->line->facility_id, 'code' => 'H02', 'name' => 'Paketleme Hattı']);
        Facility::create(['code' => 'ANK', 'name' => 'Ankara Tesisi']);

        $this->actingAs($this->manager)->get(route('admin.facilities.index'))
            ->assertOk()
            ->assertSee('<title>Tesis ve Hatlar', false)
            ->assertSeeInOrder(['Yeni tesis', 'ANK', 'Ankara Tesisi', 'Bu tesiste henüz hat yok.', 'IST', 'İstanbul Tesisi', 'H01', 'Hat 1', '2 makine', '(1 kullanımda)', 'H02', 'Paketleme Hattı', '0 makine'])
            ->assertSee(route('admin.facilities.create'), false)
            ->assertSee(route('admin.lines.create', $machine->line->facility), false)
            ->assertSee(route('admin.lines.edit', $empty), false)
            ->assertSee(route('admin.machines.index', ['line_id' => $machine->line_id]), false);
    }

    public function test_index_shows_empty_state(): void
    {
        $this->actingAs($this->manager)->get(route('admin.facilities.index'))
            ->assertOk()
            ->assertSee('Henüz tesis tanımlanmamış.');
    }

    public function test_manager_creates_a_facility_with_normalised_code(): void
    {
        $this->actingAs($this->manager)->get(route('admin.facilities.create'))
            ->assertOk()
            ->assertSee('<title>Yeni tesis', false);

        $this->actingAs($this->manager)
            ->post(route('admin.facilities.store'), ['code' => ' ank ', 'name' => ' Ankara Tesisi '])
            ->assertRedirect(route('admin.facilities.index'))
            ->assertSessionHas('status', 'ANK tesisi eklendi. Şimdi hatlarını ekleyebilirsiniz.');

        $facility = Facility::sole();
        $this->assertSame('ANK', $facility->code);
        $this->assertSame('Ankara Tesisi', $facility->name);
    }

    public function test_facility_validation_messages_are_turkish(): void
    {
        Facility::create(['code' => 'IST', 'name' => 'İstanbul Tesisi']);

        $this->actingAs($this->manager)->from(route('admin.facilities.create'))
            ->post(route('admin.facilities.store'), ['code' => '', 'name' => ''])
            ->assertRedirect(route('admin.facilities.create'))
            ->assertSessionHasErrors(['code' => 'kod zorunludur.', 'name' => 'ad zorunludur.']);

        $this->actingAs($this->manager)
            ->post(route('admin.facilities.store'), ['code' => 'ist', 'name' => 'İkinci'])
            ->assertSessionHasErrors(['code' => 'kod zaten kullanılıyor.']);

        $this->actingAs($this->manager)
            ->post(route('admin.facilities.store'), ['code' => 'IS-T', 'name' => 'Tireli'])
            ->assertSessionHasErrors(['code' => 'Kod yalnızca büyük harf (A–Z) ve rakamlardan oluşmalıdır; boşluk ve tire kullanılamaz.']);

        $this->actingAs($this->manager)
            ->post(route('admin.facilities.store'), ['code' => 'ABCDEFGHIJK', 'name' => 'Uzun'])
            ->assertSessionHasErrors(['code' => 'kod en fazla 10 karakter olabilir.']);

        $this->assertSame(1, Facility::count());
    }

    public function test_manager_edits_a_facility_without_records(): void
    {
        $facility = Facility::create(['code' => 'IST', 'name' => 'İstanbul']);

        $this->actingAs($this->manager)->get(route('admin.facilities.edit', $facility))
            ->assertOk()
            ->assertSee('IST tesisini düzenle')
            ->assertDontSee('id="code-locked"', false);

        $this->actingAs($this->manager)
            ->put(route('admin.facilities.update', $facility), ['code' => 'IZM', 'name' => 'İzmir Tesisi'])
            ->assertRedirect(route('admin.facilities.index'))
            ->assertSessionHas('status', 'IZM tesisi güncellendi.');

        $facility->refresh();
        $this->assertSame('IZM', $facility->code);
        $this->assertSame('İzmir Tesisi', $facility->name);

        // Kendi kodunu korurken benzersizlik kuralına takılmaz.
        $this->actingAs($this->manager)
            ->put(route('admin.facilities.update', $facility), ['code' => 'IZM', 'name' => 'İzmir'])
            ->assertSessionHasNoErrors();
    }

    public function test_facility_code_is_locked_once_a_record_exists(): void
    {
        $machine = $this->makeMachine();
        $facility = $machine->line->facility;
        $this->openCleaning($this->operator(), $machine);

        $this->actingAs($this->manager)->get(route('admin.facilities.edit', $facility))
            ->assertOk()
            ->assertSee('Bu tesiste temizlik kaydı açılmış. Tesis kodu kayıt numaralarında ve saha defteri referanslarında kullanıldığı için değiştirilemez (K-17).')
            ->assertSee('id="code-locked"', false);

        $this->actingAs($this->manager)->from(route('admin.facilities.edit', $facility))
            ->put(route('admin.facilities.update', $facility), ['code' => 'ANK', 'name' => 'Yeni ad'])
            ->assertRedirect(route('admin.facilities.edit', $facility))
            ->assertSessionHasErrors(['code' => 'Bu tesiste temizlik kaydı açılmış. Tesis kodu kayıt numaralarında ve saha defteri referanslarında kullanıldığı için değiştirilemez (K-17).']);
        $this->assertSame('IST', $facility->fresh()->code);

        // Ad değişebilir; salt okunur alan aynı kodu gönderir.
        $this->actingAs($this->manager)
            ->put(route('admin.facilities.update', $facility), ['code' => 'IST', 'name' => 'İstanbul Merkez'])
            ->assertSessionHasNoErrors();
        $this->assertSame('İstanbul Merkez', $facility->fresh()->name);
        $this->assertSame('IST', $facility->fresh()->code);
    }

    public function test_manager_adds_a_line_to_a_facility(): void
    {
        $facility = Facility::create(['code' => 'IST', 'name' => 'İstanbul']);

        $this->actingAs($this->manager)->get(route('admin.lines.create', $facility))
            ->assertOk()
            ->assertSee('IST tesisine hat ekle');

        $this->actingAs($this->manager)
            ->post(route('admin.lines.store', $facility), ['code' => 'h01', 'name' => 'Dolum Hattı'])
            ->assertRedirect(route('admin.facilities.index'))
            ->assertSessionHas('status', 'IST / H01 hattı eklendi.');

        $line = Line::sole();
        $this->assertSame($facility->id, $line->facility_id);
        $this->assertSame('H01', $line->code);
        $this->assertSame('Dolum Hattı', $line->name);
    }

    public function test_line_code_is_unique_per_facility(): void
    {
        $istanbul = Facility::create(['code' => 'IST', 'name' => 'İstanbul']);
        $ankara = Facility::create(['code' => 'ANK', 'name' => 'Ankara']);
        Line::create(['facility_id' => $istanbul->id, 'code' => 'H01', 'name' => 'Hat 1']);

        $this->actingAs($this->manager)
            ->post(route('admin.lines.store', $istanbul), ['code' => 'H01', 'name' => 'Başka'])
            ->assertSessionHasErrors(['code' => 'Bu tesiste aynı kodlu bir hat zaten var.']);

        $this->actingAs($this->manager)
            ->post(route('admin.lines.store', $ankara), ['code' => 'H01', 'name' => 'Ankara Hat 1'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->manager)
            ->post(route('admin.lines.store', $ankara), ['code' => '', 'name' => ''])
            ->assertSessionHasErrors(['code' => 'kod zorunludur.', 'name' => 'ad zorunludur.']);

        $this->assertSame(2, Line::count());
    }

    public function test_manager_edits_a_line_and_its_code_is_locked_once_a_record_exists(): void
    {
        $machine = $this->makeMachine();
        $line = $machine->line;
        $other = Line::create(['facility_id' => $line->facility_id, 'code' => 'H02', 'name' => 'Hat 2']);

        $this->actingAs($this->manager)
            ->put(route('admin.lines.update', $other), ['code' => 'H01', 'name' => 'Hat 2'])
            ->assertSessionHasErrors(['code' => 'Bu tesiste aynı kodlu bir hat zaten var.']);

        $this->actingAs($this->manager)
            ->put(route('admin.lines.update', $other), ['code' => 'H03', 'name' => 'Paketleme'])
            ->assertRedirect(route('admin.facilities.index'))
            ->assertSessionHas('status', 'IST / H03 hattı güncellendi.');
        $this->assertSame('H03', $other->fresh()->code);

        $this->openCleaning($this->operator(), $machine);

        $this->actingAs($this->manager)->get(route('admin.lines.edit', $line))
            ->assertOk()
            ->assertSee('Bu hatta temizlik kaydı açılmış. Hat kodu kayıt numaralarında kullanıldığı için değiştirilemez (K-17).');

        $this->actingAs($this->manager)
            ->put(route('admin.lines.update', $line), ['code' => 'H09', 'name' => 'Hat 1'])
            ->assertSessionHasErrors(['code' => 'Bu hatta temizlik kaydı açılmış. Hat kodu kayıt numaralarında kullanıldığı için değiştirilemez (K-17).']);
        $this->assertSame('H01', $line->fresh()->code);

        $this->actingAs($this->manager)
            ->put(route('admin.lines.update', $line), ['name' => 'Dolum Hattı'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Dolum Hattı', $line->fresh()->name);
        $this->assertSame('H01', $line->fresh()->code);

        // Kaydı olmayan hat (H03) kilitli değildir.
        $this->actingAs($this->manager)->get(route('admin.lines.edit', $other))
            ->assertOk()
            ->assertDontSee('Hat kodu kayıt numaralarında kullanıldığı için değiştirilemez');
        $this->assertSame(1, Machine::count());
    }

    public function test_unknown_facility_or_line_is_404(): void
    {
        $this->actingAs($this->manager)->get(route('admin.facilities.edit', 999))->assertNotFound();
        $this->actingAs($this->manager)->get(route('admin.lines.create', 999))->assertNotFound();
        $this->actingAs($this->manager)->get(route('admin.lines.edit', 999))->assertNotFound();
    }
}
