<?php

namespace Tests\Feature\Admin\Procedures;

use App\Models\DefinitionChange;
use App\Models\ProcedureVersion;
use App\Models\ProcedureVersionMaterial;
use Dom\Element;
use LogicException;

/**
 * Prosedür versiyonunun beklediği malzemeler (K-12, K-13, K-15): taslakta eklenir, zorunlu ya
 * da isteğe bağlı yapılır, sıralanır, çıkarılır; malzeme zorunluluğu listeden türetilir.
 * Yeni taslak listeyi kopyalar; yayımlanmış versiyonun listesi değişmez.
 */
class VersionMaterialsTest extends ProcedureTestCase
{
    public function test_manager_builds_the_expected_material_list_on_a_draft(): void
    {
        $procedure = $this->procedure();
        $draft = $this->draft($procedure);
        $detergent = $this->makeMaterial('DET-01');
        $acid = $this->makeMaterial('DUR-03');
        $editor = route('admin.procedures.versions.show', [$procedure, $draft]);

        $this->post(route('admin.procedures.materials.store', [$procedure, $draft]), ['material_id' => $detergent->id, 'is_required' => '1'])
            ->assertRedirect($editor.'#procedure-materials')
            ->assertSessionHas('status', 'DET-01 listeye eklendi.');
        $this->assertTrue($draft->fresh()->material_required);

        // İşaretsiz kutu gönderilmez: isteğe bağlı.
        $this->post(route('admin.procedures.materials.store', [$procedure, $draft]), ['material_id' => $acid->id]);

        $this->assertSame([['DET-01', 1, true], ['DUR-03', 2, false]], $this->materialsOf($draft));
        $this->assertTrue($draft->fresh()->material_required);
    }

    public function test_requirement_follows_the_list(): void
    {
        // K-13: zorunlu malzeme kalmayınca versiyon malzeme istemez.
        $procedure = $this->procedure();
        $draft = $this->draft($procedure);
        $this->post(route('admin.procedures.materials.store', [$procedure, $draft]), ['material_id' => $this->makeMaterial('DET-01')->id, 'is_required' => '1']);
        $item = $draft->materials()->sole();

        $this->put(route('admin.procedures.materials.update', [$procedure, $draft, $item]), [])
            ->assertSessionHas('status', 'DET-01 artık isteğe bağlı.');
        $this->assertFalse($draft->fresh()->material_required);
        $this->assertSame([['DET-01', 1, false]], $this->materialsOf($draft));

        $this->put(route('admin.procedures.materials.update', [$procedure, $draft, $item]), ['is_required' => '1']);
        $this->assertTrue($draft->fresh()->material_required);

        $this->delete(route('admin.procedures.materials.destroy', [$procedure, $draft, $item]))
            ->assertSessionHas('status', 'DET-01 listeden çıkarıldı.');
        $this->assertSame([], $this->materialsOf($draft));
        $this->assertFalse($draft->fresh()->material_required);
    }

    public function test_materials_are_reordered_and_removal_closes_the_gap(): void
    {
        $procedure = $this->procedure();
        $draft = $this->draft($procedure);
        foreach (['DET-01', 'DEZ-02', 'DUR-03'] as $code) {
            $this->post(route('admin.procedures.materials.store', [$procedure, $draft]), ['material_id' => $this->makeMaterial($code)->id, 'is_required' => '1']);
        }
        [$detergent, $disinfectant] = $draft->materials()->get()->all();

        $this->post(route('admin.procedures.materials.move', [$procedure, $draft, $disinfectant, 'up']))->assertRedirect();
        $this->assertSame(['DEZ-02', 'DET-01', 'DUR-03'], array_column($this->materialsOf($draft), 0));

        $this->delete(route('admin.procedures.materials.destroy', [$procedure, $draft, $detergent]));
        $this->assertSame([['DEZ-02', 1, true], ['DUR-03', 2, true]], $this->materialsOf($draft));
    }

    public function test_duplicate_and_retired_materials_are_rejected(): void
    {
        $procedure = $this->procedure();
        $draft = $this->draft($procedure);
        $detergent = $this->makeMaterial('DET-01');
        $retired = $this->makeMaterial('ALK-04');
        $retired->update(['is_active' => false]);
        $editor = route('admin.procedures.versions.show', [$procedure, $draft]);
        $this->post(route('admin.procedures.materials.store', [$procedure, $draft]), ['material_id' => $detergent->id, 'is_required' => '1']);

        $this->from($editor)->post(route('admin.procedures.materials.store', [$procedure, $draft]), ['material_id' => $detergent->id])
            ->assertRedirect($editor)
            ->assertSessionHasErrors(['material_id' => 'DET-01 bu versiyonun listesinde zaten var.']);
        $this->from($editor)->post(route('admin.procedures.materials.store', [$procedure, $draft]), ['material_id' => $retired->id])
            ->assertSessionHasErrors(['material_id' => 'Seçilen malzeme geçersiz.']);
        $this->from($editor)->post(route('admin.procedures.materials.store', [$procedure, $draft]), [])
            ->assertSessionHasErrors(['material_id' => 'malzeme zorunludur.']);

        $this->assertSame([['DET-01', 1, true]], $this->materialsOf($draft));
    }

    public function test_editor_lists_the_materials_and_offers_only_the_rest(): void
    {
        $procedure = $this->procedure();
        $draft = $this->draft($procedure);
        $detergent = $this->makeMaterial('DET-01');
        $acid = $this->makeMaterial('DUR-03');
        $this->makeMaterial('ALK-04')->update(['is_active' => false]);
        $this->post(route('admin.procedures.materials.store', [$procedure, $draft]), ['material_id' => $detergent->id, 'is_required' => '1']);

        $response = $this->get(route('admin.procedures.versions.show', [$procedure, $draft]))->assertOk();

        $card = $this->one($response, '#procedure-materials');
        $this->assertStringContainsString('DET-01 Malzeme DET-01 Zorunlu', $this->text($card));
        $this->assertSame(
            ['', (string) $acid->id],
            array_map(fn (Element $option) => $option->getAttribute('value'), iterator_to_array($card->querySelectorAll('select[name="material_id"] option'))),
        );
        $this->assertSame('Zorunlu: DET-01', $this->text($this->one($response, '.procedure-settings__materials')));
        // Elle "malzeme zorunlu" kutusu yok; zorunluluk listeden gelir.
        $this->assertNull($this->page($response)->querySelector('input[name="material_required"]'));
    }

    public function test_published_version_shows_the_list_read_only(): void
    {
        $procedure = $this->procedure();
        $v1 = $this->publishVersion($procedure, [['steps' => 1]], materials: [[$this->makeMaterial('DET-01'), true], [$this->makeMaterial('DUR-03'), false]]);

        $response = $this->get(route('admin.procedures.versions.show', [$procedure, $v1]))->assertOk();

        $card = $this->one($response, '#procedure-materials');
        $this->assertStringContainsString('DET-01 Malzeme DET-01 Zorunlu', $this->text($card));
        $this->assertStringContainsString('DUR-03 Malzeme DUR-03 İsteğe bağlı', $this->text($card));
        $this->assertNull($card->querySelector('form'));
        $this->assertSame('Zorunlu: DET-01', $this->text($this->one($response, '.procedure-settings__materials')));
    }

    public function test_new_draft_copies_the_list_and_deleting_the_draft_removes_it(): void
    {
        $procedure = $this->procedure();
        $this->publishVersion($procedure, [['steps' => 1]], materials: [[$this->makeMaterial('DET-01'), true], [$this->makeMaterial('DUR-03'), false]]);

        $this->post(route('admin.procedures.versions.store', $procedure))->assertRedirect();
        $draft = $procedure->versions()->whereNull('published_at')->sole();

        $this->assertSame([['DET-01', 1, true], ['DUR-03', 2, false]], $this->materialsOf($draft));
        $this->assertTrue($draft->material_required);

        $this->delete(route('admin.procedures.versions.destroy', [$procedure, $draft]))->assertRedirect();
        $this->assertSame(1, $procedure->versions()->count());
        $this->assertSame(2, ProcedureVersionMaterial::count());
    }

    public function test_requirement_copied_from_a_version_without_a_list_lasts_until_the_list_changes(): void
    {
        // K-13: liste tanımlanmadan önceki versiyonun genel zorunluluğu kopyalanır; liste ilk
        // değiştiğinde zorunluluk listeden belirlenir.
        $procedure = $this->procedure();
        $this->publishVersion($procedure, [['steps' => 1]], materialRequired: true);
        $this->post(route('admin.procedures.versions.store', $procedure));
        $draft = $procedure->versions()->whereNull('published_at')->sole();
        $this->assertTrue($draft->material_required);

        $response = $this->get(route('admin.procedures.versions.show', [$procedure, $draft]));
        $this->assertStringContainsString('en az bir geçerli malzeme ister', $this->text($this->one($response, '#procedure-materials')));
        $this->assertSame('Zorunlu (en az bir malzeme)', $this->text($this->one($response, '.procedure-settings__materials')));

        $this->post(route('admin.procedures.materials.store', [$procedure, $draft]), ['material_id' => $this->makeMaterial('ALK-04')->id]);
        $this->assertFalse($draft->fresh()->material_required);
    }

    public function test_list_of_a_published_version_cannot_change_at_model_level(): void
    {
        $procedure = $this->procedure();
        $v1 = $this->publishVersion($procedure, [['steps' => 1]], materials: [[$this->makeMaterial('DET-01'), true]]);
        $item = $v1->materials()->sole();

        $this->assertThrows(fn () => $v1->materials()->create(['material_id' => $this->makeMaterial('DUR-03')->id, 'sequence' => 2]), LogicException::class, 'Yayımlanmış prosedür versiyonuna malzeme eklenemez (K-15).');
        $this->assertThrows(fn () => $item->update(['is_required' => false]), LogicException::class, 'Yayımlanmış prosedür versiyonunun malzemesi değiştirilemez (K-15).');
        $this->assertThrows(fn () => $item->delete(), LogicException::class, 'Yayımlanmış prosedür versiyonunun malzemesi silinemez (K-15).');

        $this->assertSame([['DET-01', 1, true]], $this->materialsOf($v1));
    }

    public function test_list_changes_are_recorded_under_the_procedure(): void
    {
        $procedure = $this->procedure();
        $draft = $this->draft($procedure);
        $material = $this->makeMaterial('DET-01');

        $this->post(route('admin.procedures.materials.store', [$procedure, $draft]), ['material_id' => $material->id, 'is_required' => '1']);
        $item = $draft->materials()->sole();

        $change = DefinitionChange::query()
            ->where('subject_type', DefinitionChange::typeOf($item))
            ->where('subject_id', $item->id)
            ->sole();
        $this->assertSame(DefinitionChange::typeOf($procedure), $change->root_type);
        $this->assertSame($procedure->id, $change->root_id);
        $this->assertSame('PRC-TEST v1 · DET-01', $change->subject_label);
    }

    /**
     * @return list<array{0: string, 1: int, 2: bool}>
     */
    private function materialsOf(ProcedureVersion $version): array
    {
        return $version->materials()->with('material')->get()
            ->map(fn ($item) => [$item->material->code, $item->sequence, $item->is_required])
            ->all();
    }
}
