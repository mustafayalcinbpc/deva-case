<?php

namespace Tests\Feature\Screens\Actions;

use App\Models\CleaningMaterial;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Malzeme aksiyonları: ekleme ve geçersiz kılma (K-12–K-14).
 */
class MaterialActionsTest extends CleaningActionTestCase
{
    public function test_owner_adds_a_material(): void
    {
        $ahmet = $this->operator();
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine());
        $material = $this->makeMaterial();

        $this->actingAs($ahmet)
            ->post(route('cleanings.materials.store', $cleaning), [
                'material_id' => (string) $material->id,
                'lot_no' => 'LOT-42',
                'expiry_date' => '2027-03-31',
            ])
            ->assertRedirect($this->showUrl($cleaning))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Malzeme eklendi.');

        $item = $cleaning->materials()->sole();
        $this->assertSame($material->id, $item->material_id);
        $this->assertSame('LOT-42', $item->lot_no);
        $this->assertSame('2027-03-31', $item->expiry_date->toDateString());
        $this->assertSame($ahmet->id, $item->added_by);
        $this->assertNull($item->voided_at);
    }

    public function test_expired_material_is_rejected_and_the_input_is_kept(): void
    {
        // K-14: sunucu tarihi 2026-10-09; dünkü tarih geçmiştir.
        $ahmet = $this->operator();
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine());
        $material = $this->makeMaterial();

        $response = $this->actingAs($ahmet)
            ->from($this->showUrl($cleaning))
            ->post(route('cleanings.materials.store', $cleaning), [
                'material_id' => $material->id,
                'lot_no' => 'LOT-42',
                'expiry_date' => '2026-10-08',
            ]);

        $this->assertViolation($response, $cleaning, 'material_expired')
            ->assertSessionHasInput('lot_no', 'LOT-42')
            ->assertSessionHasInput('expiry_date', '2026-10-08');
        $this->assertSame(0, $cleaning->materials()->count());
    }

    public function test_user_without_a_role_in_the_cleaning_cannot_add_materials(): void
    {
        $ahmet = $this->operator();
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine());
        $material = $this->makeMaterial();

        $response = $this->actingAs($this->operator('Zeynep'))
            ->from($this->showUrl($cleaning))
            ->post(route('cleanings.materials.store', $cleaning), [
                'material_id' => $material->id,
                'lot_no' => 'LOT-42',
                'expiry_date' => '2027-03-31',
            ]);

        $this->assertViolation($response, $cleaning, 'not_allowed');
        $this->assertSame(0, $cleaning->materials()->count());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function invalidMaterials(): iterable
    {
        yield 'malzeme seçilmemiş' => [['material_id' => null], 'material_id', 'malzeme zorunludur.'];
        yield 'katalogda olmayan malzeme' => [['material_id' => 999999], 'material_id', 'Seçilen malzeme geçersiz.'];
        yield 'lot girilmemiş' => [['lot_no' => ''], 'lot_no', 'lot numarası zorunludur.'];
        yield 'lot çok uzun' => [['lot_no' => str_repeat('L', 101)], 'lot_no', 'lot numarası en fazla 100 karakter olabilir.'];
        yield 'tarih girilmemiş' => [['expiry_date' => ''], 'expiry_date', 'son kullanma tarihi zorunludur.'];
        yield 'tarih yerel biçimde' => [['expiry_date' => '31.03.2027'], 'expiry_date', 'son kullanma tarihi Y-m-d biçiminde olmalıdır.'];
        yield 'takvimde olmayan gün' => [['expiry_date' => '2027-02-30'], 'expiry_date', 'son kullanma tarihi Y-m-d biçiminde olmalıdır.'];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidMaterials')]
    public function test_invalid_material_input_is_rejected(array $overrides, string $field, string $message): void
    {
        $ahmet = $this->operator();
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine());
        $material = $this->makeMaterial();

        $this->actingAs($ahmet)
            ->from($this->showUrl($cleaning))
            ->post(route('cleanings.materials.store', $cleaning), [
                'material_id' => $material->id,
                'lot_no' => 'LOT-42',
                'expiry_date' => '2027-03-31',
                ...$overrides,
            ])
            ->assertRedirect($this->showUrl($cleaning))
            ->assertSessionHasErrors([$field => $message]);

        $this->assertSame(0, $cleaning->materials()->count());
    }

    public function test_owner_voids_a_material_with_a_reason(): void
    {
        $ahmet = $this->operator();
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine(), materials: [$this->entry($this->makeMaterial())]);
        $item = $cleaning->materials()->sole();

        $this->actingAs($ahmet)
            ->post(route('cleanings.materials.void', [$cleaning, $item]), ['void_reason' => 'Yanlış lot girildi'])
            ->assertRedirect($this->showUrl($cleaning))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Malzeme geçersiz kılındı.');

        $item->refresh();
        $this->assertNotNull($item->voided_at);
        $this->assertSame($ahmet->id, $item->voided_by);
        $this->assertSame('Yanlış lot girildi', $item->void_reason);
        $this->assertSame('LOT-001', $item->lot_no, 'İlk kayıt olduğu gibi kalmalı.');
    }

    public function test_void_without_a_reason_is_rejected(): void
    {
        $ahmet = $this->operator();
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine(), materials: [$this->entry($this->makeMaterial())]);
        $item = $cleaning->materials()->sole();

        $this->actingAs($ahmet)
            ->from($this->showUrl($cleaning))
            ->post(route('cleanings.materials.void', [$cleaning, $item]), ['void_reason' => '   '])
            ->assertRedirect($this->showUrl($cleaning))
            ->assertSessionHasErrors(['void_reason' => 'gerekçe zorunludur.']);

        $this->assertNull($item->fresh()->voided_at);
    }

    public function test_void_reason_longer_than_the_limit_is_rejected(): void
    {
        $ahmet = $this->operator();
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine(), materials: [$this->entry($this->makeMaterial())]);
        $item = $cleaning->materials()->sole();

        $this->actingAs($ahmet)
            ->from($this->showUrl($cleaning))
            ->post(route('cleanings.materials.void', [$cleaning, $item]), ['void_reason' => str_repeat('a', 1001)])
            ->assertSessionHasErrors(['void_reason' => 'gerekçe en fazla 1000 karakter olabilir.']);

        $this->assertNull($item->fresh()->voided_at);
    }

    public function test_material_cannot_be_voided_twice(): void
    {
        $ahmet = $this->operator();
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine(), materials: [$this->entry($this->makeMaterial())]);
        $item = $cleaning->materials()->sole();
        $this->workflow()->voidMaterial($ahmet, $item, 'Yanlış lot girildi');
        $firstVoid = CleaningMaterial::findOrFail($item->id);

        $response = $this->actingAs($ahmet)
            ->from($this->showUrl($cleaning))
            ->post(route('cleanings.materials.void', [$cleaning, $item]), ['void_reason' => 'Tekrar']);

        $this->assertViolation($response, $cleaning, 'material_already_voided');
        $this->assertSame('Yanlış lot girildi', $item->fresh()->void_reason);
        $this->assertEquals($firstVoid->voided_at, $item->fresh()->voided_at);
    }
}
