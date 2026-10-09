<?php

namespace Tests\Feature\Admin\DefinitionChanges;

use App\Models\DefinitionChange;
use Closure;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Günlük yalnızca eklenir (R-46, R-47): satırlar veritabanında trigger'larla korunur, model de
 * değiştirmeye ve silmeye izin vermez. Oturum olmadan (konsol, seed) yapılan değişikliğin
 * işlemi yapanı yoktur; ekranda "Sistem" görünür.
 */
class ImmutabilityTest extends DefinitionChangesTestCase
{
    public function test_rows_cannot_be_updated_or_deleted_in_the_database(): void
    {
        $material = $this->makeMaterial('DET-01');
        $change = $this->lastChangeOf($material);

        $this->assertQueryFails(fn () => DB::table('definition_changes')->where('id', $change->id)->update(['action' => 'deleted']));
        $this->assertQueryFails(fn () => DB::table('definition_changes')->where('id', $change->id)->update(['actor_id' => $this->admin->id]));
        $this->assertQueryFails(fn () => DB::table('definition_changes')->where('id', $change->id)->delete());
        $this->assertQueryFails(fn () => DefinitionChange::query()->whereKey($change->id)->update(['subject_label' => 'BAŞKA']));

        $this->assertSame(['created', null, 'DET-01'], [
            DB::table('definition_changes')->where('id', $change->id)->value('action'),
            DB::table('definition_changes')->where('id', $change->id)->value('actor_id'),
            DB::table('definition_changes')->where('id', $change->id)->value('subject_label'),
        ]);
    }

    public function test_model_refuses_to_change_or_delete_a_row(): void
    {
        $change = $this->lastChangeOf($this->makeMaterial('DET-01'));

        try {
            $change->update(['subject_label' => 'BAŞKA']);
            $this->fail('Günlük satırı değiştirilebildi.');
        } catch (LogicException) {
        }

        try {
            $change->delete();
            $this->fail('Günlük satırı silinebildi.');
        } catch (LogicException) {
        }

        $this->assertSame('DET-01', $change->fresh()->subject_label);
    }

    public function test_changes_without_a_session_have_no_actor(): void
    {
        $material = $this->makeMaterial('DET-01');
        $this->actingAs($this->admin);
        $material->update(['name' => 'Deterjan']);

        [$created, $updated] = $this->changesOf($material)->all();
        $this->assertNull($created->actor_id);
        $this->assertSame('Sistem', $created->actorName());
        $this->assertSame($this->admin->id, $updated->actor_id);
        $this->assertSame('Zeynep Arslan', $updated->actorName());
    }

    public function test_seeded_definitions_are_recorded_with_the_system_as_actor(): void
    {
        $this->seed(DemoSeeder::class);

        $this->assertGreaterThan(0, DefinitionChange::query()->count());
        $this->assertSame(0, DefinitionChange::query()->whereNotNull('actor_id')->count());

        // Seed'in geçmişe tarihlediği iş işlemleri de kendi adıyla ve kendi zamanıyla görünür.
        $retired = DefinitionChange::query()->where('subject_type', 'machine')->where('action', 'retired')->sole();
        $this->assertSame('IST / H01 / M04', $retired->subject_label);
        $this->assertTrue($retired->occurred_at->isPast());
        $this->assertSame(1, DefinitionChange::query()->where('subject_type', 'user')->where('action', 'deactivated')->count());
        $this->assertGreaterThan(0, DefinitionChange::query()->where('action', 'published')->count());
    }

    private function assertQueryFails(Closure $statement): void
    {
        try {
            $statement();
        } catch (QueryException $exception) {
            $this->assertStringContainsString('definition_changes', $exception->getMessage());

            return;
        }

        $this->fail('Günlük satırı veritabanında değiştirilebildi ya da silinebildi.');
    }
}
