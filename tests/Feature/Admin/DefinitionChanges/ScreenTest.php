<?php

namespace Tests\Feature\Admin\DefinitionChanges;

use App\Models\DefinitionChange;
use App\Models\Material;
use App\Models\WorkOrder;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Değişiklik günlüğü ekranı (R-49): yalnızca yönetici görür; en yeni değişiklik üstte, okunur
 * Türkçe tür/işlem/alan adları, eski → yeni değerler ve tanımın sayfasına bağlantı. Tür, kişi,
 * işlem ve tarih aralığıyla süzülür; tanım sayfalarında kısa geçmiş ve süzülmüş günlüğe bağlantı.
 */
class ScreenTest extends DefinitionChangesTestCase
{
    public function test_only_managers_can_open_the_log(): void
    {
        $this->get(route('admin.definition-changes.index'))->assertRedirect(route('login'));

        $this->actingAs($this->operator())->get(route('admin.definition-changes.index'))->assertForbidden();

        $this->actingAs($this->admin)->get(route('admin.definition-changes.index'))
            ->assertOk()
            ->assertSee('<title>Değişiklik Günlüğü', false);

        // Menü öğesi Faz 0'da tanımlandı; route artık var.
        $this->get(route('dashboard'))->assertSee(route('admin.definition-changes.index'), false);
    }

    public function test_log_lists_changes_newest_first_with_readable_values_and_links(): void
    {
        $machine = $this->makeMachine(code: 'M01');
        $this->actingAs($this->admin);

        $this->at('09:00:00');
        $this->put(route('admin.machines.update', $machine), [
            'line_id' => $machine->line_id,
            'procedure_id' => $machine->procedure_id,
            'code' => 'M01',
            'name' => 'Dolum makinesi',
        ])->assertRedirect();
        $this->at('10:00:00');
        $this->post(route('admin.machines.retire', $machine))->assertRedirect();

        $page = $this->page($this->get(route('admin.definition-changes.index'))->assertOk());
        $rows = $this->rows($page);

        $this->assertSame(['9 Ekim 13:00', 'Zeynep Arslan', 'Makine IST / H01 / M01', 'Kullanımdan kaldırıldı'], array_slice($rows[0], 0, 4));
        $this->assertSame(['9 Ekim 12:00', 'Zeynep Arslan', 'Makine IST / H01 / M01', 'Güncellendi'], array_slice($rows[1], 0, 4));
        $this->assertSame('Sistem', $rows[2][1]);
        $this->assertSame(DefinitionChange::count(), count($rows));

        $retired = $page->querySelector('tbody tr');
        $this->assertSame('Kullanımda:', $this->text($retired->querySelector('.definition-change-diff__attribute')));
        $this->assertSame('eski değer Evet', $this->text($retired->querySelector('.definition-change-diff__old')));
        $this->assertSame('yeni değer Hayır', $this->text($retired->querySelector('.definition-change-diff__new')));
        $this->assertSame(route('admin.machines.show', $machine), $retired->querySelector('a.definition-change__label')->getAttribute('href'));

        // Oluşturma satırında yalnızca yeni değerler; yabancı anahtar kısa adıyla.
        $created = $page->querySelector('#definition-change-'.$this->changesOf($machine)->first()->id);
        $this->assertSame(
            ['Kod: M01', 'Ad: Makine M01', 'Hat: IST / H01', 'Prosedür: PRC-M01', 'Kullanımda: Evet'],
            array_map(fn (Element $item) => $this->text($item), iterator_to_array($created->querySelectorAll('.definition-change-diff__item'))),
        );
    }

    public function test_deleted_definitions_link_to_their_root_page(): void
    {
        $machine = $this->makeMachine(code: 'M01');
        $procedure = $machine->procedure;
        $this->actingAs($this->admin);

        $this->post(route('admin.procedures.versions.store', $procedure))->assertRedirect();
        $draft = $procedure->versions()->whereNull('published_at')->sole();
        $phase = $draft->phases()->sole();
        $this->post(route('admin.procedures.phases.store', [$procedure, $draft]), ['name' => 'Son durulama', 'min_duration_minutes' => 0])->assertRedirect();
        $added = $draft->phases()->where('name', 'Son durulama')->sole();
        $this->delete(route('admin.procedures.phases.destroy', [$procedure, $draft, $added]))->assertRedirect();

        $page = $this->page($this->get(route('admin.definition-changes.index', ['type' => 'procedure_phase']))->assertOk());

        $deletedRow = $page->querySelector('#definition-change-'.$this->lastChangeOf($added)->id);
        $this->assertSame('Prosedür fazı PRC-M01 v2 · Son durulama', $this->text($deletedRow->querySelectorAll('td')->item(2)));
        $this->assertSame(route('admin.procedures.show', $procedure), $deletedRow->querySelector('a.definition-change__label')->getAttribute('href'));

        // Var olan faz, versiyon sayfasındaki yerine bağlanır.
        $copiedRow = $page->querySelector('#definition-change-'.$this->lastChangeOf($phase)->id);
        $this->assertSame(
            route('admin.procedures.versions.show', [$procedure, $draft])."#phase-{$phase->id}",
            $copiedRow->querySelector('a.definition-change__label')->getAttribute('href'),
        );
    }

    public function test_filters_by_type_actor_action_and_definition(): void
    {
        $machine = $this->makeMachine(code: 'M01');
        $material = $this->makeMaterial('DET-01');
        $this->actingAs($this->admin);
        $this->post(route('admin.machines.retire', $machine))->assertRedirect();
        $this->post(route('admin.materials.deactivate', $material))->assertRedirect();
        $this->post(route('admin.procedures.versions.store', $machine->procedure))->assertRedirect();

        $ids = fn (array $query) => array_map(
            fn (Element $row) => (int) substr($row->getAttribute('id'), strlen('definition-change-')),
            iterator_to_array($this->page($this->get(route('admin.definition-changes.index', $query))->assertOk())->querySelectorAll('tbody tr')),
        );
        $expected = fn ($query) => $query->orderByDesc('occurred_at')->orderByDesc('id')->pluck('id')->all();

        $this->assertSame($expected(DefinitionChange::where('subject_type', 'machine')), $ids(['type' => 'machine']));
        $this->assertSame($expected(DefinitionChange::whereNull('actor_id')), $ids(['actor' => 'system']));
        $this->assertSame($expected(DefinitionChange::where('actor_id', $this->admin->id)), $ids(['actor' => $this->admin->id]));
        $this->assertSame($expected(DefinitionChange::where('action', 'deactivated')), $ids(['action' => 'deactivated']));
        $this->assertSame(
            $expected(DefinitionChange::where('subject_type', 'machine')->where('action', 'retired')->where('actor_id', $this->admin->id)),
            $ids(['type' => 'machine', 'action' => 'retired', 'actor' => $this->admin->id]),
        );

        // Bir prosedürün geçmişi: versiyon, faz ve adımlar dahil, başka prosedür hariç.
        $procedureIds = $ids(['root_type' => 'procedure', 'root_id' => $machine->procedure_id]);
        $this->assertSame($expected(DefinitionChange::ofDefinition('procedure', $machine->procedure_id)), $procedureIds);
        $this->assertEqualsCanonicalizing(
            ['procedure', 'procedure_version', 'procedure_phase', 'procedure_step'],
            DefinitionChange::whereIn('id', $procedureIds)->distinct()->pluck('subject_type')->all(),
        );

        // Geçersiz değerler yok sayılır.
        $this->assertSame($expected(DefinitionChange::query()), $ids(['type' => 'yok', 'actor' => 'kimse', 'action' => 'silindi', 'from' => '2026-13-40']));
    }

    public function test_date_range_uses_display_timezone_days(): void
    {
        $this->at('08:00:00', '2026-10-01');
        Material::create(['code' => 'A', 'name' => 'A']);
        // UTC 22:30 = İstanbul'da ertesi gün 01:30.
        $this->at('22:30:00', '2026-10-05');
        Material::create(['code' => 'B', 'name' => 'B']);
        $this->at('08:00:00', '2026-10-09');
        Material::create(['code' => 'C', 'name' => 'C']);

        $this->actingAs($this->admin);
        $labels = fn (array $query) => array_map(
            fn (Element $row) => $this->text($row->querySelector('.definition-change__label')),
            iterator_to_array($this->page($this->get(route('admin.definition-changes.index', ['type' => 'material', ...$query]))->assertOk())->querySelectorAll('tbody tr')),
        );

        $this->assertSame(['C', 'B', 'A'], $labels([]));
        $this->assertSame(['B'], $labels(['from' => '2026-10-06', 'to' => '2026-10-06']));
        $this->assertSame([], $labels(['from' => '2026-10-02', 'to' => '2026-10-05']));
        $this->assertSame(['C', 'B'], $labels(['from' => '2026-10-06']));
        $this->assertSame(['A'], $labels(['to' => '2026-10-05']));
        // Ters verilen aralık düzeltilir.
        $this->assertSame(['B', 'A'], $labels(['from' => '2026-10-08', 'to' => '2026-10-01']));

        $this->get(route('admin.definition-changes.index', ['type' => 'material', 'from' => '2026-10-02', 'to' => '2026-10-05']))
            ->assertSee('Filtreye uyan değişiklik yok.');
    }

    public function test_definition_scope_shows_its_name_and_page(): void
    {
        $machine = $this->makeMachine(code: 'M01');

        $page = $this->page($this->actingAs($this->admin)
            ->get(route('admin.definition-changes.index', ['root_type' => 'machine', 'root_id' => $machine->id]))
            ->assertOk());

        $scope = $page->querySelector('.definition-change-scope');
        $this->assertSame('Makine IST / H01 / M01 için değişiklikler gösteriliyor. Bütün değişiklikler', $this->text($scope));
        $this->assertSame(route('admin.machines.show', $machine), $scope->querySelector('a.definition-change-scope__label')->getAttribute('href'));
        // Filtre formu tanım kapsamını korur.
        $this->assertSame((string) $machine->id, $page->querySelector('form.definition-change-filters input[name=root_id]')->getAttribute('value'));
    }

    public function test_definition_pages_show_recent_history_and_link_to_the_filtered_log(): void
    {
        $machine = $this->makeMachine(code: 'M01');
        $material = $this->makeMaterial('DET-01');
        $workOrder = WorkOrder::create(['code' => 'IE-1', 'machine_id' => $machine->id, 'line_id' => $machine->line_id]);
        $user = $this->operator('Ahmet');
        $this->actingAs($this->admin);

        for ($i = 1; $i <= 6; $i++) {
            $this->at(sprintf('09:%02d:00', $i));
            $machine->update(['name' => "Makine {$i}"]);
        }

        $pages = [
            'machine' => [$machine->id, route('admin.machines.show', $machine)],
            'material' => [$material->id, route('admin.materials.edit', $material)],
            'work_order' => [$workOrder->id, route('admin.work-orders.edit', $workOrder)],
            'user' => [$user->id, route('admin.users.edit', $user)],
            'procedure' => [$machine->procedure_id, route('admin.procedures.show', $machine->procedure_id)],
            'facility' => [$machine->line->facility_id, route('admin.facilities.edit', $machine->line->facility_id)],
            'line' => [$machine->line_id, route('admin.lines.edit', $machine->line_id)],
        ];

        foreach ($pages as $type => [$id, $url]) {
            $history = $this->page($this->get($url)->assertOk())->querySelector('.definition-history');
            $this->assertNotNull($history, "{$type} sayfasında değişiklik geçmişi yok.");

            $count = DefinitionChange::ofDefinition($type, $id)->count();
            $this->assertCount(min($count, 5), $history->querySelectorAll('.definition-history__item'), $type);

            $more = $history->querySelector('a.definition-history__more');
            $this->assertSame(route('admin.definition-changes.index', ['root_type' => $type, 'root_id' => $id]), $more->getAttribute('href'));
            $this->assertStringContainsString("({$count})", $this->text($more));
        }

        // Makinede en yeni beş değişiklik, en yeni üstte.
        $machineHistory = $this->page($this->get(route('admin.machines.show', $machine)))->querySelector('.definition-history');
        $first = $machineHistory->querySelector('.definition-history__item');
        $this->assertStringContainsString('9 Ekim 12:06', $this->text($first->querySelector('.definition-history__meta')));
        $this->assertStringContainsString('Zeynep Arslan', $this->text($first->querySelector('.definition-history__meta')));
        $this->assertSame('yeni değer Makine 6', $this->text($first->querySelector('.definition-change-diff__new')));

        // Prosedürde alt tanımın hangisi olduğu ve sayfası yazılır.
        $procedureHistory = $this->page($this->get(route('admin.procedures.show', $machine->procedure_id)))->querySelector('.definition-history');
        $stepItem = $procedureHistory->querySelector('.definition-history__item');
        $this->assertSame('Prosedür versiyonu', $this->text($stepItem->querySelector('.definition-change__type')));
        $this->assertSame('Yayımlandı', $this->text($stepItem->querySelector('.definition-change-action')));
    }

    /**
     * @return list<list<string>>
     */
    private function rows(HTMLDocument $page): array
    {
        return array_map(
            fn (Element $row) => array_map(fn (Element $cell) => $this->text($cell), iterator_to_array($row->querySelectorAll('td'))),
            iterator_to_array($page->querySelectorAll('tbody tr')),
        );
    }
}
