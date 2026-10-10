<?php

namespace Tests\Feature\Admin\Catalog;

use App\Models\Cleaning;
use App\Models\CleaningMaterial;
use App\Models\DefinitionChange;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\User;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\TestCase;

/**
 * Malzeme kataloğu ve lotları (K-13, K-14, R-07, R-10): ekleme, düzenleme, kullanımdan kaldırma.
 * Kaldırılan malzemenin ya da lotun girişi yeni kayıtta ve malzeme eklerken sunulmaz, gönderilirse
 * reddedilir; geçmiş kayıtlarda görünmeye devam eder. Kayıtta kullanılan lotun numarası değişmez,
 * SKT düzeltilebilir; kayıt seçildiği andaki kopyayı taşır.
 */
class MaterialManagementTest extends TestCase
{
    use BuildsCleaningFixtures, InteractsWithCleaningWorkflow, RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('08:00:00');
        $this->manager = $this->manager('Zeynep');
    }

    public function test_list_shows_code_name_state_and_usage_count(): void
    {
        $machine = $this->makeMachine();
        $detergent = $this->makeMaterial('DET-01');
        $disinfectant = $this->makeMaterial('DEZ-02');
        $retired = $this->makeMaterial('ALK-03');
        $retired->update(['is_active' => false]);

        // Aynı kayda iki kez girilen malzeme bir kayıt sayılır.
        $this->openCleaning($this->operator('Ahmet'), $machine, materials: [$this->entry($detergent), $this->entry($detergent, 'LOT-2')]);
        $this->openCleaning($this->operator('Mehmet'), $machine, materials: [$this->entry($detergent)]);

        $page = $this->page($this->actingAs($this->manager)->get(route('admin.materials.index'))
            ->assertOk()
            ->assertSee('<title>Malzemeler', false));

        $rows = array_map(
            fn (Element $row) => array_map(fn (Element $cell) => $this->text($cell), array_slice(iterator_to_array($row->querySelectorAll('td')), 0, 4)),
            iterator_to_array($page->querySelectorAll('tbody tr')),
        );

        // Kullanımdakiler önce, sonra koda göre.
        $this->assertSame([
            ['DET-01', 'Malzeme DET-01', 'Kullanımda', '2'],
            ['DEZ-02', 'Malzeme DEZ-02', 'Kullanımda', '0'],
            ['ALK-03', 'Malzeme ALK-03', 'Kullanımdan kaldırıldı', '0'],
        ], $rows);

        $this->assertSame(route('admin.materials.edit', $detergent), $page->querySelector("#material-{$detergent->id} a")->getAttribute('href'));
        $this->assertNotNull($page->querySelector("#material-{$disinfectant->id} form[action=\"".route('admin.materials.deactivate', $disinfectant).'"]'));
        $this->assertNotNull($page->querySelector("#material-{$retired->id} form[action=\"".route('admin.materials.activate', $retired).'"]'));
    }

    public function test_manager_creates_a_material(): void
    {
        $this->actingAs($this->manager)->get(route('admin.materials.create'))
            ->assertOk()
            ->assertSee('action="'.route('admin.materials.store').'"', false);

        $this->actingAs($this->manager)->post(route('admin.materials.store'), ['code' => ' DEZ-09 ', 'name' => 'Perasetik asit %0,2'])
            ->assertRedirect(route('admin.materials.index'))
            ->assertSessionHas('status', 'Malzeme eklendi: DEZ-09')
            ->assertSessionHasNoErrors();

        $material = Material::query()->sole();
        $this->assertSame(['DEZ-09', 'Perasetik asit %0,2', true], [$material->code, $material->name, $material->is_active]);
    }

    public function test_validation_messages_are_turkish(): void
    {
        $this->makeMaterial('DET-01');

        $this->actingAs($this->manager)->from(route('admin.materials.create'))->post(route('admin.materials.store'), [])
            ->assertRedirect(route('admin.materials.create'))
            ->assertSessionHasErrors([
                'code' => 'malzeme kodu zorunludur.',
                'name' => 'malzeme adı zorunludur.',
            ]);

        $this->actingAs($this->manager)->from(route('admin.materials.create'))->post(route('admin.materials.store'), [
            'code' => 'DET-01',
            'name' => str_repeat('a', 256),
        ])->assertSessionHasErrors([
            'code' => 'malzeme kodu zaten kullanılıyor.',
            'name' => 'malzeme adı en fazla 255 karakter olabilir.',
        ]);

        $this->assertSame(1, Material::count());

        // Hata alanın altında gösterilir, girilen değer korunur.
        $page = $this->page($this->actingAs($this->manager)->from(route('admin.materials.create'))->followingRedirects()
            ->post(route('admin.materials.store'), ['code' => 'DET-01', 'name' => 'Deterjan'])
            ->assertOk());
        $this->assertSame('malzeme kodu zaten kullanılıyor.', $this->text($page->getElementById('code-error')));
        $this->assertSame('Deterjan', $page->getElementById('name')->getAttribute('value'));
    }

    public function test_manager_edits_a_material_and_its_own_code_is_not_a_duplicate(): void
    {
        $material = $this->makeMaterial('DET-01');
        $this->makeMaterial('DEZ-02');

        $this->actingAs($this->manager)->get(route('admin.materials.edit', $material))
            ->assertOk()
            ->assertSee('Henüz hiçbir kayıtta kullanılmadı.');

        $this->actingAs($this->manager)->put(route('admin.materials.update', $material), ['code' => 'DET-01', 'name' => 'Alkali deterjan'])
            ->assertRedirect(route('admin.materials.index'))
            ->assertSessionHasNoErrors();
        $this->assertSame('Alkali deterjan', $material->fresh()->name);

        // Kullanılmamış malzemenin kodu düzeltilebilir, ama başka malzemenin kodu alınamaz.
        $this->actingAs($this->manager)->put(route('admin.materials.update', $material), ['code' => 'DET-11', 'name' => 'Alkali deterjan'])
            ->assertSessionHasNoErrors();
        $this->assertSame('DET-11', $material->fresh()->code);

        $this->actingAs($this->manager)->from(route('admin.materials.edit', $material))
            ->put(route('admin.materials.update', $material), ['code' => 'DEZ-02', 'name' => 'Alkali deterjan'])
            ->assertRedirect(route('admin.materials.edit', $material))
            ->assertSessionHasErrors(['code' => 'malzeme kodu zaten kullanılıyor.']);
        $this->assertSame('DET-11', $material->fresh()->code);
    }

    public function test_code_of_a_material_used_in_records_cannot_change_but_its_name_can(): void
    {
        $material = $this->makeMaterial('DET-01');
        $this->openCleaning($this->operator(), $this->makeMachine(), materials: [$this->entry($material)]);

        $page = $this->page($this->actingAs($this->manager)->get(route('admin.materials.edit', $material))->assertOk());
        $this->assertTrue($page->getElementById('code')->hasAttribute('readonly'));
        $this->assertStringContainsString('1 temizlik kaydında kullanıldı.', $this->text($page->querySelector('.material-status')));

        $this->actingAs($this->manager)->from(route('admin.materials.edit', $material))
            ->put(route('admin.materials.update', $material), ['code' => 'DET-99', 'name' => 'Deterjan'])
            ->assertRedirect(route('admin.materials.edit', $material))
            ->assertSessionHasErrors(['code' => 'Bu malzeme kayıtlarda kullanıldığı için kodu değiştirilemez.']);
        $this->assertSame(['DET-01', 'Malzeme DET-01'], [$material->fresh()->code, $material->fresh()->name]);

        $this->actingAs($this->manager)->put(route('admin.materials.update', $material), ['code' => 'DET-01', 'name' => 'Deterjan'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Deterjan', $material->fresh()->name);
    }

    public function test_material_is_deactivated_and_activated_but_never_deleted(): void
    {
        $material = $this->makeMaterial('DET-01');

        $this->actingAs($this->manager)->post(route('admin.materials.deactivate', $material))
            ->assertRedirect(route('admin.materials.index'))
            ->assertSessionHas('status', 'DET-01 kullanımdan kaldırıldı. Yeni girişlerde seçilemez; geçmiş kayıtlarda görünmeye devam eder.');
        $this->assertFalse($material->fresh()->is_active);

        $page = $this->page($this->actingAs($this->manager)->get(route('admin.materials.edit', $material))->assertOk());
        $this->assertStringContainsString('Kullanımdan kaldırıldı', $this->text($page->querySelector('.material-status')));
        $this->assertNotNull($page->querySelector('form[action="'.route('admin.materials.activate', $material).'"]'));

        $this->actingAs($this->manager)->post(route('admin.materials.activate', $material))
            ->assertRedirect(route('admin.materials.index'))
            ->assertSessionHas('status', 'DET-01 yeniden kullanımda.');
        $this->assertTrue($material->fresh()->is_active);

        $this->actingAs($this->manager)->delete('/admin/materials/'.$material->id)->assertMethodNotAllowed();
        $this->assertSame(1, Material::count());
    }

    public function test_only_usable_lots_are_offered_in_the_new_record_form(): void
    {
        // K-13, K-14: kullanımdan kaldırılan malzemenin, kullanımdan kaldırılan ve SKT'si geçen lot sunulmaz.
        $this->makeMachine(code: 'M01');
        $detergent = $this->makeMaterial('DET-01');
        $retired = $this->makeMaterial('DEZ-02');
        $usable = $this->lot($detergent, 'DT-1', '2027-01-31');
        $this->lot($detergent, 'DT-0', '2026-10-08');
        $this->lot($detergent, 'DT-X', '2027-06-30')->update(['is_active' => false]);
        $this->lot($retired, 'DZ-1');
        $retired->update(['is_active' => false]);

        $lots = $this->actingAs($this->operator())->get(route('cleanings.create'))->assertOk()->viewData('lotsByMaterial');

        $this->assertSame([$detergent->id], $lots->keys()->all());
        $this->assertSame([$usable->id], $lots->collapse()->pluck('id')->all());
    }

    public function test_lot_of_an_inactive_material_is_rejected_when_opening_a_record(): void
    {
        $machine = $this->makeMachine(code: 'M01');
        $retired = $this->makeMaterial('DEZ-02');
        $lot = $this->lot($retired, 'DZ-1');
        $retired->update(['is_active' => false]);

        $this->actingAs($this->operator())->from(route('cleanings.create'))->post(route('cleanings.store'), [
            'machine_id' => $machine->id,
            'type' => 'planned',
            'materials' => [['material_id' => $retired->id, 'material_lot_id' => $lot->id]],
        ])
            ->assertRedirect(route('cleanings.create'))
            ->assertSessionHasErrors(['workflow' => 'DZ-1 lotu kullanımda değil.']);

        $this->assertSame(0, Cleaning::count());
    }

    public function test_inactive_material_is_not_accepted_on_the_record_but_existing_entries_stay(): void
    {
        $machine = $this->makeMachine(code: 'M01');
        $detergent = $this->makeMaterial('DET-01');
        $disinfectant = $this->makeMaterial('DEZ-02');
        $ahmet = $this->operator();
        $cleaning = $this->openCleaning($ahmet, $machine, materials: [$this->entry($disinfectant, 'LOT-DZ')]);

        $disinfectant->update(['is_active' => false]);

        $page = $this->page($this->actingAs($ahmet)->get(route('cleanings.show', $cleaning))->assertOk());

        // Kayıtta zaten olan giriş görünmeye devam eder (R-10).
        $this->assertStringContainsString('DEZ-02', $this->text($page->querySelector('#materials')));
        $this->assertStringContainsString('LOT-DZ', $this->text($page->querySelector('#materials')));

        // Kullanımdan kaldırılan malzemenin lotu gönderilse de reddedilir.
        $this->actingAs($ahmet)->from(route('cleanings.show', $cleaning))
            ->post(route('cleanings.materials.store', $cleaning), ['material_lot_id' => $this->lot($disinfectant, 'LOT-2')->id])
            ->assertSessionHasErrors(['workflow' => 'LOT-2 lotu kullanımda değil.']);

        $this->assertSame(1, CleaningMaterial::query()->where('cleaning_id', $cleaning->id)->count());

        $this->actingAs($ahmet)->post(route('cleanings.materials.store', $cleaning), ['material_lot_id' => $this->lot($detergent, 'LOT-3')->id])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, CleaningMaterial::query()->where('cleaning_id', $cleaning->id)->count());
    }

    // ---------------------------------------------------------------------------------------
    // Lotlar (K-14)
    // ---------------------------------------------------------------------------------------

    public function test_manager_adds_lots_and_the_material_page_lists_them(): void
    {
        $material = $this->makeMaterial('DET-01');
        $edit = route('admin.materials.edit', $material);

        $this->actingAs($this->manager)->post(route('admin.materials.lots.store', $material), [
            'lot_no' => ' DT-24118 ',
            'expiry_date' => '2027-05-31',
            'received_at' => '2026-09-01',
        ])
            ->assertRedirect($edit.'#material-lots')
            ->assertSessionHas('status', 'DT-24118 lotu eklendi.')
            ->assertSessionHasNoErrors();

        $lot = MaterialLot::query()->sole();
        $this->assertSame(
            [$material->id, 'DT-24118', '2027-05-31', '2026-09-01', true],
            [$lot->material_id, $lot->lot_no, $lot->expiry_date->toDateString(), $lot->received_at->toDateString(), $lot->is_active],
        );

        $expired = $this->lot($material, 'DT-23090', '2026-09-30');
        $recalled = $this->lot($material, 'DT-24500', '2027-08-31');
        $recalled->update(['is_active' => false]);
        $this->openCleaning($this->operator(), $this->makeMachine(), materials: [$this->entry($material, 'DT-24118')]);

        $page = $this->page($this->actingAs($this->manager)->get($edit)->assertOk());

        $rows = array_map(
            fn (Element $row) => array_map(fn (Element $cell) => $this->text($cell), array_slice(iterator_to_array($row->querySelectorAll('td')), 0, 5)),
            iterator_to_array($page->querySelectorAll('#material-lots tbody tr')),
        );

        // SKT sırasıyla; geçmiş ve kullanımdan kaldırılmış lot işaretli.
        $this->assertSame([
            ['DT-23090', '30 Eylül', '—', 'SKT geçti', '0'],
            ['DT-24118', '31 Mayıs 2027', '1 Eylül', 'Kullanımda', '1'],
            ['DT-24500', '31 Ağustos 2027', '—', 'Kullanımdan kaldırıldı', '0'],
        ], $rows);
        $this->assertNotNull($page->querySelector("#material-lot-{$expired->id} a[href=\"".route('admin.materials.lots.edit', [$material, $expired]).'"]'));
        $this->assertNotNull($page->querySelector("#material-lot-{$recalled->id} form[action=\"".route('admin.materials.lots.activate', [$material, $recalled]).'"]'));
        $this->assertNotNull($page->querySelector('#material-lots form[action="'.route('admin.materials.lots.store', $material).'"]'));
    }

    public function test_lot_validation_messages_are_turkish_and_lot_numbers_are_unique_per_material(): void
    {
        $detergent = $this->makeMaterial('DET-01');
        $disinfectant = $this->makeMaterial('DEZ-02');
        $this->lot($detergent, 'LOT-1');
        $edit = route('admin.materials.edit', $detergent);

        $this->actingAs($this->manager)->from($edit)->post(route('admin.materials.lots.store', $detergent), [])
            ->assertRedirect($edit)
            ->assertSessionHasErrors([
                'lot_no' => 'lot numarası zorunludur.',
                'expiry_date' => 'son kullanma tarihi zorunludur.',
            ]);

        $this->actingAs($this->manager)->from($edit)->post(route('admin.materials.lots.store', $detergent), [
            'lot_no' => 'LOT-1',
            'expiry_date' => '31.05.2027',
            'received_at' => 'dün',
        ])->assertSessionHasErrors([
            'lot_no' => 'lot numarası zaten kullanılıyor.',
            'expiry_date' => 'son kullanma tarihi Y-m-d biçiminde olmalıdır.',
            'received_at' => 'giriş tarihi Y-m-d biçiminde olmalıdır.',
        ]);

        // Başka malzemede aynı lot numarası olabilir.
        $this->actingAs($this->manager)->post(route('admin.materials.lots.store', $disinfectant), ['lot_no' => 'LOT-1', 'expiry_date' => '2027-05-31'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, MaterialLot::count());
    }

    public function test_used_lot_number_is_locked_but_its_expiry_can_be_corrected_without_changing_records(): void
    {
        $material = $this->makeMaterial('DET-01');
        $lot = $this->lot($material, 'DT-24118', '2027-05-31');
        $cleaning = $this->openCleaning($this->operator(), $this->makeMachine(), materials: [$this->entry($material, 'DT-24118')]);
        $form = route('admin.materials.lots.edit', [$material, $lot]);

        $page = $this->page($this->actingAs($this->manager)->get($form)->assertOk());
        $this->assertTrue($page->getElementById('lot_no')->hasAttribute('readonly'));
        $this->assertStringContainsString('1 temizlik kaydında kullanıldı.', $this->text($page->querySelector('.material-lot-status')));

        $this->actingAs($this->manager)->from($form)
            ->put(route('admin.materials.lots.update', [$material, $lot]), ['lot_no' => 'DT-24181', 'expiry_date' => '2027-05-31'])
            ->assertRedirect($form)
            ->assertSessionHasErrors(['lot_no' => 'Bu lot kayıtlarda kullanıldığı için numarası değiştirilemez; son kullanma tarihi düzeltilebilir.']);

        $this->actingAs($this->manager)
            ->put(route('admin.materials.lots.update', [$material, $lot]), ['lot_no' => 'DT-24118', 'expiry_date' => '2027-06-30'])
            ->assertRedirect(route('admin.materials.edit', $material).'#material-lots')
            ->assertSessionHas('status', 'DT-24118 lotu güncellendi.');

        $this->assertSame('2027-06-30', $lot->fresh()->expiry_date->toDateString());
        // Kayıt, lotun seçildiği andaki kopyasını taşır (R-13).
        $this->assertSame('2027-05-31', $cleaning->materials()->sole()->expiry_date->toDateString());
    }

    public function test_unused_lot_number_can_be_corrected(): void
    {
        $material = $this->makeMaterial('DET-01');
        $lot = $this->lot($material, 'DT-24181');

        $this->actingAs($this->manager)
            ->put(route('admin.materials.lots.update', [$material, $lot]), ['lot_no' => 'DT-24118', 'expiry_date' => '2027-05-31', 'received_at' => ''])
            ->assertSessionHasNoErrors();

        $this->assertSame(['DT-24118', '2027-05-31', null], [$lot->fresh()->lot_no, $lot->fresh()->expiry_date->toDateString(), $lot->fresh()->received_at]);
    }

    public function test_lot_is_deactivated_and_activated_and_cannot_be_used_while_inactive(): void
    {
        $material = $this->makeMaterial('DET-01');
        $lot = $this->lot($material, 'DT-24118');
        $back = route('admin.materials.edit', $material).'#material-lots';

        $this->actingAs($this->manager)->post(route('admin.materials.lots.deactivate', [$material, $lot]))
            ->assertRedirect($back)
            ->assertSessionHas('status', 'DT-24118 lotu kullanımdan kaldırıldı. Yeni girişlerde seçilemez; girildiği kayıtlarda görünmeye devam eder.');
        $this->assertFalse($lot->fresh()->is_active);

        $this->assertRuleViolation('material_lot_unavailable', fn () => $this->openCleaning($this->operator(), $this->makeMachine(), materials: [$this->entry($material, 'DT-24118')]));

        $this->actingAs($this->manager)->post(route('admin.materials.lots.activate', [$material, $lot]))
            ->assertRedirect($back)
            ->assertSessionHas('status', 'DT-24118 lotu yeniden kullanımda.');
        $this->assertTrue($lot->fresh()->is_active);

        $this->actingAs($this->manager)->delete('/admin/materials/'.$material->id.'/lots/'.$lot->id)->assertMethodNotAllowed();
        $this->assertSame(1, MaterialLot::count());
    }

    public function test_lot_routes_are_scoped_to_the_material_and_closed_to_operators(): void
    {
        $detergent = $this->makeMaterial('DET-01');
        $disinfectant = $this->makeMaterial('DEZ-02');
        $lot = $this->lot($detergent, 'DT-1');

        $this->actingAs($this->manager)->get(route('admin.materials.lots.edit', [$disinfectant, $lot]))->assertNotFound();
        $this->actingAs($this->manager)->post(route('admin.materials.lots.deactivate', [$disinfectant, $lot]))->assertNotFound();

        $this->actingAs($this->operator());
        $requests = [
            ['post', route('admin.materials.lots.store', $detergent), ['lot_no' => 'X', 'expiry_date' => '2027-01-31']],
            ['get', route('admin.materials.lots.edit', [$detergent, $lot])],
            ['put', route('admin.materials.lots.update', [$detergent, $lot]), ['lot_no' => 'X', 'expiry_date' => '2027-01-31']],
            ['post', route('admin.materials.lots.deactivate', [$detergent, $lot])],
            ['post', route('admin.materials.lots.activate', [$detergent, $lot])],
        ];

        foreach ($requests as $request) {
            [$method, $url, $data] = $request + [2 => []];
            $this->{$method}($url, $data)->assertForbidden();
        }

        $this->assertSame([['DT-1', true]], MaterialLot::all()->map(fn ($lot) => [$lot->lot_no, $lot->is_active])->all());
    }

    public function test_list_shows_lot_counts_and_lot_changes_are_in_the_material_history(): void
    {
        $material = $this->makeMaterial('DET-01');
        $this->makeMaterial('DEZ-02');
        $this->lot($material, 'DT-1', '2027-01-31');
        $this->lot($material, 'DT-0', '2026-10-08');

        $page = $this->page($this->actingAs($this->manager)->get(route('admin.materials.index'))->assertOk());

        $this->assertSame('2 lot (1 seçilebilir)', $this->text($page->querySelector("#material-{$material->id} .material-list__lots")));

        $this->actingAs($this->manager)->post(route('admin.materials.lots.store', $material), ['lot_no' => 'DT-2', 'expiry_date' => '2027-03-31']);
        $lot = MaterialLot::where('lot_no', 'DT-2')->sole();

        $change = DefinitionChange::query()->where('subject_type', DefinitionChange::typeOf($lot))->where('subject_id', $lot->id)->sole();
        $this->assertSame([DefinitionChange::typeOf($material), $material->id, 'DET-01 / DT-2'], [$change->root_type, $change->root_id, $change->subject_label]);
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
