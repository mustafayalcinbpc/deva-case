<?php

namespace Tests\Feature;

use App\Enums\CancelReason;
use App\Enums\CleaningStatus;
use App\Enums\CleaningTaskSource;
use App\Enums\CleaningTaskStatus;
use App\Enums\CleaningType;
use App\Enums\SliceEndReason;
use App\Enums\StepStatus;
use App\Enums\UserRole;
use App\Enums\WorkOrderStatus;
use App\Models\Cleaning;
use App\Models\CleaningEvent;
use App\Models\CleaningMaterial;
use App\Models\CleaningPhase;
use App\Models\CleaningPlan;
use App\Models\CleaningTask;
use App\Models\Facility;
use App\Models\Line;
use App\Models\Machine;
use App\Models\MaterialLot;
use App\Models\Procedure;
use App\Models\User;
use App\Models\WorkSlice;
use App\Services\Cleaning\CleaningEventRecorder;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Demo verisi (DemoSeeder): giriş yapılabilir kullanıcılar ve CleaningWorkflow üzerinden
 * oluşturulmuş, her durumu gösteren temizlik kayıtları.
 */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Demo verisi yüzlerce workflow işlemiyle oluştuğu için (birkaç saniye) bir kez yüklenir;
     * kontroller aşağıdaki yardımcılardadır ve her biri kendi mesajıyla başarısız olur.
     */
    public function test_demo_dataset_is_complete_and_consistent(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertClockWasReset();
        $this->assertDemoUsersMatchThePlan();
        $this->assertMasterDataIsComplete();
        $this->assertEveryCleaningStatusIsRepresented();
        $this->assertHistoryShowsPauseWorkerChangeAndDeviation();
        $this->assertProcedureVersionsAreUsedAsIntended();
        $this->assertUnplannedCleaningsHaveNoFieldReference();
        $this->assertMaterialsComeFromLots();
        $this->assertPlansAndTasksShowEveryTaskState();
        $this->assertEveryEventChainVerifies();
    }

    /**
     * K-13, K-14: prosedürler beklenen malzemeleri listeler; her malzeme satırı bir lottan gelir
     * ve lotun lot no / SKT kopyasını taşır. Biri geçmiş, biri kullanımdan kaldırılmış lot vardır.
     */
    private function assertMaterialsComeFromLots(): void
    {
        $filling = Procedure::where('code', 'PRC-DOL')->firstOrFail()->versions()->where('version', 1)->firstOrFail();
        $this->assertSame(
            [['DET-01', true], ['DEZ-02', true], ['DUR-03', false]],
            $filling->materials()->with('material')->get()->map(fn ($item) => [$item->material->code, $item->is_required])->all(),
            'Dolum prosedürünün beklediği malzemeler',
        );

        $this->assertSame(0, CleaningMaterial::whereNull('material_lot_id')->count(), 'Lotsuz malzeme satırı');
        foreach (CleaningMaterial::with('lot')->get() as $item) {
            $this->assertSame([$item->lot->lot_no, $item->lot->expiry_date->toDateString()], [$item->lot_no, $item->expiry_date->toDateString()]);
        }

        $this->assertTrue(MaterialLot::where('expiry_date', '<', now()->toDateString())->exists(), 'SKT\'si geçmiş lot');
        $this->assertTrue(MaterialLot::where('is_active', false)->exists(), 'Kullanımdan kaldırılmış lot');
    }

    /**
     * K-20, K-21, K-23, K-24: planlar görev üretir; demo açık (biri gecikmiş), ileride (biri
     * üretimdeki emrin tamamlanmasını bekliyor), kayda bağlı ve tamamlanmış görev içerir. Her
     * kullanımdaki planın etkin görevi vardır. Görevden açılan kayıt planlıdır.
     */
    private function assertPlansAndTasksShowEveryTaskState(): void
    {
        $this->assertSame(5, CleaningPlan::count());
        $this->assertSame(1, CleaningTask::where('status', CleaningTaskStatus::Done)->count(), 'Tamamlanan görev');
        $this->assertSame(1, CleaningTask::where('status', CleaningTaskStatus::InRecord)->count(), 'Kayda bağlı görev');
        $this->assertSame(4, CleaningTask::where('status', CleaningTaskStatus::Open)->count(), 'Açık görev');
        $this->assertSame(0, CleaningPlan::query()->active()->whereDoesntHave('activeTask')->count(), 'Etkin görevi olmayan plan');

        $open = CleaningTask::open()->with('triggerWorkOrder')->get();
        $this->assertSame(1, $open->filter(fn (CleaningTask $task) => $task->isOverdue(now()))->count(), 'Gecikmiş görev');
        $this->assertSame(3, $open->filter(fn (CleaningTask $task) => $task->isUpcoming(now()))->count(), 'İleride görev');
        $waiting = $open->sole(fn (CleaningTask $task) => $task->scheduled_at === null);
        $this->assertSame(['IE-2026-1058', WorkOrderStatus::InProduction], [$waiting->triggerWorkOrder->code, $waiting->triggerWorkOrder->status]);

        $fromWorkOrder = CleaningTask::where('source', CleaningTaskSource::WorkOrder)->whereNotNull('cleaning_id')->sole();
        $this->assertSame('IE-2026-1051', $fromWorkOrder->triggerWorkOrder->code);
        $this->assertSame('IE-2026-1056', $fromWorkOrder->workOrder->code);
        $this->assertSame(WorkOrderStatus::Completed, $fromWorkOrder->triggerWorkOrder->status);

        foreach (Cleaning::whereNotNull('cleaning_task_id')->get() as $cleaning) {
            $this->assertSame(CleaningType::Planned, $cleaning->type);
        }
    }

    private function assertDemoUsersMatchThePlan(): void
    {
        $expected = [
            'operator1@demo.test' => ['Ahmet Yılmaz', UserRole::Operator, true],
            'operator2@demo.test' => ['Mehmet Kaya', UserRole::Operator, true],
            'operator3@demo.test' => ['Ayşe Demir', UserRole::Operator, true],
            'operator4@demo.test' => ['Eski Personel', UserRole::Operator, false],
            'yonetici1@demo.test' => ['Zeynep Arslan', UserRole::Manager, true],
        ];

        $this->assertSame(count($expected), User::count());

        foreach ($expected as $email => [$name, $role, $active]) {
            $user = User::where('email', $email)->firstOrFail();

            $this->assertSame($name, $user->name, $email);
            $this->assertSame($role, $user->role, $email);
            $this->assertSame($active, $user->is_active, $email);
            $this->assertTrue(Hash::check('1234', $user->password), "{$email} şifresi '1234' değil.");
        }
    }

    private function assertMasterDataIsComplete(): void
    {
        $this->assertSame('İstanbul Tesisi', Facility::where('code', 'IST')->sole()->name);
        $this->assertSame(['H01', 'H02'], Line::orderBy('code')->pluck('code')->all());

        // K-18: her aktif makinenin yayımlanmış bir prosedürü var.
        foreach (Machine::where('is_active', true)->with('procedure')->get() as $machine) {
            $this->assertNotNull($machine->procedure?->currentVersion(), "{$machine->code} makinesinin prosedürü yok.");
        }

        // R-12: kullanımdan kaldırılmış makine, geçmiş kaydıyla birlikte duruyor.
        $inactive = Machine::where('is_active', false)->get();
        $this->assertCount(1, $inactive);
        $this->assertTrue(Cleaning::where('machine_id', $inactive->first()->id)->exists());

        // R-08, K-02: malzeme zorunluluğu ve boşluk ayarı prosedürler arasında farklı.
        $versions = Procedure::all()->map->currentVersion();
        $this->assertEqualsCanonicalizing([true, false], $versions->pluck('material_required')->unique()->values()->all());
        $includeGaps = $versions->flatMap(fn ($version) => $version->phases()->pluck('include_gaps'))->unique()->values()->all();
        $this->assertEqualsCanonicalizing([true, false], $includeGaps);

        // R-03 örneği: 5, 3 ve 4 adımlı üç faz.
        $filling = Procedure::where('code', 'PRC-DOL')->firstOrFail()->currentVersion();
        $this->assertSame([5, 3, 4], $filling->phases()->withCount('steps')->get()->pluck('steps_count')->all());
    }

    private function assertEveryCleaningStatusIsRepresented(): void
    {
        foreach (CleaningStatus::cases() as $status) {
            $this->assertTrue(Cleaning::where('status', $status)->exists(), "'{$status->value}' durumunda kayıt yok.");
        }

        $inProgress = Cleaning::where('status', CleaningStatus::InProgress)->with('steps')->get();

        // Bir kayıtta bir adım çalışıyor (açık dilim, iki görevli); diğerinde adım duraklatılmış.
        $running = $inProgress->filter(fn (Cleaning $cleaning) => $cleaning->steps->contains('status', StepStatus::Running));
        $paused = $inProgress->filter(fn (Cleaning $cleaning) => $cleaning->steps->contains('status', StepStatus::Paused));
        $this->assertCount(1, $running);
        $this->assertCount(1, $paused);
        $this->assertNotSame($running->first()->id, $paused->first()->id);

        $runningStep = $running->first()->steps->firstWhere('status', StepStatus::Running);
        $this->assertSame(2, $runningStep->openSlice()->firstOrFail()->workers()->count());
        $this->assertTrue($running->first()->steps->where('status', StepStatus::Completed)->isNotEmpty());

        // Başlamamış kayıt birkaç dakika önce açılmış; süresi dolmamış.
        $created = Cleaning::where('status', CleaningStatus::Created)->sole();
        $this->assertTrue($created->created_at->between(now()->subMinutes(10), now()));
        $this->assertNull($created->started_at);

        // K-09: sahibi başlamamış kaydını "hatalı kayıt" gerekçesiyle iptal etti.
        $this->assertTrue(Cleaning::where('status', CleaningStatus::Cancelled)
            ->where('cancel_reason', CancelReason::InvalidRecord)
            ->whereColumn('cancelled_by', 'owner_id')
            ->whereNull('started_at')
            ->exists());
    }

    private function assertHistoryShowsPauseWorkerChangeAndDeviation(): void
    {
        $this->assertTrue(WorkSlice::where('end_reason', SliceEndReason::Paused)
            ->whereHas('step.cleaning', fn ($query) => $query->where('status', CleaningStatus::Completed))
            ->exists(), 'Duraklatmalı tamamlanmış kayıt yok.');

        $this->assertTrue(WorkSlice::where('end_reason', SliceEndReason::WorkersChanged)
            ->whereHas('step.cleaning', fn ($query) => $query->where('status', CleaningStatus::Completed))
            ->exists(), 'Adım sırasında görevli değişikliği olan tamamlanmış kayıt yok.');

        // K-01: minimum süre altında kalan faz gerekçeyle kapanmış.
        $phase = CleaningPhase::where('below_minimum', true)->sole();
        $this->assertNotEmpty($phase->deviation_reason);
        $this->assertLessThan($phase->procedurePhase->min_duration_seconds, $phase->measured_seconds);
    }

    private function assertProcedureVersionsAreUsedAsIntended(): void
    {
        // R-11: dolum prosedürünün yıkama fazı v1'de 15 dk, v2'de 20 dk.
        $procedure = Procedure::where('code', 'PRC-DOL')->firstOrFail();
        [$v1, $v2] = $procedure->versions()->orderBy('version')->get()->all();

        $this->assertSame([1, 2], [$v1->version, $v2->version]);
        $this->assertTrue($v1->published_at->lt($v2->published_at));
        $this->assertTrue($v2->is($procedure->currentVersion()));
        $this->assertEquals(900, $v1->phases()->where('sequence', 2)->value('min_duration_seconds'));
        $this->assertEquals(1200, $v2->phases()->where('sequence', 2)->value('min_duration_seconds'));

        // K-15: v2 yayımlanmadan önce açılan kayıtlar v1, sonra açılanlar v2 ile.
        $cleanings = Cleaning::whereIn('procedure_version_id', [$v1->id, $v2->id])->get();

        foreach ($cleanings as $cleaning) {
            $expected = $cleaning->created_at->lt($v2->published_at) ? $v1 : $v2;
            $this->assertEquals($expected->id, $cleaning->procedure_version_id, $cleaning->record_no);
        }

        $this->assertEqualsCanonicalizing([$v1->id, $v2->id], $cleanings->pluck('procedure_version_id')->unique()->values()->all());

        // Aynı ölçülen süre v1'de minimumu geçer, v2'de sapma olur.
        $deviation = CleaningPhase::where('below_minimum', true)->sole();
        $this->assertEquals($v2->id, $deviation->cleaning->procedure_version_id);
        $this->assertTrue(CleaningPhase::query()
            ->whereHas('cleaning', fn ($query) => $query->where('procedure_version_id', $v1->id))
            ->where('sequence', 2)
            ->where('below_minimum', false)
            ->where('measured_seconds', $deviation->measured_seconds)
            ->exists());
    }

    private function assertUnplannedCleaningsHaveNoFieldReference(): void
    {
        $unplanned = Cleaning::where('type', CleaningType::Unplanned)->get();

        $this->assertNotEmpty($unplanned);
        $this->assertTrue($unplanned->every(fn (Cleaning $cleaning) => $cleaning->field_ref === null && $cleaning->started_at !== null));

        // K-17: planlı kayıtta saha defteri referansı ilk adımla üretilir.
        foreach (Cleaning::where('type', CleaningType::Planned)->get() as $cleaning) {
            $this->assertSame($cleaning->started_at !== null, $cleaning->field_ref !== null, $cleaning->record_no);
        }
    }

    private function assertEveryEventChainVerifies(): void
    {
        $recorder = app(CleaningEventRecorder::class);

        foreach (Cleaning::all() as $cleaning) {
            $this->assertTrue($cleaning->events()->exists(), "{$cleaning->record_no} için olay yok.");
            $this->assertTrue($recorder->verify($cleaning), "{$cleaning->record_no} olay zinciri doğrulanamadı.");
        }
    }

    public function test_demo_data_loads_just_after_midnight(): void
    {
        // Gece 00:30'da "bugün" biten iş emirlerinin planlanan bitişi henüz gelmemiştir; görevden
        // açılan demo kaydı yine de açılabilmeli (K-24: vakti gelmeyen görevden kayıt açılmaz).
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-11 00:30:00', 'Europe/Istanbul')->utc());

        $this->seed(DatabaseSeeder::class);

        $open = CleaningTask::open()->get();
        $this->assertSame(4, $open->count());
        $this->assertSame(1, $open->filter(fn (CleaningTask $task) => $task->isOverdue(now()))->count());
        $this->assertSame(1, CleaningTask::where('status', CleaningTaskStatus::InRecord)->count());
    }

    private function assertClockWasReset(): void
    {
        $this->assertFalse(CarbonImmutable::hasTestNow());
        $this->assertTrue(CleaningEvent::where('occurred_at', '>', now())->doesntExist());
    }
}
