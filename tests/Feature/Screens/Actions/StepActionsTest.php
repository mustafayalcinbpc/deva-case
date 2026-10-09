<?php

namespace Tests\Feature\Screens\Actions;

use App\Enums\CleaningStatus;
use App\Enums\PhaseStatus;
use App\Enums\StepStatus;
use App\Models\User;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Adım aksiyonları: başlat, duraklat, devam et, tamamla, görevlileri değiştir.
 */
class StepActionsTest extends CleaningActionTestCase
{
    public function test_owner_starts_the_first_step(): void
    {
        $ahmet = $this->operator();
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine());
        $step = $this->stepOf($cleaning, 1);

        $this->actingAs($ahmet)
            ->post(route('cleanings.steps.start', [$cleaning, $step]))
            ->assertRedirect($this->showUrl($cleaning, $step))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', '1. adım başlatıldı.');

        $this->assertSame(StepStatus::Running, $step->fresh()->status);
        $this->assertSame(CleaningStatus::InProgress, $cleaning->fresh()->status);
        $this->assertSame($ahmet->id, $this->lastEvent($cleaning, 'step.started')->actor_id);
    }

    public function test_an_assignee_operates_the_step_as_themselves(): void
    {
        // R-44: sahibi olmayan görevli de adımı yürütür; olayın aktörü isteği yapan kişidir.
        $ahmet = $this->operator();
        $mehmet = $this->operator('Mehmet');
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine(), [$mehmet]);
        $step = $this->stepOf($cleaning, 1);

        $this->actingAs($mehmet)
            ->post(route('cleanings.steps.start', [$cleaning, $step]))
            ->assertRedirect($this->showUrl($cleaning, $step))
            ->assertSessionHas('status', '1. adım başlatıldı.');

        $this->assertSame($mehmet->id, $this->lastEvent($cleaning, 'step.started')->actor_id);
    }

    public function test_running_step_is_paused(): void
    {
        $ahmet = $this->operator();
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine());
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        $this->travel(60)->seconds();
        $step = $this->stepOf($cleaning, 1);

        $this->actingAs($ahmet)
            ->post(route('cleanings.steps.pause', [$cleaning, $step]))
            ->assertRedirect($this->showUrl($cleaning, $step))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', '1. adım duraklatıldı.');

        $step->refresh();
        $this->assertSame(StepStatus::Paused, $step->status);
        $this->assertNull($step->openSlice()->first(), 'Duraklatılan adımın açık dilimi kalmamalı.');
    }

    public function test_paused_step_is_resumed(): void
    {
        $ahmet = $this->operator();
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine());
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        $this->travel(60)->seconds();
        $this->workflow()->pauseStep($ahmet, $this->stepOf($cleaning, 1));
        $this->travel(30)->seconds();
        $step = $this->stepOf($cleaning, 1);

        $this->actingAs($ahmet)
            ->post(route('cleanings.steps.resume', [$cleaning, $step]))
            ->assertRedirect($this->showUrl($cleaning, $step))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', '1. adım devam ediyor.');

        $step->refresh();
        $this->assertSame(StepStatus::Running, $step->status);
        $this->assertCount(2, $step->slices()->get());
    }

    public function test_completing_a_step_mid_phase_reports_only_the_step(): void
    {
        $ahmet = $this->operator();
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine([['steps' => 2]]));
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        $this->travel(60)->seconds();
        $step = $this->stepOf($cleaning, 1);

        $this->actingAs($ahmet)
            ->post(route('cleanings.steps.complete', [$cleaning, $step]))
            ->assertRedirect($this->showUrl($cleaning, $step))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', '1. adım tamamlandı.');

        $this->assertSame(StepStatus::Completed, $step->fresh()->status);
        $this->assertSame(PhaseStatus::InProgress, $this->phaseOf($cleaning, 1)->status);
    }

    public function test_completing_the_last_step_of_a_phase_reports_the_phase(): void
    {
        $ahmet = $this->operator();
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine([['steps' => 1], ['steps' => 1]]));
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        $this->travel(60)->seconds();
        $step = $this->stepOf($cleaning, 1);

        $this->actingAs($ahmet)
            ->post(route('cleanings.steps.complete', [$cleaning, $step]))
            ->assertRedirect($this->showUrl($cleaning, $step))
            ->assertSessionHas('status', '1. adım tamamlandı. 1. faz tamamlandı.');

        $this->assertSame(PhaseStatus::Completed, $this->phaseOf($cleaning, 1)->status);
        $this->assertSame(CleaningStatus::InProgress, $cleaning->fresh()->status);
    }

    public function test_completing_the_last_step_reports_the_completed_cleaning(): void
    {
        $ahmet = $this->operator();
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine([['steps' => 1], ['steps' => 1]]));
        $this->runStep($ahmet, $cleaning, 1);
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 2));
        $this->travel(60)->seconds();
        $step = $this->stepOf($cleaning, 2);

        $this->actingAs($ahmet)
            ->post(route('cleanings.steps.complete', [$cleaning, $step]))
            ->assertRedirect($this->showUrl($cleaning, $step))
            ->assertSessionHas('status', '2. adım tamamlandı. 2. faz tamamlandı. Temizlik tamamlandı.');

        $cleaning->refresh();
        $this->assertSame(CleaningStatus::Completed, $cleaning->status);
        $this->assertNotNull($cleaning->closed_at);
    }

    public function test_phase_below_minimum_asks_for_a_reason_and_the_same_request_with_one_succeeds(): void
    {
        // K-01: 15 dk minimum, 10 dk çalışıldı. Gerekçesiz istek geri döner (ekran o fazın
        // gerekçe alanını açar), aynı istek gerekçeyle tekrarlanınca faz sapmayla kapanır.
        $ahmet = $this->operator();
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine([['steps' => 1, 'min_seconds' => 900], ['steps' => 1]]));
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        $this->travel(600)->seconds();
        $step = $this->stepOf($cleaning, 1);
        $phase = $this->phaseOf($cleaning, 1);

        $response = $this->actingAs($ahmet)
            ->from($this->showUrl($cleaning))
            ->post(route('cleanings.steps.complete', [$cleaning, $step]), ['deviation_reason' => '']);

        $this->assertViolation($response, $cleaning, 'below_minimum_duration')
            ->assertSessionHas('violation.context.phase_id', $phase->id);
        $this->assertSame(StepStatus::Running, $step->fresh()->status);
        $this->assertSame(PhaseStatus::InProgress, $phase->fresh()->status);

        $this->actingAs($ahmet)
            ->from($this->showUrl($cleaning))
            ->post(route('cleanings.steps.complete', [$cleaning, $step]), ['deviation_reason' => 'Hat acil üretime alındı'])
            ->assertRedirect($this->showUrl($cleaning, $step))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', '1. adım tamamlandı. 1. faz tamamlandı.');

        $phase->refresh();
        $this->assertSame(PhaseStatus::Completed, $phase->status);
        $this->assertTrue($phase->below_minimum);
        $this->assertSame('Hat acil üretime alındı', $phase->deviation_reason);
    }

    public function test_deviation_reason_longer_than_the_limit_is_rejected(): void
    {
        $ahmet = $this->operator();
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine([['steps' => 1, 'min_seconds' => 900]]));
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        $this->travel(600)->seconds();
        $step = $this->stepOf($cleaning, 1);

        $this->actingAs($ahmet)
            ->from($this->showUrl($cleaning))
            ->post(route('cleanings.steps.complete', [$cleaning, $step]), ['deviation_reason' => str_repeat('a', 2001)])
            ->assertRedirect($this->showUrl($cleaning))
            ->assertSessionHasErrors(['deviation_reason' => 'gerekçe en fazla 2000 karakter olabilir.']);

        $this->assertSame(StepStatus::Running, $step->fresh()->status);
    }

    public function test_workers_are_replaced(): void
    {
        $ahmet = $this->operator();
        $mehmet = $this->operator('Mehmet');
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine(), [$mehmet]);
        $step = $this->stepOf($cleaning, 1);

        $this->actingAs($ahmet)
            ->put(route('cleanings.steps.workers', [$cleaning, $step]), ['user_ids' => [(string) $mehmet->id]])
            ->assertRedirect($this->showUrl($cleaning, $step))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Görevliler güncellendi.');

        $this->assertSame([$mehmet->id], $this->activeAssigneeIds($step));
    }

    /**
     * @return iterable<string, array{Closure(User): array<mixed>, string, string}>
     */
    public static function invalidWorkerLists(): iterable
    {
        yield 'boş liste' => [fn (User $user) => [], 'user_ids', 'görevliler zorunludur.'];
        yield 'liste değil' => [fn (User $user) => (string) $user->id, 'user_ids', 'görevliler bir liste olmalıdır.'];
        yield 'olmayan kullanıcı' => [fn (User $user) => [999999], 'user_ids.0', 'Seçilen görevli geçersiz.'];
        yield 'sayı değil' => [fn (User $user) => ['abc'], 'user_ids.0', 'görevli tam sayı olmalıdır.'];
        yield 'tekrar eden' => [fn (User $user) => [$user->id, $user->id], 'user_ids.1', 'görevli alanında tekrar eden değer var.'];
    }

    /**
     * @param  Closure(User): array<mixed>  $userIds
     */
    #[DataProvider('invalidWorkerLists')]
    public function test_invalid_worker_list_is_rejected(Closure $userIds, string $field, string $message): void
    {
        $ahmet = $this->operator();
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine());
        $step = $this->stepOf($cleaning, 1);

        $this->actingAs($ahmet)
            ->from($this->showUrl($cleaning))
            ->put(route('cleanings.steps.workers', [$cleaning, $step]), ['user_ids' => $userIds($ahmet)])
            ->assertRedirect($this->showUrl($cleaning))
            ->assertSessionHasErrors([$field => $message]);

        $this->assertSame([$ahmet->id], $this->activeAssigneeIds($step));
    }

    public function test_user_who_is_neither_owner_nor_assignee_is_not_allowed(): void
    {
        $ahmet = $this->operator();
        $stranger = $this->operator('Zeynep');
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine());
        $step = $this->stepOf($cleaning, 1);

        $response = $this->actingAs($stranger)
            ->from($this->showUrl($cleaning))
            ->post(route('cleanings.steps.start', [$cleaning, $step]));

        $this->assertViolation($response, $cleaning, 'not_allowed')
            ->assertSessionHasErrors(['workflow' => 'Bu işlem için yetkiniz yok: adımı başlatma.']);
        $this->assertSame(StepStatus::Pending, $step->fresh()->status);
        $this->assertSame(CleaningStatus::Created, $cleaning->fresh()->status);
    }

    public function test_manager_role_does_not_grant_step_operation(): void
    {
        // R-44: görmek çalıştırmak değildir; yönetici de sahibi ya da görevli değilse adım yürütemez.
        $ahmet = $this->operator();
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine());
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        $step = $this->stepOf($cleaning, 1);

        $response = $this->actingAs($this->manager())
            ->from($this->showUrl($cleaning))
            ->post(route('cleanings.steps.pause', [$cleaning, $step]));

        $this->assertViolation($response, $cleaning, 'not_allowed');
        $this->assertSame(StepStatus::Running, $step->fresh()->status);
    }

    public function test_steps_cannot_be_started_out_of_order(): void
    {
        $ahmet = $this->operator();
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine([['steps' => 2]]));
        $step = $this->stepOf($cleaning, 2);

        $response = $this->actingAs($ahmet)
            ->from($this->showUrl($cleaning))
            ->post(route('cleanings.steps.start', [$cleaning, $step]));

        $this->assertViolation($response, $cleaning, 'step_out_of_order');
        $this->assertSame(StepStatus::Pending, $step->fresh()->status);
    }

    public function test_worker_busy_on_another_cleaning_blocks_the_start(): void
    {
        // K-07: Mehmet M03'teki adımda çalışırken M04'teki adıma başlanamaz.
        $ahmet = $this->operator();
        $ayse = $this->operator('Ayşe');
        $mehmet = $this->operator('Mehmet');
        $first = $this->openCleaning($ahmet, $this->makeMachine(code: 'M03'), [$mehmet]);
        $this->workflow()->startStep($ahmet, $this->stepOf($first, 1));
        $second = $this->openCleaning($ayse, $this->makeMachine(code: 'M04'), [$mehmet]);
        $step = $this->stepOf($second, 1);

        $response = $this->actingAs($ayse)
            ->from($this->showUrl($second))
            ->post(route('cleanings.steps.start', [$second, $step]));

        $this->assertViolation($response, $second, 'worker_busy')
            ->assertSessionHas('violation.context.user_id', $mehmet->id)
            ->assertSessionHas('violation.context.blocking_cleaning_id', $first->id);
        $this->assertSame(StepStatus::Pending, $step->fresh()->status);
        $this->assertSame(CleaningStatus::Created, $second->fresh()->status);
    }

    public function test_paused_step_cannot_be_completed_directly(): void
    {
        $ahmet = $this->operator();
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine());
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        $this->workflow()->pauseStep($ahmet, $this->stepOf($cleaning, 1));
        $step = $this->stepOf($cleaning, 1);

        $response = $this->actingAs($ahmet)
            ->from($this->showUrl($cleaning))
            ->post(route('cleanings.steps.complete', [$cleaning, $step]));

        $this->assertViolation($response, $cleaning, 'invalid_transition');
        $this->assertSame(StepStatus::Paused, $step->fresh()->status);
    }
}
