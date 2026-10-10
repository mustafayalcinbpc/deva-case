<?php

namespace Tests\Feature\Admin\DefinitionChanges;

use App\Models\DefinitionChange;
use App\Models\Facility;
use App\Models\Line;
use App\Models\Machine;
use App\Models\Material;
use App\Models\Procedure;
use App\Models\ProcedurePhase;
use App\Models\ProcedureStep;
use App\Models\User;
use App\Models\WorkOrder;

/**
 * Yönetim ekranlarından yapılan her tanım değişikliği günlüğe yazılır (R-49): işlemi yapan,
 * işlem ve alanların eski → yeni hali. Zaman damgaları gibi gürültü alanları yazılmaz.
 */
class RecordingTest extends DefinitionChangesTestCase
{
    public function test_facility_and_line_changes_are_recorded_with_actor_and_diff(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.facilities.store'), ['code' => 'ANK', 'name' => 'Ankara Tesisi'])
            ->assertRedirect();
        $facility = Facility::where('code', 'ANK')->firstOrFail();

        $this->at('08:30:00');
        $this->put(route('admin.facilities.update', $facility), ['code' => 'ANK', 'name' => 'Ankara Fabrikası'])->assertRedirect();

        [$created, $updated] = $this->changesOf($facility)->all();
        $this->assertChange('created', ['code' => [null, 'ANK'], 'name' => [null, 'Ankara Tesisi']], $created);
        $this->assertChange('updated', ['name' => ['Ankara Tesisi', 'Ankara Fabrikası']], $updated);
        $this->assertSame($this->admin->id, $updated->actor_id);
        $this->assertSame('ANK', $updated->subject_label);
        $this->assertSame(['facility', $facility->id], [$updated->root_type, $updated->root_id]);
        $this->assertMoment('2026-10-09 08:30:00', $updated->occurred_at);

        $this->post(route('admin.lines.store', $facility), ['code' => 'H05', 'name' => 'Hat 5'])->assertRedirect();
        $line = Line::where('code', 'H05')->firstOrFail();
        $this->put(route('admin.lines.update', $line), ['code' => 'H06', 'name' => 'Hat 5'])->assertRedirect();

        [$created, $updated] = $this->changesOf($line)->all();
        $this->assertSame('created', $created->action->value);
        $this->assertSame('ANK / H05', $created->subject_label);
        // Yabancı anahtarın o anki kısa adı da saklanır.
        $this->assertEntry(['old' => null, 'new' => $facility->id, 'new_label' => 'ANK'], $created->fields['facility_id']);
        $this->assertChange('updated', ['code' => ['H05', 'H06']], $updated);
        $this->assertSame('ANK / H06', $updated->subject_label);
    }

    public function test_machine_changes_are_recorded_with_related_definition_labels(): void
    {
        $machine = $this->makeMachine(code: 'M01');
        $other = $this->makeMachine(code: 'M02');

        $this->actingAs($this->admin)->put(route('admin.machines.update', $machine), [
            'line_id' => $machine->line_id,
            'procedure_id' => $other->procedure_id,
            'code' => 'M01',
            'name' => 'Dolum makinesi',
        ])->assertRedirect(route('admin.machines.show', $machine));

        $change = $this->lastChangeOf($machine);
        $this->assertChange('updated', [
            'procedure_id' => [$machine->procedure_id, $other->procedure_id],
            'name' => ['Makine M01', 'Dolum makinesi'],
        ], $change);
        $this->assertSame('PRC-M01', $change->fields['procedure_id']['old_label']);
        $this->assertSame('PRC-M02', $change->fields['procedure_id']['new_label']);
        $this->assertSame('IST / H01 / M01', $change->subject_label);
        $this->assertSame($this->admin->id, $change->actor_id);

        $this->post(route('admin.machines.store'), [
            'line_id' => $machine->line_id,
            'procedure_id' => $other->procedure_id,
            'code' => 'M09',
            'name' => 'Yeni makine',
        ])->assertRedirect();

        $created = $this->lastChangeOf(Machine::where('code', 'M09')->firstOrFail());
        $this->assertChange('created', [
            'line_id' => [null, $machine->line_id],
            'procedure_id' => [null, $other->procedure_id],
            'code' => [null, 'M09'],
            'name' => [null, 'Yeni makine'],
            'is_active' => [null, true],
        ], $created);
        $this->assertSame('IST / H01', $created->fields['line_id']['new_label']);
    }

    public function test_procedure_version_phase_and_step_changes_are_recorded_under_the_procedure(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.procedures.store'), ['code' => 'PRC-YENI', 'name' => 'Yeni prosedür'])
            ->assertRedirect();
        $procedure = Procedure::where('code', 'PRC-YENI')->firstOrFail();
        $version = $procedure->versions()->sole();

        $this->assertSame(['created'], $this->actionsOf($procedure));
        $this->assertSame(['created'], $this->actionsOf($version));
        $this->assertSame('PRC-YENI v1', $this->lastChangeOf($version)->subject_label);

        $this->put(route('admin.procedures.update', $procedure), ['code' => 'PRC-YENI', 'name' => 'Dolum hattı temizliği'])->assertRedirect();
        $this->assertChange('updated', ['name' => ['Yeni prosedür', 'Dolum hattı temizliği']], $this->lastChangeOf($procedure));

        // K-13: zorunluluk elle değil, beklenen malzeme listesinden türetilir.
        $this->post(route('admin.procedures.materials.store', [$procedure, $version]), ['material_id' => $this->makeMaterial('DET-01')->id, 'is_required' => '1'])->assertRedirect();
        $this->assertChange('updated', ['material_required' => [false, true]], $this->lastChangeOf($version));

        // Faz: ekleme, düzenleme.
        $this->post(route('admin.procedures.phases.store', [$procedure, $version]), ['name' => 'Ön yıkama', 'min_duration_minutes' => 5])->assertRedirect();
        $phase = ProcedurePhase::where('name', 'Ön yıkama')->firstOrFail();
        $this->put(route('admin.procedures.phases.update', [$procedure, $version, $phase]), [
            'name' => 'Ön durulama',
            'min_duration_minutes' => 10,
            'include_gaps' => '1',
        ])->assertRedirect();

        [$created, $updated] = $this->changesOf($phase)->all();
        $this->assertChange('created', [
            'procedure_version_id' => [null, $version->id],
            'sequence' => [null, 1],
            'name' => [null, 'Ön yıkama'],
            'min_duration_seconds' => [null, 300],
            'include_gaps' => [null, false],
        ], $created);
        $this->assertSame('PRC-YENI v1', $created->fields['procedure_version_id']['new_label']);
        $this->assertChange('updated', [
            'name' => ['Ön yıkama', 'Ön durulama'],
            'min_duration_seconds' => [300, 600],
            'include_gaps' => [false, true],
        ], $updated);
        $this->assertSame('PRC-YENI v1 · Ön durulama', $updated->subject_label);

        // Adım: ekleme, düzenleme, silme.
        $this->post(route('admin.procedures.steps.store', [$procedure, $version, $phase]), ['title' => 'Kapağı sök', 'description' => 'Dikkatli sökün'])->assertRedirect();
        $step = ProcedureStep::where('title', 'Kapağı sök')->firstOrFail();
        $this->put(route('admin.procedures.steps.update', [$procedure, $version, $phase, $step]), ['title' => 'Kapağı çıkar', 'description' => ''])->assertRedirect();
        $this->delete(route('admin.procedures.steps.destroy', [$procedure, $version, $phase, $step]))->assertRedirect();

        [$created, $updated, $deleted] = $this->changesOf($step)->all();
        $this->assertSame('created', $created->action->value);
        $this->assertSame('PRC-YENI v1 · Ön durulama · Kapağı sök', $created->subject_label);
        $this->assertChange('updated', [
            'title' => ['Kapağı sök', 'Kapağı çıkar'],
            'description' => ['Dikkatli sökün', null],
        ], $updated);
        // Silinen adımın son hali "eski" değer olarak kalır; boş alanlar yazılmaz.
        $this->assertChange('deleted', [
            'procedure_phase_id' => [$phase->id, null],
            'sequence' => [1, null],
            'title' => ['Kapağı çıkar', null],
        ], $deleted);
        $this->assertSame('PRC-YENI v1 · Ön durulama · Kapağı çıkar', $deleted->subject_label);

        // Versiyon, beklenen malzeme, faz ve adım değişiklikleri prosedürün geçmişindedir.
        $history = DefinitionChange::query()->ofDefinition('procedure', $procedure->id)->get();
        $this->assertCount(10, $history);
        $this->assertSame(DefinitionChange::query()->whereIn('subject_type', ['procedure', 'procedure_version', 'procedure_version_material', 'procedure_phase', 'procedure_step'])->count(), $history->count());
        $this->assertSame([$this->admin->id], $history->pluck('actor_id')->unique()->values()->all());

        // Taslak silinince fazı ve versiyonu da "silindi" olarak kalır.
        $this->delete(route('admin.procedures.versions.destroy', [$procedure, $version]))->assertRedirect();
        $this->assertSame(['created', 'updated', 'deleted'], $this->actionsOf($phase));
        $this->assertSame(['created', 'updated', 'deleted'], $this->actionsOf($version));
        $this->assertSame(['procedure', $procedure->id], [$this->lastChangeOf($version)->root_type, $this->lastChangeOf($version)->root_id]);
    }

    public function test_reordering_records_only_the_real_position_change(): void
    {
        $procedure = Procedure::create(['code' => 'PRC-SIRA', 'name' => 'Sıra']);
        $version = $procedure->versions()->create(['version' => 1, 'material_required' => false]);
        $first = $version->phases()->create(['sequence' => 1, 'name' => 'Birinci', 'min_duration_seconds' => 0, 'include_gaps' => false]);
        $second = $version->phases()->create(['sequence' => 2, 'name' => 'İkinci', 'min_duration_seconds' => 0, 'include_gaps' => false]);

        $this->actingAs($this->admin)
            ->post(route('admin.procedures.phases.move', [$procedure, $version, $second, 'up']))
            ->assertRedirect();

        // Benzersizlik için kullanılan geçici sıra (0) günlükte görünmez.
        $this->assertChange('updated', ['sequence' => [1, 2]], $this->lastChangeOf($first));
        $this->assertChange('updated', ['sequence' => [2, 1]], $this->lastChangeOf($second));
        $this->assertSame(['created', 'updated'], $this->actionsOf($second));
    }

    public function test_material_work_order_and_user_changes_are_recorded(): void
    {
        $machine = $this->makeMachine(code: 'M01');
        $this->actingAs($this->admin);

        $this->post(route('admin.materials.store'), ['code' => 'DET-09', 'name' => 'Deterjan'])->assertRedirect();
        $material = Material::where('code', 'DET-09')->firstOrFail();
        $this->put(route('admin.materials.update', $material), ['code' => 'DET-10', 'name' => 'Deterjan'])->assertRedirect();

        [$created, $updated] = $this->changesOf($material)->all();
        $this->assertChange('created', ['code' => [null, 'DET-09'], 'name' => [null, 'Deterjan'], 'is_active' => [null, true]], $created);
        $this->assertChange('updated', ['code' => ['DET-09', 'DET-10']], $updated);
        $this->assertSame('DET-10', $updated->subject_label);

        // Makineye bağlanan üretim iş emrinin hattı makineden gelir; ikisinin kısa adı da saklanır.
        $this->post(route('admin.work-orders.store'), ['code' => 'IE-1', 'machine_id' => $machine->id, 'description' => 'Parti 1'])->assertRedirect();
        $workOrder = WorkOrder::where('code', 'IE-1')->firstOrFail();
        $this->put(route('admin.work-orders.update', $workOrder), ['code' => 'IE-1', 'description' => 'Parti 2'])->assertRedirect();

        [$created, $updated] = $this->changesOf($workOrder)->all();
        $this->assertChange('created', [
            'code' => [null, 'IE-1'],
            'description' => [null, 'Parti 1'],
            'line_id' => [null, $machine->line_id],
            'machine_id' => [null, $machine->id],
            'status' => [null, 'planned'],
        ], $created);
        $this->assertSame('IST / H01', $created->fields['line_id']['new_label']);
        $this->assertSame('IST / H01 / M01', $created->fields['machine_id']['new_label']);
        $this->assertChange('updated', [
            'line_id' => [$machine->line_id, null],
            'machine_id' => [$machine->id, null],
            'description' => ['Parti 1', 'Parti 2'],
        ], $updated);
        $this->assertEntry(['old' => $machine->id, 'new' => null, 'old_label' => 'IST / H01 / M01'], $updated->fields['machine_id']);

        $this->post(route('admin.users.store'), [
            'name' => 'Ayşe Demir',
            'email' => 'ayse@demo.test',
            'role' => 'operator',
            'password' => 'gizli-sifre-123',
            'password_confirmation' => 'gizli-sifre-123',
        ])->assertRedirect();
        $user = User::where('email', 'ayse@demo.test')->firstOrFail();
        $this->put(route('admin.users.update', $user), ['name' => 'Ayşe Demir', 'email' => 'ayse@demo.test', 'role' => 'manager'])->assertRedirect();

        [$created, $updated] = $this->changesOf($user)->all();
        // Şifre oluşturma satırında da yazılmaz.
        $this->assertChange('created', [
            'name' => [null, 'Ayşe Demir'],
            'email' => [null, 'ayse@demo.test'],
            'role' => [null, 'operator'],
            'is_active' => [null, true],
        ], $created);
        $this->assertChange('updated', ['role' => ['operator', 'manager']], $updated);
        $this->assertSame('ayse@demo.test', $updated->subject_label);
        $this->assertSame([$this->admin->id], $this->changesOf($user)->pluck('actor_id')->unique()->values()->all());
    }

    public function test_saving_without_changes_or_with_only_timestamps_records_nothing(): void
    {
        $material = $this->makeMaterial('DET-01');
        $this->actingAs($this->admin);

        $this->put(route('admin.materials.update', $material), ['code' => 'DET-01', 'name' => 'Malzeme DET-01'])->assertRedirect();
        $material->touch();

        $this->assertSame(['created'], $this->actionsOf($material));
    }
}
