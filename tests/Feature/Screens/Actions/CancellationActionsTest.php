<?php

namespace Tests\Feature\Screens\Actions;

use App\Enums\CancelReason;
use App\Enums\CleaningStatus;
use App\Enums\StepStatus;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Kaydı iptal etme (K-08, K-09).
 */
class CancellationActionsTest extends CleaningActionTestCase
{
    public function test_owner_cancels_a_record_that_has_not_started_as_invalid(): void
    {
        $ahmet = $this->operator();
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine());

        $this->actingAs($ahmet)
            ->post(route('cleanings.cancel', $cleaning), [
                'cancel_reason' => 'invalid_record',
                'cancel_note' => 'Yanlış makine seçildi',
            ])
            ->assertRedirect($this->showUrl($cleaning))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Kayıt iptal edildi.');

        $cleaning->refresh();
        $this->assertSame(CleaningStatus::Cancelled, $cleaning->status);
        $this->assertSame(CancelReason::InvalidRecord, $cleaning->cancel_reason);
        $this->assertSame('Yanlış makine seçildi', $cleaning->cancel_note);
        $this->assertSame($ahmet->id, $cleaning->cancelled_by);
        $this->assertNotNull($cleaning->closed_at);
    }

    public function test_owner_cannot_cancel_a_started_record(): void
    {
        // K-09: başlamış kaydı yalnızca yönetici iptal eder.
        $ahmet = $this->operator();
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine());
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));

        $response = $this->actingAs($ahmet)
            ->from($this->showUrl($cleaning))
            ->post(route('cleanings.cancel', $cleaning), [
                'cancel_reason' => 'invalid_record',
                'cancel_note' => 'Yanlış makine seçildi',
            ]);

        $this->assertViolation($response, $cleaning, 'not_allowed')
            ->assertSessionHasErrors(['workflow' => 'Bu işlem için yetkiniz yok: kaydı iptal etme.']);
        $this->assertSame(CleaningStatus::InProgress, $cleaning->fresh()->status);
        $this->assertSame(StepStatus::Running, $this->stepOf($cleaning, 1)->status);
    }

    public function test_owner_may_only_cancel_as_invalid_record(): void
    {
        $ahmet = $this->operator();
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine());

        $response = $this->actingAs($ahmet)
            ->from($this->showUrl($cleaning))
            ->post(route('cleanings.cancel', $cleaning), [
                'cancel_reason' => 'personnel_left',
                'cancel_note' => 'Vardiya bitti',
            ]);

        $this->assertViolation($response, $cleaning, 'not_allowed');
        $this->assertSame(CleaningStatus::Created, $cleaning->fresh()->status);
    }

    public function test_manager_cancels_a_started_record(): void
    {
        // K-08: açık dilim kapanır, çalışan adım duraklatılmış olarak kalır.
        $ahmet = $this->operator();
        $manager = $this->manager();
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine());
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        $this->travel(60)->seconds();

        $this->actingAs($manager)
            ->post(route('cleanings.cancel', $cleaning), [
                'cancel_reason' => 'personnel_left',
                'cancel_note' => 'Operatör vardiyadan ayrıldı',
            ])
            ->assertRedirect($this->showUrl($cleaning))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Kayıt iptal edildi.');

        $cleaning->refresh();
        $this->assertSame(CleaningStatus::Cancelled, $cleaning->status);
        $this->assertSame(CancelReason::PersonnelLeft, $cleaning->cancel_reason);
        $this->assertSame($manager->id, $cleaning->cancelled_by);
        $step = $this->stepOf($cleaning, 1);
        $this->assertSame(StepStatus::Paused, $step->status);
        $this->assertNull($step->openSlice()->first());
    }

    public function test_closed_record_cannot_be_cancelled_again(): void
    {
        $ahmet = $this->operator();
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine());
        $this->workflow()->cancel($ahmet, $cleaning, CancelReason::InvalidRecord, 'Yanlış makine seçildi');

        $response = $this->actingAs($this->manager())
            ->from($this->showUrl($cleaning))
            ->post(route('cleanings.cancel', $cleaning), [
                'cancel_reason' => 'other',
                'cancel_note' => 'Tekrar',
            ]);

        $this->assertViolation($response, $cleaning, 'record_closed');
        $this->assertSame('Yanlış makine seçildi', $cleaning->fresh()->cancel_note);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function invalidCancellations(): iterable
    {
        yield 'açıklama yok' => [['cancel_note' => ''], 'cancel_note', 'açıklama zorunludur.'];
        yield 'açıklama yalnızca boşluk' => [['cancel_note' => '   '], 'cancel_note', 'açıklama zorunludur.'];
        yield 'açıklama çok uzun' => [['cancel_note' => str_repeat('a', 2001)], 'cancel_note', 'açıklama en fazla 2000 karakter olabilir.'];
        yield 'gerekçe yok' => [['cancel_reason' => ''], 'cancel_reason', 'iptal gerekçesi zorunludur.'];
        yield 'tanımsız gerekçe' => [['cancel_reason' => 'whatever'], 'cancel_reason', 'Seçilen iptal gerekçesi geçersiz.'];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidCancellations')]
    public function test_invalid_cancellation_input_is_rejected(array $overrides, string $field, string $message): void
    {
        $ahmet = $this->operator();
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine());

        $this->actingAs($ahmet)
            ->from($this->showUrl($cleaning))
            ->post(route('cleanings.cancel', $cleaning), [
                'cancel_reason' => 'invalid_record',
                'cancel_note' => 'Yanlış makine seçildi',
                ...$overrides,
            ])
            ->assertRedirect($this->showUrl($cleaning))
            ->assertSessionHasErrors([$field => $message]);

        $this->assertSame(CleaningStatus::Created, $cleaning->fresh()->status);
    }
}
