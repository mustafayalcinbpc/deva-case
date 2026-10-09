<?php

namespace Tests\Feature\Admin\Catalog;

use App\Models\Cleaning;
use App\Models\CleaningMaterial;
use App\Models\Material;
use App\Models\User;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\TestCase;

/**
 * Malzeme kataloğu (K-13, R-07, R-10): ekleme, düzenleme, kullanımdan kaldırma. Kaldırılan malzeme
 * yeni kayıtta ve malzeme ekleme formunda sunulmaz, gönderilirse reddedilir; geçmiş kayıtlarda
 * görünmeye devam eder.
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

    public function test_inactive_material_is_not_offered_in_the_new_record_form(): void
    {
        $this->makeMachine(code: 'M01');
        $this->makeMaterial('DET-01');
        $this->makeMaterial('DEZ-02')->update(['is_active' => false]);

        $page = $this->page($this->actingAs($this->operator())->get(route('cleanings.create'))->assertOk());

        $this->assertSame(
            ['Malzeme seçin', 'DET-01 — Malzeme DET-01'],
            array_map(fn (Element $option) => $this->text($option), iterator_to_array($page->querySelectorAll('select[name="materials[0][material_id]"] option'))),
        );
    }

    public function test_inactive_material_is_rejected_when_opening_a_record(): void
    {
        $machine = $this->makeMachine(code: 'M01');
        $retired = $this->makeMaterial('DEZ-02');
        $retired->update(['is_active' => false]);

        $this->actingAs($this->operator())->from(route('cleanings.create'))->post(route('cleanings.store'), [
            'machine_id' => $machine->id,
            'type' => 'planned',
            'materials' => [['material_id' => $retired->id, 'lot_no' => 'LOT-1', 'expiry_date' => '2027-01-31']],
        ])
            ->assertRedirect(route('cleanings.create'))
            ->assertSessionHasErrors(['materials.0.material_id' => 'Seçilen malzeme geçersiz.']);

        $this->assertSame(0, Cleaning::count());
    }

    public function test_inactive_material_is_not_offered_or_accepted_on_the_record_but_existing_entries_stay(): void
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

        // Ekleme formunda sunulmaz.
        $options = array_map(
            fn (Element $option) => $option->getAttribute('value'),
            iterator_to_array($page->querySelectorAll('select[name="material_id"] option')),
        );
        $this->assertContains((string) $detergent->id, $options);
        $this->assertNotContains((string) $disinfectant->id, $options);

        // Elle gönderilse de reddedilir.
        $this->actingAs($ahmet)->from(route('cleanings.show', $cleaning))
            ->post(route('cleanings.materials.store', $cleaning), [
                'material_id' => $disinfectant->id,
                'lot_no' => 'LOT-2',
                'expiry_date' => '2027-01-31',
            ])
            ->assertSessionHasErrors(['material_id' => 'Seçilen malzeme geçersiz.']);

        $this->assertSame(1, CleaningMaterial::query()->where('cleaning_id', $cleaning->id)->count());

        $this->actingAs($ahmet)->post(route('cleanings.materials.store', $cleaning), [
            'material_id' => $detergent->id,
            'lot_no' => 'LOT-3',
            'expiry_date' => '2027-01-31',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, CleaningMaterial::query()->where('cleaning_id', $cleaning->id)->count());
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
