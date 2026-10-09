<?php

namespace Tests\Feature\Cleaning;

use App\Enums\CancelReason;
use App\Enums\CleaningStatus;
use App\Enums\SliceEndReason;
use App\Enums\StepStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\TestCase;

/**
 * İptal kuralları (K-08, K-09; R-36–R-39):
 *  - başlamamış kayıt: sahibi yalnızca "hatalı kayıt" gerekçesiyle, yönetici her gerekçeyle;
 *  - başlamış kayıt: yalnızca yönetici;
 *  - açıklama her zaman zorunlu.
 */
class CancellationTest extends TestCase
{
    use BuildsCleaningFixtures, InteractsWithCleaningWorkflow, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('08:00:00');
    }

    public function test_owner_can_cancel_an_unstarted_record_as_invalid(): void
    {
        // K-09
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine);
        $this->at('08:05:00');

        $this->workflow()->cancel($ahmet, $cleaning->fresh(), CancelReason::InvalidRecord, 'Yanlış makine seçildi');

        $cleaning = $cleaning->fresh();
        $this->assertSame(CleaningStatus::Cancelled, $cleaning->status);
        $this->assertMoment('2026-10-09 08:05:00', $cleaning->closed_at);
        $this->assertEquals($ahmet->id, $cleaning->cancelled_by);
        $this->assertSame(CancelReason::InvalidRecord, $cleaning->cancel_reason);
        $this->assertSame('Yanlış makine seçildi', $cleaning->cancel_note);
        $this->assertSame(StepStatus::Pending, $this->stepOf($cleaning, 1)->status);

        $event = $this->lastEvent($cleaning, 'cleaning.cancelled');
        $this->assertEquals($ahmet->id, $event->actor_id);
        $this->assertSame('invalid_record', $event->payload['reason']);
        $this->assertSame('Yanlış makine seçildi', $event->payload['note']);
        $this->assertEventChainIntact($cleaning);
    }

    public static function reasonsOtherThanInvalidRecord(): iterable
    {
        yield 'personel ayrıldı' => [CancelReason::PersonnelLeft];
        yield 'diğer' => [CancelReason::Other];
    }

    #[DataProvider('reasonsOtherThanInvalidRecord')]
    public function test_owner_can_cancel_an_unstarted_record_only_as_invalid_record(CancelReason $reason): void
    {
        // K-09: sahibin iptalinde gerekçe türü "hatalı kayıt" olur.
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine);

        $this->assertRuleViolation('not_allowed', fn () => $this->workflow()->cancel($ahmet, $cleaning->fresh(), $reason, 'Vazgeçtim'));

        $this->assertSame(CleaningStatus::Created, $cleaning->fresh()->status);
    }

    public function test_owner_cannot_cancel_a_started_record(): void
    {
        // K-08, K-09: başlamış kaydı yalnızca yönetici iptal edebilir.
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine);
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));

        $this->assertRuleViolation('not_allowed', fn () => $this->workflow()->cancel($ahmet, $cleaning->fresh(), CancelReason::InvalidRecord, 'Yanlış makine'));

        $this->assertSame(CleaningStatus::InProgress, $cleaning->fresh()->status);
        $this->assertSame(StepStatus::Running, $this->stepOf($cleaning, 1)->status);
    }

    public function test_owner_with_an_outdated_view_cannot_cancel_a_record_that_has_since_started(): void
    {
        // K-09 + Sözleşme 1B: karar kilitlenen güncel satıra göre verilir. Sahibin elindeki
        // model "created" görünürken yardımcı ilk adımı başlatmıştır.
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $cleaning = $this->openCleaning($ahmet, $machine, [$mehmet]);
        $staleCleaning = $cleaning->fresh();
        $this->workflow()->startStep($mehmet, $this->stepOf($cleaning, 1));

        $this->assertRuleViolation('not_allowed', fn () => $this->workflow()->cancel($ahmet, $staleCleaning, CancelReason::InvalidRecord, 'Yanlış makine'));

        $this->assertSame(CleaningStatus::InProgress, $cleaning->fresh()->status);
    }

    public function test_other_operators_cannot_cancel_someone_elses_record(): void
    {
        // R-38: saha personeli istediği kaydı kapatamaz.
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $cleaning = $this->openCleaning($ahmet, $machine);

        $this->assertRuleViolation('not_allowed', fn () => $this->workflow()->cancel($mehmet, $cleaning->fresh(), CancelReason::InvalidRecord, 'Hatalı'));

        $this->assertSame(CleaningStatus::Created, $cleaning->fresh()->status);
    }

    public function test_helpers_cannot_cancel_the_record_they_work_on(): void
    {
        // R-38, K-09: iptal sahibine (başlamamışsa) ve yöneticiye açıktır; görevli olmak yetmez.
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $cleaning = $this->openCleaning($ahmet, $machine, [$mehmet]);

        $this->assertRuleViolation('not_allowed', fn () => $this->workflow()->cancel($mehmet, $cleaning->fresh(), CancelReason::InvalidRecord, 'Hatalı'));
    }

    public static function allReasons(): iterable
    {
        foreach (CancelReason::cases() as $reason) {
            yield $reason->value => [$reason];
        }
    }

    #[DataProvider('allReasons')]
    public function test_manager_can_cancel_an_unstarted_record_with_any_reason(CancelReason $reason): void
    {
        // K-08
        $machine = $this->makeMachine();
        $manager = $this->manager();
        $cleaning = $this->openCleaning($this->operator('Ahmet'), $machine);

        $this->workflow()->cancel($manager, $cleaning->fresh(), $reason, 'Yönetici müdahalesi');

        $cleaning = $cleaning->fresh();
        $this->assertSame(CleaningStatus::Cancelled, $cleaning->status);
        $this->assertSame($reason, $cleaning->cancel_reason);
        $this->assertEquals($manager->id, $cleaning->cancelled_by);
        $this->assertSame($reason->value, $this->lastEvent($cleaning, 'cleaning.cancelled')->payload['reason']);
    }

    public function test_manager_cancelling_a_running_step_closes_the_slice_and_keeps_the_work_done(): void
    {
        // K-08: yapılan adımlar ve dilimler kalır; açık dilim iptal anında kapanır;
        // çalışan adım duraklatılmış olur.
        $machine = $this->makeMachine([['steps' => 3]]);
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $manager = $this->manager();
        $cleaning = $this->openCleaning($ahmet, $machine, [$mehmet]);
        $this->runStep($ahmet, $cleaning, 1, 300);
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 2));
        $this->at('08:12:00');

        $this->workflow()->cancel($manager, $cleaning->fresh(), CancelReason::PersonnelLeft, 'Ahmet başka bölüme geçti');

        $cleaning = $cleaning->fresh();
        $this->assertSame(CleaningStatus::Cancelled, $cleaning->status);
        $this->assertMoment('2026-10-09 08:12:00', $cleaning->closed_at);
        $this->assertSame('Ahmet başka bölüme geçti', $cleaning->cancel_note);

        $first = $this->stepOf($cleaning, 1);
        $this->assertSame(StepStatus::Completed, $first->status);
        $this->assertSame(SliceEndReason::Completed, $first->slices()->sole()->end_reason);

        $second = $this->stepOf($cleaning, 2);
        $this->assertSame(StepStatus::Paused, $second->status);
        $slice = $second->slices()->sole();
        $this->assertMoment('2026-10-09 08:12:00', $slice->ended_at);
        $this->assertSame(SliceEndReason::Cancelled, $slice->end_reason);
        foreach ($slice->workers as $worker) {
            $this->assertMoment('2026-10-09 08:12:00', $worker->ended_at);
        }
        $this->assertSame(StepStatus::Pending, $this->stepOf($cleaning, 3)->status);

        $event = $this->lastEvent($cleaning, 'cleaning.cancelled');
        $this->assertEquals($manager->id, $event->actor_id);
        $this->assertSame('personnel_left', $event->payload['reason']);
        $this->assertSame('Ahmet başka bölüme geçti', $event->payload['note']);
        $this->assertEventChainIntact($cleaning);
    }

    public function test_cancelling_releases_the_machine_and_the_workers(): void
    {
        // K-08: makine ve personel serbest kalır; iş yeni bir kayıtla tamamlanır.
        $m01 = $this->makeMachine(code: 'M01');
        $m02 = $this->makeMachine(code: 'M02');
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $manager = $this->manager();
        $cancelled = $this->openCleaning($ahmet, $m01, [$mehmet]);
        $replacement = $this->openCleaning($mehmet, $m01);
        $ahmetsOther = $this->openCleaning($ahmet, $m02);
        $this->workflow()->startStep($ahmet, $this->stepOf($cancelled, 1));

        $this->workflow()->cancel($manager, $cancelled->fresh(), CancelReason::PersonnelLeft, 'Ahmet ayrıldı');

        // M01 ve Mehmet serbest: Mehmet aynı makinede yeni kaydı başlatabilir.
        $this->workflow()->startStep($mehmet, $this->stepOf($replacement, 1));
        $this->assertSame(CleaningStatus::InProgress, $replacement->fresh()->status);
        // Ahmet serbest: başka makinede çalışabilir.
        $this->workflow()->startStep($ahmet, $this->stepOf($ahmetsOther, 1));
        $this->assertSame(CleaningStatus::InProgress, $ahmetsOther->fresh()->status);
    }

    public function test_manager_can_cancel_a_record_whose_step_is_paused(): void
    {
        // K-08, R-31: vardiya bitti, adım duraklatıldı; yönetici kaydı kapatır.
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $manager = $this->manager();
        $cleaning = $this->openCleaning($ahmet, $machine);
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        $this->at('08:10:00');
        $this->workflow()->pauseStep($ahmet, $this->stepOf($cleaning, 1));
        $this->at('12:00:00');

        $this->workflow()->cancel($manager, $cleaning->fresh(), CancelReason::Other, 'Vardiya bitti, devam edilmeyecek');

        $this->assertSame(CleaningStatus::Cancelled, $cleaning->fresh()->status);
        $step = $this->stepOf($cleaning, 1);
        $this->assertSame(StepStatus::Paused, $step->status);
        $slice = $step->slices()->sole();
        $this->assertSame(SliceEndReason::Paused, $slice->end_reason);
        $this->assertMoment('2026-10-09 08:10:00', $slice->ended_at);
    }

    public static function missingNotes(): iterable
    {
        yield 'boş açıklama' => [''];
        yield 'yalnızca boşluk (yorum)' => ['   '];
    }

    #[DataProvider('missingNotes')]
    public function test_cancellation_requires_a_note(string $note): void
    {
        // K-08, K-09: açıklama yazmak zorunlu.
        $machine = $this->makeMachine();
        $manager = $this->manager();
        $cleaning = $this->openCleaning($this->operator('Ahmet'), $machine);

        $this->assertRuleViolation('reason_required', fn () => $this->workflow()->cancel($manager, $cleaning->fresh(), CancelReason::Other, $note));

        $this->assertSame(CleaningStatus::Created, $cleaning->fresh()->status);
        $this->assertNotContains('cleaning.cancelled', $this->eventTypes($cleaning));
    }

    public function test_inactive_manager_cannot_cancel(): void
    {
        $machine = $this->makeMachine();
        $manager = $this->deactivate($this->manager());
        $cleaning = $this->openCleaning($this->operator('Ahmet'), $machine);

        $this->assertRuleViolation('inactive_user', fn () => $this->workflow()->cancel($manager, $cleaning->fresh(), CancelReason::Other, 'Kapatılıyor'));

        $this->assertSame(CleaningStatus::Created, $cleaning->fresh()->status);
    }
}
