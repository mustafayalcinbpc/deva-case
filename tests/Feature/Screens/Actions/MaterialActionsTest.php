<?php

namespace Tests\Feature\Screens\Actions;

use App\Models\Cleaning;
use App\Models\CleaningMaterial;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Malzeme aksiyonları: lot seçerek ekleme ve geçersiz kılma (K-12–K-14). Başarılı aksiyondan
 * sonra detay sayfası Malzemeler sekmesinde açılır (#materials).
 */
class MaterialActionsTest extends CleaningActionTestCase
{
    public function test_owner_adds_a_material_by_choosing_a_lot(): void
    {
        // K-14: operatör lotu seçer; lot no ve SKT lot kaydından kopyalanır.
        $ahmet = $this->operator();
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine());
        $lot = $this->lot($this->makeMaterial(), 'LOT-42', '2027-03-31');

        $this->actingAs($ahmet)
            ->post(route('cleanings.materials.store', $cleaning), ['material_lot_id' => (string) $lot->id])
            ->assertRedirect($this->materialsUrl($cleaning))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Malzeme eklendi.');

        $item = $cleaning->materials()->sole();
        $this->assertSame($lot->id, $item->material_lot_id);
        $this->assertSame($lot->material_id, $item->material_id);
        $this->assertSame('LOT-42', $item->lot_no);
        $this->assertSame('2027-03-31', $item->expiry_date->toDateString());
        $this->assertSame($ahmet->id, $item->added_by);
        $this->assertNull($item->voided_at);
    }

    public function test_expired_lot_is_rejected_and_the_input_is_kept(): void
    {
        // K-14: sunucu tarihi 2026-10-09; SKT'si dün dolan lot kullanılamaz.
        $ahmet = $this->operator();
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine());
        $lot = $this->lot($this->makeMaterial(), 'LOT-42', '2026-10-08');

        $response = $this->actingAs($ahmet)
            ->from($this->showUrl($cleaning))
            ->post(route('cleanings.materials.store', $cleaning), ['material_lot_id' => $lot->id]);

        $this->assertViolation($response, $cleaning, 'material_expired')
            ->assertSessionHasInput('material_lot_id', $lot->id);
        $this->assertSame(0, $cleaning->materials()->count());
    }

    public function test_inactive_lot_is_rejected(): void
    {
        // K-14: kullanımdan kaldırılan (ör. geri çağrılan) lot eklenemez.
        $ahmet = $this->operator();
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine());
        $lot = $this->lot($this->makeMaterial(), 'LOT-42');
        $lot->update(['is_active' => false]);

        $response = $this->actingAs($ahmet)
            ->from($this->showUrl($cleaning))
            ->post(route('cleanings.materials.store', $cleaning), ['material_lot_id' => $lot->id]);

        $this->assertViolation($response, $cleaning, 'material_lot_unavailable');
        $this->assertSame(0, $cleaning->materials()->count());
    }

    public function test_user_without_a_role_in_the_cleaning_cannot_add_materials(): void
    {
        $ahmet = $this->operator();
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine());
        $lot = $this->lot($this->makeMaterial(), 'LOT-42');

        $response = $this->actingAs($this->operator('Zeynep'))
            ->from($this->showUrl($cleaning))
            ->post(route('cleanings.materials.store', $cleaning), ['material_lot_id' => $lot->id]);

        $this->assertViolation($response, $cleaning, 'not_allowed');
        $this->assertSame(0, $cleaning->materials()->count());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidLots(): iterable
    {
        yield 'lot seçilmemiş' => [['material_lot_id' => null]];
        yield 'boş seçim' => [['material_lot_id' => '']];
        yield 'olmayan lot' => [['material_lot_id' => 999999]];
        yield 'sayı olmayan değer' => [['material_lot_id' => 'LOT-42']];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    #[DataProvider('invalidLots')]
    public function test_invalid_lot_input_is_rejected(array $input): void
    {
        // Eski alanlar (malzeme, lot no, SKT) artık kabul edilmez; yalnızca lot seçimi.
        $ahmet = $this->operator();
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine());
        $material = $this->makeMaterial();

        $this->actingAs($ahmet)
            ->from($this->showUrl($cleaning))
            ->post(route('cleanings.materials.store', $cleaning), [
                'material_id' => $material->id,
                'lot_no' => 'LOT-42',
                'expiry_date' => '2027-03-31',
                ...$input,
            ])
            // Doğrulama hatası Malzemeler sekmesine döner; hata gizli sekmede kalmaz.
            ->assertRedirect($this->materialsUrl($cleaning))
            ->assertSessionHasErrors(['material_lot_id']);

        $this->assertSame(0, $cleaning->materials()->count());
    }

    public function test_owner_voids_a_material_with_a_reason(): void
    {
        $ahmet = $this->operator();
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine(), materials: [$this->entry($this->makeMaterial())]);
        $item = $cleaning->materials()->sole();

        $this->actingAs($ahmet)
            ->post(route('cleanings.materials.void', [$cleaning, $item]), ['void_reason' => 'Yanlış lot girildi'])
            ->assertRedirect($this->materialsUrl($cleaning))
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
            ->assertRedirect($this->materialsUrl($cleaning))
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

    private function materialsUrl(Cleaning $cleaning): string
    {
        return route('cleanings.show', $cleaning).'#materials';
    }
}
