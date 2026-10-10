<?php

namespace Tests\Feature\Admin\Catalog;

use App\Enums\CleaningType;
use App\Enums\WorkOrderStatus;
use App\Events\WorkOrderCompleted;
use App\Models\Facility;
use App\Models\Line;
use App\Models\Machine;
use App\Models\User;
use App\Models\WorkOrder;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\TestCase;

/**
 * Üretim iş emirleri (K-19): bir hatta, bir makineye ya da hiçbirine bağlıdır. Makineye bağlı iş
 * emri o makinenin hattına da bağlıdır. Kayıtlarda kullanılan üretim iş emrinin kodu ve bağlantısı değişmez.
 */
class WorkOrderManagementTest extends TestCase
{
    use BuildsCleaningFixtures, InteractsWithCleaningWorkflow, RefreshDatabase;

    private User $manager;

    private Machine $m01;

    private Machine $m02;

    private Line $h02;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('08:00:00');
        $this->manager = $this->manager('Zeynep');

        // IST / H01: M01, M02; IST / H02: M05.
        $this->m01 = $this->makeMachine(code: 'M01');
        $this->m02 = $this->makeMachine(code: 'M02');
        $this->h02 = Line::create(['facility_id' => $this->m01->line->facility_id, 'code' => 'H02', 'name' => 'Paketleme Hattı']);
    }

    public function test_relations_resolve_machine_and_line(): void
    {
        $byMachine = WorkOrder::create(['code' => 'IE-1', 'line_id' => $this->m01->line_id, 'machine_id' => $this->m01->id]);
        $byLine = WorkOrder::create(['code' => 'IE-2', 'line_id' => $this->h02->id]);
        $legacy = WorkOrder::create(['code' => 'IE-3', 'machine_id' => $this->m02->id]);
        $free = WorkOrder::create(['code' => 'IE-4']);

        $this->assertTrue($byMachine->machine->is($this->m01));
        $this->assertTrue($byMachine->line->is($this->m01->line));
        $this->assertNull($byLine->machine);
        $this->assertTrue($byLine->line->is($this->h02));
        $this->assertNull($free->machine);
        $this->assertNull($free->line);

        // Hattı boş eski veride hat makineden gelir.
        $this->assertNull($legacy->line);
        $this->assertTrue($legacy->boundLine()->is($this->m02->line));
        $this->assertSame('IST / H01 / M02', $legacy->locationCodes());
        $this->assertSame('IST / H02', $byLine->locationCodes());
        $this->assertNull($free->locationCodes());

        $cleaning = $this->workflow()->open($this->operator(), $this->m01, CleaningType::Planned, workOrder: $byMachine);
        $this->assertTrue($byMachine->cleanings()->sole()->is($cleaning));
    }

    public function test_list_shows_binding_and_usage_and_can_be_filtered(): void
    {
        $m05 = $this->makeMachine(code: 'M05');
        $m05->update(['line_id' => $this->h02->id]);

        $ie1 = WorkOrder::create(['code' => 'IE-1', 'line_id' => $this->m01->line_id, 'machine_id' => $this->m01->id, 'description' => 'Şurup dolum']);
        WorkOrder::create(['code' => 'IE-2', 'line_id' => $this->m01->line_id, 'description' => 'Şurup hazırlama']);
        WorkOrder::create(['code' => 'IE-3', 'line_id' => $this->h02->id, 'machine_id' => $m05->id, 'description' => 'Blister']);
        WorkOrder::create(['code' => 'IE-4']);
        $this->workflow()->open($this->operator(), $this->m01, CleaningType::Planned, workOrder: $ie1);

        $page = $this->page($this->actingAs($this->manager)->get(route('admin.work-orders.index'))
            ->assertOk()
            ->assertSee('<title>Üretim İş Emirleri', false));

        $this->assertSame([
            ['IE-1', 'Şurup dolum', 'Makine IST / H01 / M01', 'Planlandı', 'Planlanmadı', '1'],
            ['IE-2', 'Şurup hazırlama', 'Hat IST / H01', 'Planlandı', 'Planlanmadı', '0'],
            ['IE-3', 'Blister', 'Makine IST / H02 / M05', 'Planlandı', 'Planlanmadı', '0'],
            ['IE-4', '—', 'Bütün makineler', 'Planlandı', 'Planlanmadı', '0'],
        ], $this->rows($page));

        // Hat filtresi: hatta bağlı olanlar ve hattın makinelerine bağlı olanlar.
        $this->assertSame(['IE-1', 'IE-2'], $this->codes(['line_id' => $this->m01->line_id]));
        $this->assertSame(['IE-3'], $this->codes(['line_id' => $this->h02->id]));
        $this->assertSame(['IE-3'], $this->codes(['machine_id' => $m05->id]));
        $this->assertSame(['IE-1', 'IE-2'], $this->codes(['q' => 'şurup']));
        $this->assertSame(['IE-4'], $this->codes(['q' => 'IE-4']));
        $this->assertSame([], $this->codes(['q' => '%']));

        // Geçersiz filtre değerleri yok sayılır.
        $this->assertSame(['IE-1', 'IE-2', 'IE-3', 'IE-4'], $this->codes(['line_id' => 'abc', 'machine_id' => '-1']));

        $this->actingAs($this->manager)->get(route('admin.work-orders.index', ['q' => 'yok']))
            ->assertOk()
            ->assertSee('Filtreye uyan üretim iş emri yok.');
    }

    public function test_work_order_is_created_bound_to_a_machine_a_line_or_nothing(): void
    {
        $page = $this->page($this->actingAs($this->manager)->get(route('admin.work-orders.create'))->assertOk());
        $this->assertSame(
            ['Hat yok', 'H01 — Hat 1', 'H02 — Paketleme Hattı'],
            array_map(fn (Element $option) => $this->text($option), iterator_to_array($page->querySelectorAll('#line_id option'))),
        );
        $this->assertSame(
            ['Makine yok', 'M01 — Makine M01', 'M02 — Makine M02'],
            array_map(fn (Element $option) => $this->text($option), iterator_to_array($page->querySelectorAll('#machine_id option'))),
        );

        // Makine seçilince hat makineden gelir.
        $this->actingAs($this->manager)->post(route('admin.work-orders.store'), [
            'code' => 'IE-10', 'description' => 'Şurup dolum', 'line_id' => '', 'machine_id' => $this->m01->id,
        ])->assertRedirect(route('admin.work-orders.index'))->assertSessionHas('status', 'Üretim iş emri eklendi: IE-10');

        // Makine ve kendi hattı birlikte seçilebilir.
        $this->actingAs($this->manager)->post(route('admin.work-orders.store'), [
            'code' => 'IE-11', 'line_id' => $this->m02->line_id, 'machine_id' => $this->m02->id,
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->manager)->post(route('admin.work-orders.store'), [
            'code' => 'IE-12', 'line_id' => $this->h02->id, 'machine_id' => '',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->manager)->post(route('admin.work-orders.store'), [
            'code' => 'IE-13', 'description' => '',
        ])->assertSessionHasNoErrors();

        $this->assertSame([
            ['IE-10', 'Şurup dolum', $this->m01->line_id, $this->m01->id],
            ['IE-11', null, $this->m02->line_id, $this->m02->id],
            ['IE-12', null, $this->h02->id, null],
            ['IE-13', null, null, null],
        ], WorkOrder::query()->orderBy('code')->get()->map(fn (WorkOrder $order) => [$order->code, $order->description, $order->line_id, $order->machine_id])->all());

        // Kayıt açılırken: makineye bağlı yalnızca o makinede, hatta bağlı o hattın makinelerinde.
        $ie10 = WorkOrder::query()->where('code', 'IE-10')->sole();
        $this->assertTrue($ie10->isUsableFor($this->m01));
        $this->assertFalse($ie10->isUsableFor($this->m02));
    }

    public function test_machine_must_be_on_the_selected_line(): void
    {
        $this->actingAs($this->manager)->from(route('admin.work-orders.create'))->post(route('admin.work-orders.store'), [
            'code' => 'IE-20', 'line_id' => $this->h02->id, 'machine_id' => $this->m01->id,
        ])
            ->assertRedirect(route('admin.work-orders.create'))
            ->assertSessionHasErrors(['machine_id' => 'Seçilen makine, seçilen hatta değil.']);

        $this->assertSame(0, WorkOrder::count());
    }

    public function test_validation_messages_are_turkish(): void
    {
        WorkOrder::create(['code' => 'IE-1']);

        $this->actingAs($this->manager)->from(route('admin.work-orders.create'))->post(route('admin.work-orders.store'), [])
            ->assertSessionHasErrors(['code' => 'üretim iş emri kodu zorunludur.']);

        $this->actingAs($this->manager)->from(route('admin.work-orders.create'))->post(route('admin.work-orders.store'), [
            'code' => 'IE-1',
            'description' => str_repeat('a', 256),
            'line_id' => 999999,
            'machine_id' => 999999,
        ])->assertSessionHasErrors([
            'code' => 'üretim iş emri kodu zaten kullanılıyor.',
            'description' => 'açıklama en fazla 255 karakter olabilir.',
            'line_id' => 'Seçilen hat geçersiz.',
            'machine_id' => 'Seçilen makine geçersiz.',
        ]);

        $this->assertSame(1, WorkOrder::count());

        $page = $this->page($this->actingAs($this->manager)->from(route('admin.work-orders.create'))->followingRedirects()
            ->post(route('admin.work-orders.store'), ['code' => 'IE-1', 'line_id' => $this->h02->id, 'machine_id' => $this->m01->id])
            ->assertOk());
        $this->assertSame('üretim iş emri kodu zaten kullanılıyor.', $this->text($page->getElementById('code-error')));
        $this->assertSame('Seçilen makine, seçilen hatta değil.', $this->text($page->getElementById('machine_id-error')));
        $this->assertSame((string) $this->h02->id, $page->querySelector('#line_id option[selected]')->getAttribute('value'));
        $this->assertSame((string) $this->m01->id, $page->querySelector('#machine_id option[selected]')->getAttribute('value'));
    }

    public function test_unused_work_order_can_be_rebound(): void
    {
        $order = WorkOrder::create(['code' => 'IE-1', 'line_id' => $this->m01->line_id, 'machine_id' => $this->m01->id]);

        $page = $this->page($this->actingAs($this->manager)->get(route('admin.work-orders.edit', $order))->assertOk());
        $this->assertSame((string) $this->m01->id, $page->querySelector('#machine_id option[selected]')->getAttribute('value'));
        $this->assertFalse($page->getElementById('machine_id')->hasAttribute('disabled'));

        $this->actingAs($this->manager)->put(route('admin.work-orders.update', $order), [
            'code' => 'IE-1A', 'description' => 'Hat geneli', 'line_id' => $this->h02->id, 'machine_id' => '',
        ])->assertRedirect(route('admin.work-orders.index'))->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame(['IE-1A', 'Hat geneli', $this->h02->id, null], [$order->code, $order->description, $order->line_id, $order->machine_id]);
    }

    public function test_used_work_order_keeps_its_code_and_binding_but_description_can_change(): void
    {
        $order = WorkOrder::create(['code' => 'IE-1', 'line_id' => $this->m01->line_id, 'machine_id' => $this->m01->id, 'description' => 'Şurup']);
        $this->workflow()->open($this->operator(), $this->m01, CleaningType::Planned, workOrder: $order);

        $page = $this->page($this->actingAs($this->manager)->get(route('admin.work-orders.edit', $order))->assertOk());
        $this->assertTrue($page->getElementById('code')->hasAttribute('readonly'));
        $this->assertTrue($page->getElementById('machine_id')->hasAttribute('disabled'));
        $this->assertSame((string) $this->m01->id, $page->querySelector('input[type="hidden"][name="machine_id"]')->getAttribute('value'));
        $this->assertStringContainsString('1 temizlik kaydında kullanıldı', $this->text($page->querySelector('.work-order-form__locked')));

        $this->actingAs($this->manager)->from(route('admin.work-orders.edit', $order))->put(route('admin.work-orders.update', $order), [
            'code' => 'IE-1', 'line_id' => $this->m02->line_id, 'machine_id' => $this->m02->id,
        ])->assertSessionHasErrors(['machine_id' => 'Bu üretim iş emri kayıtlarda kullanıldığı için kodu ve bağlantısı değiştirilemez; yalnızca açıklaması düzeltilebilir.']);

        $this->actingAs($this->manager)->from(route('admin.work-orders.edit', $order))->put(route('admin.work-orders.update', $order), [
            'code' => 'IE-9', 'line_id' => $this->m01->line_id, 'machine_id' => $this->m01->id,
        ])->assertSessionHasErrors(['code']);

        $this->actingAs($this->manager)->put(route('admin.work-orders.update', $order), [
            'code' => 'IE-1', 'description' => 'Şurup dolum', 'line_id' => $this->m01->line_id, 'machine_id' => $this->m01->id,
        ])->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame(['IE-1', 'Şurup dolum', $this->m01->line_id, $this->m01->id], [$order->code, $order->description, $order->line_id, $order->machine_id]);
    }

    public function test_used_legacy_work_order_without_line_can_still_be_described(): void
    {
        // Eski veride makineye bağlı üretim iş emrinin hattı boş olabilir; formun gönderdiği aynı bağlantıdır.
        $order = WorkOrder::create(['code' => 'IE-1', 'machine_id' => $this->m01->id]);
        $this->workflow()->open($this->operator(), $this->m01, CleaningType::Planned, workOrder: $order);

        $this->actingAs($this->manager)->put(route('admin.work-orders.update', $order), [
            'code' => 'IE-1', 'description' => 'Açıklama', 'line_id' => '', 'machine_id' => $this->m01->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame(['Açıklama', $this->m01->line_id], [$order->fresh()->description, $order->fresh()->line_id]);
    }

    public function test_new_record_form_lists_work_orders_with_the_same_labels(): void
    {
        $ankara = Facility::create(['code' => 'ANK', 'name' => 'Ankara Tesisi']);
        $ankaraLine = Line::create(['facility_id' => $ankara->id, 'code' => 'H01', 'name' => 'Dolum Hattı']);

        WorkOrder::create(['code' => 'IE-1', 'line_id' => $this->m01->line_id, 'machine_id' => $this->m01->id, 'description' => 'Şurup dolum']);
        WorkOrder::create(['code' => 'IE-2', 'line_id' => $ankaraLine->id]);
        WorkOrder::create(['code' => 'IE-3', 'machine_id' => $this->m02->id, 'description' => 'Eski kayıt']);
        WorkOrder::create(['code' => 'IE-4', 'description' => 'Genel']);

        $page = $this->page($this->actingAs($this->operator())->get(route('cleanings.create'))->assertOk());

        $options = array_map(
            fn (Element $option) => [$this->text($option), $option->getAttribute('data-machine-id'), $option->getAttribute('data-line-id')],
            iterator_to_array($page->querySelectorAll('#work_order_id option')),
        );

        $this->assertSame([
            ['Üretim iş emri yok', null, null],
            ['IE-1 — Şurup dolum (IST / H01 / M01)', (string) $this->m01->id, (string) $this->m01->line_id],
            ['IE-2 (ANK / H01)', '', (string) $ankaraLine->id],
            ['IE-3 — Eski kayıt (IST / H01 / M02)', (string) $this->m02->id, ''],
            ['IE-4 — Genel (bütün makineler)', '', ''],
        ], $options);
    }

    public function test_new_record_form_query_count_does_not_grow_with_work_orders(): void
    {
        $operator = $this->operator();
        WorkOrder::create(['code' => 'IE-1', 'line_id' => $this->m01->line_id, 'machine_id' => $this->m01->id]);

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $measure = function () use (&$queries, $operator): int {
            $queries = 0;
            $this->actingAs($operator)->get(route('cleanings.create'))->assertOk();

            return $queries;
        };

        $before = $measure();

        foreach (range(2, 9) as $i) {
            WorkOrder::create([
                'code' => "IE-{$i}",
                'machine_id' => [null, $this->m01->id, $this->m02->id][$i % 3],
                'line_id' => $i % 2 ? $this->h02->id : null,
            ]);
        }

        $this->assertSame($before, $measure(), 'Üretim iş emirleri ilişkileriyle birlikte sabit sayıda sorguyla yüklenir.');
    }

    public function test_product_and_planned_times_are_saved_in_display_timezone(): void
    {
        // Zamanlar gösterim saat diliminde girilir (Europe/Istanbul, UTC+3), UTC saklanır.
        $this->actingAs($this->manager)->post(route('admin.work-orders.store'), [
            'code' => 'IE-30', 'machine_id' => $this->m01->id, 'product' => 'Parasetamol şurup 150 ml',
            'planned_start_at' => '2026-10-12T06:00', 'planned_end_at' => '2026-10-14T18:30',
        ])->assertSessionHasNoErrors();

        $order = WorkOrder::query()->where('code', 'IE-30')->sole();
        $this->assertSame('Parasetamol şurup 150 ml', $order->product);
        $this->assertSame(WorkOrderStatus::Planned, $order->status);
        $this->assertSame('2026-10-12 03:00:00', $order->planned_start_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-14 15:30:00', $order->planned_end_at->utc()->format('Y-m-d H:i:s'));

        $page = $this->page($this->actingAs($this->manager)->get(route('admin.work-orders.edit', $order))->assertOk());
        $this->assertSame('2026-10-12T06:00', $page->getElementById('planned_start_at')->getAttribute('value'));
        $this->assertSame('2026-10-14T18:30', $page->getElementById('planned_end_at')->getAttribute('value'));

        $row = $this->rows($this->page($this->actingAs($this->manager)->get(route('admin.work-orders.index'))))[0];
        $this->assertSame(['IE-30', '— Ürün: Parasetamol şurup 150 ml', 'Planlandı', '12 Ekim 06:00 – 14 Ekim 18:30'], [$row[0], $row[1], $row[3], $row[4]]);
    }

    public function test_planned_end_cannot_be_before_start(): void
    {
        $this->actingAs($this->manager)->from(route('admin.work-orders.create'))->post(route('admin.work-orders.store'), [
            'code' => 'IE-31', 'planned_start_at' => '2026-10-12T06:00', 'planned_end_at' => '2026-10-11T06:00',
        ])->assertSessionHasErrors(['planned_end_at' => 'planlanan bitiş, planlanan başlangıç tarihi ya da sonrası olmalıdır.']);

        $this->actingAs($this->manager)->from(route('admin.work-orders.create'))->post(route('admin.work-orders.store'), [
            'code' => 'IE-31', 'planned_start_at' => '12.10.2026 06:00',
        ])->assertSessionHasErrors(['planned_start_at']);

        $this->assertSame(0, WorkOrder::count());
    }

    public function test_form_does_not_change_status(): void
    {
        $order = WorkOrder::create(['code' => 'IE-1']);

        $this->actingAs($this->manager)->put(route('admin.work-orders.update', $order), [
            'code' => 'IE-1', 'status' => WorkOrderStatus::Completed->value,
        ])->assertSessionHasNoErrors();

        $this->assertSame(WorkOrderStatus::Planned, $order->fresh()->status);
    }

    public function test_status_buttons_move_the_work_order_through_production_to_completion(): void
    {
        $order = WorkOrder::create(['code' => 'IE-1', 'machine_id' => $this->m01->id, 'line_id' => $this->m01->line_id]);

        $page = $this->page($this->actingAs($this->manager)->get(route('admin.work-orders.edit', $order))->assertOk());
        $this->assertNotNull($page->querySelector('form[action="'.route('admin.work-orders.start', $order).'"]'));
        $this->assertNotNull($page->querySelector('form[action="'.route('admin.work-orders.complete', $order).'"]'));

        $this->actingAs($this->manager)->from(route('admin.work-orders.index'))->post(route('admin.work-orders.start', $order))
            ->assertRedirect(route('admin.work-orders.index'))
            ->assertSessionHas('status', 'IE-1 üretime alındı.');
        $this->assertSame(WorkOrderStatus::InProduction, $order->fresh()->status);

        $this->at('14:30:00');
        $this->actingAs($this->manager)->from(route('admin.work-orders.index'))->post(route('admin.work-orders.complete', $order))
            ->assertRedirect(route('admin.work-orders.index'))
            ->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame(WorkOrderStatus::Completed, $order->status);
        $this->assertSame('2026-10-09 14:30:00', $order->completed_at->format('Y-m-d H:i:s'));

        // Tamamlanmış iş emrinin durumu değişmez; düğmeler gösterilmez.
        $this->actingAs($this->manager)->from(route('admin.work-orders.index'))->post(route('admin.work-orders.complete', $order))
            ->assertSessionHasErrors(['status' => 'IE-1 Tamamlandı durumunda; Tamamlandı durumuna geçirilemez.']);
        $page = $this->page($this->actingAs($this->manager)->get(route('admin.work-orders.index'))->assertOk());
        $this->assertNull($page->querySelector('.work-order-status-action'));
        $this->assertStringContainsString('Tamamlandı: 9 Ekim', $this->rows($page)[0][4]);
    }

    public function test_planned_work_order_can_be_completed_directly_and_announces_it_once(): void
    {
        Event::fake([WorkOrderCompleted::class]);
        $order = WorkOrder::create(['code' => 'IE-1']);

        $this->actingAs($this->manager)->post(route('admin.work-orders.complete', $order))->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('admin.work-orders.complete', $order))->assertSessionHasErrors('status');

        Event::assertDispatchedTimes(WorkOrderCompleted::class, 1);
        Event::assertDispatched(WorkOrderCompleted::class, fn (WorkOrderCompleted $event) => $event->workOrderId === $order->id);
    }

    public function test_list_can_be_filtered_by_status(): void
    {
        WorkOrder::create(['code' => 'IE-1']);
        WorkOrder::create(['code' => 'IE-2', 'status' => WorkOrderStatus::InProduction]);
        WorkOrder::create(['code' => 'IE-3', 'status' => WorkOrderStatus::Completed, 'completed_at' => now()]);

        $this->assertSame(['IE-2'], $this->codes(['status' => 'in_production']));
        $this->assertSame(['IE-3'], $this->codes(['status' => 'completed']));
        $this->assertSame(['IE-1', 'IE-2', 'IE-3'], $this->codes(['status' => 'yok']));
    }

    public function test_status_routes_are_for_managers_only(): void
    {
        $order = WorkOrder::create(['code' => 'IE-1']);

        $this->actingAs($this->operator())->post(route('admin.work-orders.start', $order))->assertForbidden();
        $this->actingAs($this->operator())->post(route('admin.work-orders.complete', $order))->assertForbidden();

        $this->assertSame(WorkOrderStatus::Planned, $order->fresh()->status);
    }

    // ---------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $query
     * @return list<string>
     */
    private function codes(array $query): array
    {
        $page = $this->page($this->actingAs($this->manager)->get(route('admin.work-orders.index', $query))->assertOk());

        return array_map(fn (array $row) => $row[0], $this->rows($page));
    }

    /**
     * @return list<list<string>>
     */
    private function rows(HTMLDocument $page): array
    {
        return array_map(
            fn (Element $row) => array_map(fn (Element $cell) => $this->text($cell), array_slice(iterator_to_array($row->querySelectorAll('td')), 0, 6)),
            iterator_to_array($page->querySelectorAll('tbody tr')),
        );
    }

    private function page(TestResponse $response): HTMLDocument
    {
        return HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR);
    }

    private function text(Element $element): string
    {
        return trim(preg_replace('/\s+/u', ' ', $element->textContent));
    }
}
