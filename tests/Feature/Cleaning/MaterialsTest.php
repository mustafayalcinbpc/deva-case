<?php

namespace Tests\Feature\Cleaning;

use App\Enums\CleaningStatus;
use App\Enums\StepStatus;
use App\Models\CleaningMaterial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\TestCase;

/**
 * Malzeme girişi ve zorunluluğu (K-12, K-13, K-14; R-07–R-10).
 */
class MaterialsTest extends TestCase
{
    use BuildsCleaningFixtures, InteractsWithCleaningWorkflow, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('08:00:00');
    }

    public function test_required_material_blocks_the_first_step(): void
    {
        // K-12, R-09: zorunluysa malzeme girilmeden ilk adım başlatılamaz.
        $machine = $this->makeMachine(materialRequired: true);
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine);

        $this->assertRuleViolation('material_required', fn () => $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1)));

        $cleaning = $cleaning->fresh();
        $this->assertSame(CleaningStatus::Created, $cleaning->status);
        $this->assertNull($cleaning->started_at);
        $this->assertNull($cleaning->field_ref);
        $this->assertSame(StepStatus::Pending, $this->stepOf($cleaning, 1)->status);
        $this->assertSame(['cleaning.opened'], $this->eventTypes($cleaning));
    }

    public function test_required_material_given_at_opening_allows_the_first_step(): void
    {
        $machine = $this->makeMachine(materialRequired: true);
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine, materials: [$this->entry($this->makeMaterial())]);

        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));

        $this->assertSame(CleaningStatus::InProgress, $cleaning->fresh()->status);
    }

    public function test_required_material_added_after_opening_allows_the_first_step(): void
    {
        // K-12: malzeme sonradan da eklenebilir.
        $machine = $this->makeMachine(materialRequired: true);
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine);

        $this->workflow()->addMaterial($ahmet, $cleaning->fresh(), $this->entry($this->makeMaterial()));
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));

        $this->assertSame(CleaningStatus::InProgress, $cleaning->fresh()->status);
    }

    public function test_voided_material_does_not_satisfy_the_requirement(): void
    {
        // K-12: geçersiz işaretlenen malzeme sayılmaz.
        $machine = $this->makeMachine(materialRequired: true);
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine, materials: [$this->entry($this->makeMaterial())]);
        $this->workflow()->voidMaterial($ahmet, $cleaning->materials()->sole(), 'Yanlış ürün okutuldu');

        $this->assertRuleViolation('material_required', fn () => $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1)));

        $this->assertSame(CleaningStatus::Created, $cleaning->fresh()->status);
    }

    public function test_material_is_optional_when_the_procedure_does_not_require_it(): void
    {
        // R-08, K-13
        $machine = $this->makeMachine(materialRequired: false);
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine);

        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));

        $this->assertSame(CleaningStatus::InProgress, $cleaning->fresh()->status);
    }

    public function test_material_can_be_added_while_the_cleaning_is_running(): void
    {
        // K-12, R-10: hangi malzeme ve lot kullanıldı geriye dönük cevaplanabilir.
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $cleaning = $this->openCleaning($ahmet, $machine, [$mehmet]);
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        $this->at('08:20:00');
        $rinse = $this->makeMaterial('RNS-02');

        $item = $this->workflow()->addMaterial($mehmet, $cleaning->fresh(), $this->entry($rinse, 'LOT-777', '2026-11-30'));

        $this->assertInstanceOf(CleaningMaterial::class, $item);
        $item = $item->fresh();
        $this->assertEquals($cleaning->id, $item->cleaning_id);
        $this->assertEquals($rinse->id, $item->material_id);
        $this->assertSame('LOT-777', $item->lot_no);
        $this->assertSame('2026-11-30', $item->expiry_date->format('Y-m-d'));
        $this->assertEquals($mehmet->id, $item->added_by);

        $event = $this->lastEvent($cleaning, 'material.added');
        $this->assertEquals($mehmet->id, $event->actor_id);
        $this->assertMoment('2026-10-09 08:20:00', $event->occurred_at);
        $this->assertEquals($item->id, $event->payload['cleaning_material_id']);
        $this->assertEquals($rinse->id, $event->payload['material_id']);
        $this->assertSame('LOT-777', $event->payload['lot_no']);
        $this->assertSame('2026-11-30', $event->payload['expiry_date']);
    }

    public function test_expired_material_cannot_be_added(): void
    {
        // K-14: son kullanma tarihi sunucu tarihine göre geçmişse kaydedilemez.
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine);
        $events = $this->eventTypes($cleaning);

        $e = $this->assertRuleViolation('material_expired', fn () => $this->workflow()->addMaterial(
            $ahmet, $cleaning->fresh(), $this->entry($this->makeMaterial(), 'LOT-OLD', '2026-10-08'),
        ));

        $this->assertSame('2026-10-08', $e->context['expiry_date']);
        $this->assertSame(0, CleaningMaterial::count());
        $this->assertSame($events, $this->eventTypes($cleaning));
    }

    public function test_material_expiring_today_can_be_added(): void
    {
        // K-14: bugün geçerlidir.
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine);
        $this->at('23:59:59');

        $this->workflow()->addMaterial($ahmet, $cleaning->fresh(), $this->entry($this->makeMaterial(), 'LOT-TODAY', '2026-10-09'));

        $this->assertSame(1, CleaningMaterial::count());
    }

    public function test_voiding_keeps_the_original_entry_and_marks_it_invalid(): void
    {
        // K-12: yanlış girilen malzeme silinmez; gerekçeyle geçersiz işaretlenir.
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine, materials: [$this->entry($this->makeMaterial(), 'LOT-WRONG')]);
        $item = $cleaning->materials()->sole();
        $this->at('08:03:00');

        $this->workflow()->voidMaterial($ahmet, $item, 'Lot numarası yanlış girildi');

        $item = CleaningMaterial::find($item->id);
        $this->assertNotNull($item, 'Satır silinmemeli.');
        $this->assertSame('LOT-WRONG', $item->lot_no);
        $this->assertMoment('2026-10-09 08:03:00', $item->voided_at);
        $this->assertEquals($ahmet->id, $item->voided_by);
        $this->assertSame('Lot numarası yanlış girildi', $item->void_reason);
        $this->assertSame(0, CleaningMaterial::valid()->count());

        $event = $this->lastEvent($cleaning, 'material.voided');
        $this->assertEquals($item->id, $event->payload['cleaning_material_id']);
        $this->assertSame('Lot numarası yanlış girildi', $event->payload['reason']);
        $this->assertEventChainIntact($cleaning);
    }

    public function test_an_already_voided_material_cannot_be_voided_again(): void
    {
        // K-12: ilk geçersiz kılma kaydı (zaman, kişi, gerekçe) değişmeden kalır.
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine, materials: [$this->entry($this->makeMaterial())]);
        $item = $cleaning->materials()->sole();
        $this->workflow()->voidMaterial($ahmet, $item, 'Lot numarası yanlış girildi');
        $this->at('08:05:00');

        $this->assertRuleViolation('material_already_voided', fn () => $this->workflow()->voidMaterial($ahmet, $item, 'İkinci deneme'));

        $item = $item->fresh();
        $this->assertMoment('2026-10-09 08:00:00', $item->voided_at);
        $this->assertSame('Lot numarası yanlış girildi', $item->void_reason);
        $this->assertCount(1, array_keys($this->eventTypes($cleaning), 'material.voided'));
    }

    public static function missingVoidReasons(): iterable
    {
        yield 'boş gerekçe' => [''];
        yield 'yalnızca boşluk (yorum)' => ['   '];
    }

    #[DataProvider('missingVoidReasons')]
    public function test_voiding_requires_a_reason(string $reason): void
    {
        // K-12
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine, materials: [$this->entry($this->makeMaterial())]);
        $item = $cleaning->materials()->sole();

        $this->assertRuleViolation('reason_required', fn () => $this->workflow()->voidMaterial($ahmet, $item, $reason));

        $item = $item->fresh();
        $this->assertNull($item->voided_at);
        $this->assertNull($item->void_reason);
        $this->assertNotContains('material.voided', $this->eventTypes($cleaning));
    }
}
