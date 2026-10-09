<?php

namespace Tests\Feature\Admin\Procedures;

use App\Models\ProcedurePhase;
use App\Models\ProcedureStep;
use App\Models\ProcedureVersion;
use LogicException;

/**
 * K-15, R-13: yayımlanmış versiyon, fazları ve adımları model seviyesinde korunur; ekranı
 * atlayan kod da değiştiremez, silemez, ekleyemez. Taslak serbestçe düzenlenir ve bir kez
 * yayımlanır.
 */
class VersionImmutabilityTest extends ProcedureTestCase
{
    public function test_published_version_cannot_be_updated_or_deleted(): void
    {
        $version = $this->publishVersion($this->procedure(), [['steps' => 1]]);

        $this->assertThrows(fn () => $version->fresh()->update(['material_required' => true]), LogicException::class, 'Yayımlanmış prosedür versiyonu değiştirilemez (K-15).');
        $this->assertThrows(fn () => $version->fresh()->update(['published_at' => now()->addDay()]), LogicException::class);
        $this->assertThrows(fn () => $version->fresh()->update(['published_at' => null]), LogicException::class);
        $this->assertThrows(fn () => $version->fresh()->delete(), LogicException::class, 'Yayımlanmış prosedür versiyonu silinemez (K-15).');

        $fresh = $version->fresh();
        $this->assertFalse($fresh->material_required);
        $this->assertTrue($fresh->published_at->eq(now()));
    }

    public function test_scheduled_version_is_already_immutable(): void
    {
        $version = $this->publishVersion($this->procedure(), [['steps' => 1]], publishedAt: now()->addWeek());

        $this->assertThrows(fn () => $version->fresh()->update(['published_at' => now()->addMonth()]), LogicException::class);
        $this->assertThrows(fn () => $version->phases()->first()->update(['name' => 'Yeni']), LogicException::class);
    }

    public function test_phases_of_a_published_version_cannot_be_added_changed_or_deleted(): void
    {
        $version = $this->publishVersion($this->procedure(), [['steps' => 1, 'min_seconds' => 900]]);
        $phase = $version->phases()->first();

        $this->assertThrows(fn () => $phase->update(['min_duration_seconds' => 1200]), LogicException::class, 'Yayımlanmış prosedür versiyonunun fazı değiştirilemez (K-15).');
        $this->assertThrows(fn () => $phase->fresh()->delete(), LogicException::class, 'Yayımlanmış prosedür versiyonunun fazı silinemez (K-15).');
        $this->assertThrows(fn () => $version->phases()->create(['sequence' => 2, 'name' => 'Ek faz']), LogicException::class, 'Yayımlanmış prosedür versiyonuna faz eklenemez (K-15).');

        // Fazı yayımlanmış versiyona taşımak da eklemek sayılır.
        $draft = $this->draft($version->procedure, [['steps' => 1], ['steps' => 1]]);
        $draftPhase = $draft->phases()->where('sequence', 2)->sole();
        $this->assertThrows(fn () => $draftPhase->update(['procedure_version_id' => $version->id, 'sequence' => 5]), LogicException::class);

        $this->assertSame(1, $version->phases()->count());
        $this->assertSame(900, $phase->fresh()->min_duration_seconds);
    }

    public function test_steps_of_a_published_version_cannot_be_added_changed_or_deleted(): void
    {
        $version = $this->publishVersion($this->procedure(), [['steps' => 2]]);
        $phase = $version->phases()->first();
        $step = $phase->steps()->first();

        $this->assertThrows(fn () => $step->update(['description' => 'Yeni açıklama']), LogicException::class, 'Yayımlanmış prosedür versiyonunun adımı değiştirilemez (K-15).');
        $this->assertThrows(fn () => $step->fresh()->update(['media_path' => 'procedures/yeni.jpg']), LogicException::class);
        $this->assertThrows(fn () => $step->fresh()->delete(), LogicException::class, 'Yayımlanmış prosedür versiyonunun adımı silinemez (K-15).');
        $this->assertThrows(fn () => $phase->steps()->create(['sequence' => 3, 'title' => 'Ek adım']), LogicException::class, 'Yayımlanmış prosedür versiyonuna adım eklenemez (K-15).');

        $this->assertSame(2, $phase->steps()->count());
        $this->assertNull($step->fresh()->description);
        $this->assertNull($step->fresh()->media_path);
    }

    public function test_stale_draft_instance_cannot_change_a_version_published_meanwhile(): void
    {
        $draft = $this->draft($this->procedure(), [['steps' => 1]]);
        $stale = ProcedureVersion::find($draft->id);
        $stalePhase = ProcedurePhase::where('procedure_version_id', $draft->id)->first();

        $draft->update(['published_at' => now()]);

        $this->assertThrows(fn () => $stale->update(['material_required' => true]), LogicException::class);
        $this->assertThrows(fn () => $stale->delete(), LogicException::class);
        $this->assertThrows(fn () => $stalePhase->update(['name' => 'Yeni']), LogicException::class);
        $this->assertFalse($draft->fresh()->material_required);
    }

    public function test_draft_is_freely_editable_and_published_once(): void
    {
        $draft = $this->draft($this->procedure(), [['steps' => 2]]);
        $phase = $draft->phases()->first();
        $step = $phase->steps()->first();

        $draft->update(['material_required' => true]);
        $phase->update(['name' => 'Hazırlık', 'min_duration_seconds' => 600]);
        $step->update(['title' => 'Makineyi durdur', 'media_path' => 'procedures/durdur.jpg']);
        $phase->steps()->create(['sequence' => 3, 'title' => 'Ek adım']);
        $phase->steps()->where('sequence', 2)->first()->delete();
        $extra = $draft->phases()->create(['sequence' => 2, 'name' => 'Ek faz']);
        $extra->delete();

        $draft->update(['published_at' => now()]);

        $this->assertTrue($draft->fresh()->isPublished());
        $this->assertSame(['1. Hazırlık' => ['1. Makineyi durdur', '3. Ek adım']], $this->outline($draft));
        $this->assertSame(2, ProcedureStep::count());
    }
}
