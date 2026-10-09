<?php

namespace Tests\Unit\Cleaning;

use App\Enums\CleaningStatus;
use App\Enums\PhaseStatus;
use App\Enums\StepStatus;
use App\Exceptions\CleaningRuleViolation;
use App\Models\CleaningStep;
use BackedEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Durum makinelerinin geçiş tabloları, is-gereksinimleri.md "Kararlardan çıkan sonuçlar"
 * bölümündeki diyagramlara ve plan.md Sözleşme 1B'ye göre yazıldı (enum koduna bakılmadan).
 * Her (kaynak, hedef) çifti tek tek denenir; tabloda olmayan her geçiş yasaktır.
 */
class StatusTransitionsTest extends TestCase
{
    /**
     * BAŞLAMADI → DEVAM EDİYOR → TAMAMLANDI; BAŞLAMADI → SÜRESİ DOLDU (K-06);
     * BAŞLAMADI/DEVAM EDİYOR → İPTAL (K-08, K-09).
     */
    private const CLEANING_ALLOWED = [
        'created' => ['in_progress', 'expired', 'cancelled'],
        'in_progress' => ['completed', 'cancelled'],
        'completed' => [],
        'expired' => [],
        'cancelled' => [],
    ];

    /** BEKLİYOR → DEVAM EDİYOR → TAMAMLANDI. */
    private const PHASE_ALLOWED = [
        'pending' => ['in_progress'],
        'in_progress' => ['completed'],
        'completed' => [],
    ];

    /**
     * BEKLİYOR → ÇALIŞIYOR ⇄ DURAKLATILDI; yalnızca ÇALIŞIYOR → TAMAMLANDI
     * (Sözleşme 1B completeStep: duraklatılmış adım tamamlanamaz).
     */
    private const STEP_ALLOWED = [
        'pending' => ['running'],
        'running' => ['paused', 'completed'],
        'paused' => ['running'],
        'completed' => [],
    ];

    public static function cleaningTransitions(): iterable
    {
        return self::matrix(CleaningStatus::cases(), self::CLEANING_ALLOWED);
    }

    public static function phaseTransitions(): iterable
    {
        return self::matrix(PhaseStatus::cases(), self::PHASE_ALLOWED);
    }

    public static function stepTransitions(): iterable
    {
        return self::matrix(StepStatus::cases(), self::STEP_ALLOWED);
    }

    #[DataProvider('cleaningTransitions')]
    public function test_cleaning_status_transition_table(CleaningStatus $from, CleaningStatus $to, bool $allowed): void
    {
        $this->assertSame($allowed, $from->canTransitionTo($to));
    }

    #[DataProvider('phaseTransitions')]
    public function test_phase_status_transition_table(PhaseStatus $from, PhaseStatus $to, bool $allowed): void
    {
        $this->assertSame($allowed, $from->canTransitionTo($to));
    }

    #[DataProvider('stepTransitions')]
    public function test_step_status_transition_table(StepStatus $from, StepStatus $to, bool $allowed): void
    {
        $this->assertSame($allowed, $from->canTransitionTo($to));
    }

    public function test_every_case_is_covered_by_the_expected_tables(): void
    {
        $this->assertEqualsCanonicalizing(array_keys(self::CLEANING_ALLOWED), array_column(CleaningStatus::cases(), 'value'));
        $this->assertEqualsCanonicalizing(array_keys(self::PHASE_ALLOWED), array_column(PhaseStatus::cases(), 'value'));
        $this->assertEqualsCanonicalizing(array_keys(self::STEP_ALLOWED), array_column(StepStatus::cases(), 'value'));
    }

    public function test_closed_cleaning_states_are_terminal(): void
    {
        foreach ([CleaningStatus::Completed, CleaningStatus::Expired, CleaningStatus::Cancelled] as $status) {
            $this->assertSame([], $status->transitions(), "{$status->value} terminal olmalı");
        }
    }

    public function test_completed_phase_and_step_are_terminal(): void
    {
        $this->assertSame([], PhaseStatus::Completed->transitions());
        $this->assertSame([], StepStatus::Completed->transitions());
    }

    public function test_no_status_can_transition_to_itself(): void
    {
        foreach ([...CleaningStatus::cases(), ...PhaseStatus::cases(), ...StepStatus::cases()] as $status) {
            $this->assertFalse($status->canTransitionTo($status), $status::class."::{$status->name} kendisine geçmemeli");
        }
    }

    public function test_only_created_and_in_progress_cleanings_are_open(): void
    {
        $this->assertTrue(CleaningStatus::Created->isOpen());
        $this->assertTrue(CleaningStatus::InProgress->isOpen());
        $this->assertFalse(CleaningStatus::Completed->isOpen());
        $this->assertFalse(CleaningStatus::Expired->isOpen());
        $this->assertFalse(CleaningStatus::Cancelled->isOpen());
    }

    public function test_paused_step_cannot_be_completed_directly(): void
    {
        // Gereksinimdeki "ÇALIŞIYOR ⇄ DURAKLATILDI → TAMAMLANDI" yazımına rağmen Sözleşme 1B:
        // tamamlamak için önce devam ettirmek gerekir.
        $this->assertFalse(StepStatus::Paused->canTransitionTo(StepStatus::Completed));
        $this->assertTrue(StepStatus::Running->canTransitionTo(StepStatus::Completed));
    }

    public function test_model_transition_outside_the_table_raises_invalid_transition(): void
    {
        $step = new CleaningStep(['status' => StepStatus::Paused]);

        try {
            $step->transitionTo(StepStatus::Completed);
            $this->fail('invalid_transition bekleniyordu.');
        } catch (CleaningRuleViolation $e) {
            $this->assertSame('invalid_transition', $e->rule);
            $this->assertSame(['subject' => 'CleaningStep', 'from' => 'paused', 'to' => 'completed'], $e->context);
        }

        $this->assertSame(StepStatus::Paused, $step->status);
    }

    public function test_model_transition_inside_the_table_changes_status(): void
    {
        $step = new CleaningStep(['status' => StepStatus::Paused]);

        $step->transitionTo(StepStatus::Running);

        $this->assertSame(StepStatus::Running, $step->status);
    }

    /**
     * @param  list<BackedEnum>  $cases
     * @param  array<string, list<string>>  $allowed
     */
    private static function matrix(array $cases, array $allowed): iterable
    {
        foreach ($cases as $from) {
            foreach ($cases as $to) {
                $isAllowed = in_array($to->value, $allowed[$from->value] ?? [], true);

                yield "{$from->value} → {$to->value}".($isAllowed ? '' : ' (yasak)') => [$from, $to, $isAllowed];
            }
        }
    }
}
