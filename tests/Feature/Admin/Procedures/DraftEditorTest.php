<?php

namespace Tests\Feature\Admin\Procedures;

use App\Models\ProcedurePhase;
use App\Models\ProcedureStep;
use App\Models\ProcedureVersion;

/**
 * Taslak versiyon: yeni taslak (kopya), malzeme zorunluluğu, fazlar ve adımlar (ekleme,
 * düzenleme, silme, sıra) ve taslağı silme (R-03–R-06, K-02, K-13, K-15). Bütün işlemler
 * JS gerektirmeyen sunucu tarafı formlardır.
 */
class DraftEditorTest extends ProcedureTestCase
{
    public function test_new_draft_copies_the_latest_version_with_media_paths(): void
    {
        $procedure = $this->procedure();
        $this->publishVersion($procedure, [['steps' => 1]]);
        $v2 = $this->publishVersion($procedure, [
            ['steps' => 2, 'min_seconds' => 900, 'include_gaps' => true, 'step_attributes' => [
                1 => ['description' => 'Kapakları sökün.', 'media_path' => 'procedures/sokme.jpg'],
                2 => ['media_path' => 'procedures/yikama.mp4'],
            ]],
            ['steps' => 1, 'min_seconds' => 300],
        ], materialRequired: true);

        $response = $this->post(route('admin.procedures.versions.store', $procedure));

        $draft = $procedure->versions()->whereNull('published_at')->sole();
        $response->assertRedirect(route('admin.procedures.versions.show', [$procedure, $draft]));
        $this->assertSame(3, $draft->version);
        $this->assertTrue($draft->material_required);
        $this->assertSame($this->outline($v2), $this->outline($draft));
        $this->assertSame(
            $this->phaseRows($v2),
            $this->phaseRows($draft),
        );
        $this->assertSame(
            $this->stepRows($v2),
            $this->stepRows($draft),
        );
        // Kopya ayrı satırlardır; yayımlanmış versiyon olduğu gibi kalır.
        $this->assertEmpty(array_intersect($v2->phases()->pluck('id')->all(), $draft->phases()->pluck('id')->all()));
        $this->assertSame(3, $v2->steps()->count());
    }

    public function test_only_one_draft_at_a_time(): void
    {
        $procedure = $this->procedure();
        $this->publishVersion($procedure, [['steps' => 1]]);
        $draft = $this->draft($procedure);

        $this->from(route('admin.procedures.show', $procedure))
            ->post(route('admin.procedures.versions.store', $procedure))
            ->assertRedirect(route('admin.procedures.show', $procedure))
            ->assertSessionHasErrors(['version' => 'Bu prosedürün zaten bir taslağı var (v2). Önce onu yayımlayın ya da silin.']);

        $this->assertSame([$draft->id], $procedure->versions()->whereNull('published_at')->pluck('id')->all());
    }

    public function test_draft_can_be_deleted_but_a_published_version_cannot(): void
    {
        $procedure = $this->procedure();
        $v1 = $this->publishVersion($procedure, [['steps' => 1]]);
        $draft = $this->draft($procedure, [['steps' => 2], ['steps' => 2]]);

        $this->delete(route('admin.procedures.versions.destroy', [$procedure, $draft]))
            ->assertRedirect(route('admin.procedures.show', $procedure))
            ->assertSessionHas('status', 'v2 taslağı silindi.');

        $this->assertNull(ProcedureVersion::find($draft->id));
        $this->assertSame(1, ProcedurePhase::count());
        $this->assertSame(1, ProcedureStep::count());

        $this->delete(route('admin.procedures.versions.destroy', [$procedure, $v1]))
            ->assertSessionHasErrors('version');
        $this->assertNotNull(ProcedureVersion::find($v1->id));

        // Taslak silindikten sonra yeni taslak yine en son versiyondan, aynı numarayla açılır.
        $this->post(route('admin.procedures.versions.store', $procedure));
        $this->assertSame(2, $procedure->versions()->whereNull('published_at')->sole()->version);
    }

    public function test_draft_of_a_procedure_without_versions_starts_empty(): void
    {
        $procedure = $this->procedure();

        $this->post(route('admin.procedures.versions.store', $procedure));

        $draft = $procedure->versions()->sole();
        $this->assertSame(1, $draft->version);
        $this->assertSame(0, $draft->phases()->count());
    }

    public function test_material_requirement_is_set_on_the_draft(): void
    {
        $procedure = $this->procedure();
        $draft = $this->draft($procedure);

        $this->put(route('admin.procedures.versions.update', [$procedure, $draft]), ['material_required' => '1'])
            ->assertRedirect(route('admin.procedures.versions.show', [$procedure, $draft]));
        $this->assertTrue($draft->fresh()->material_required);

        // İşaretsiz kutu gönderilmez: "hayır".
        $this->put(route('admin.procedures.versions.update', [$procedure, $draft]), []);
        $this->assertFalse($draft->fresh()->material_required);
    }

    public function test_phases_are_added_edited_and_validated(): void
    {
        $procedure = $this->procedure();
        $draft = $this->draft($procedure, [['steps' => 1]]);

        $this->post(route('admin.procedures.phases.store', [$procedure, $draft]), [
            'name' => ' Yıkama ',
            'min_duration_minutes' => '15',
            'include_gaps' => '1',
        ])->assertRedirectContains(route('admin.procedures.versions.show', [$procedure, $draft]).'#phase-');

        $phase = $draft->phases()->where('sequence', 2)->sole();
        $this->assertSame(['Yıkama', 900, true], [$phase->name, $phase->min_duration_seconds, $phase->include_gaps]);

        $this->get(route('admin.procedures.phases.edit', [$procedure, $draft, $phase]))->assertOk()
            ->assertSee('value="15"', false)
            ->assertSee('K-02');

        $this->put(route('admin.procedures.phases.update', [$procedure, $draft, $phase]), [
            'name' => 'Yıkama ve durulama',
            'min_duration_minutes' => '0',
        ]);
        $phase->refresh();
        $this->assertSame(['Yıkama ve durulama', 0, false], [$phase->name, $phase->min_duration_seconds, $phase->include_gaps]);

        $this->post(route('admin.procedures.phases.store', [$procedure, $draft]), ['name' => '', 'min_duration_minutes' => '-1'])
            ->assertSessionHasErrors(['name', 'min_duration_minutes']);
        $this->post(route('admin.procedures.phases.store', [$procedure, $draft]), ['name' => 'X', 'min_duration_minutes' => '2.5'])
            ->assertSessionHasErrors(['min_duration_minutes']);
        $this->put(route('admin.procedures.phases.update', [$procedure, $draft, $phase]), ['name' => 'X', 'min_duration_minutes' => '-5'])
            ->assertSessionHasErrors(['min_duration_minutes']);
        $this->assertSame(2, $draft->phases()->count());
        $this->assertSame(0, $phase->fresh()->min_duration_seconds);
    }

    public function test_deleting_a_phase_removes_its_steps_and_closes_the_gap(): void
    {
        $procedure = $this->procedure();
        $draft = $this->draft($procedure, [['steps' => 1], ['steps' => 2], ['steps' => 1]]);
        $second = $draft->phases()->where('sequence', 2)->sole();

        $this->delete(route('admin.procedures.phases.destroy', [$procedure, $draft, $second]))
            ->assertRedirect(route('admin.procedures.versions.show', [$procedure, $draft]));

        $this->assertSame([
            '1. Faz 1' => ['1. Faz 1 adım 1'],
            '2. Faz 3' => ['1. Faz 3 adım 1'],
        ], $this->outline($draft));
        $this->assertSame(2, ProcedureStep::count());
    }

    public function test_phases_are_reordered_up_and_down(): void
    {
        $procedure = $this->procedure();
        $draft = $this->draft($procedure, [['steps' => 1], ['steps' => 1], ['steps' => 1]]);
        [$first, $second, $third] = $draft->phases()->get()->all();

        $this->post(route('admin.procedures.phases.move', [$procedure, $draft, $third, 'up']))
            ->assertRedirect(route('admin.procedures.versions.show', [$procedure, $draft])."#phase-{$third->id}");
        $this->assertSame(['Faz 1', 'Faz 3', 'Faz 2'], $draft->phases()->pluck('name')->all());

        $this->post(route('admin.procedures.phases.move', [$procedure, $draft, $first, 'down']));
        $this->assertSame(['Faz 3', 'Faz 1', 'Faz 2'], $draft->phases()->pluck('name')->all());

        // Uçtaki faz yerinde kalır.
        $this->post(route('admin.procedures.phases.move', [$procedure, $draft, $third, 'up']));
        $this->post(route('admin.procedures.phases.move', [$procedure, $draft, $second, 'down']));
        $this->assertSame(['Faz 3', 'Faz 1', 'Faz 2'], $draft->phases()->pluck('name')->all());
        $this->assertSame([1, 2, 3], $draft->phases()->pluck('sequence')->all());

        $this->post(route('admin.procedures.phases.move', [$procedure, $draft, $first, 'sideways']))->assertNotFound();
    }

    public function test_steps_are_added_edited_deleted_and_reordered(): void
    {
        $procedure = $this->procedure();
        $draft = $this->draft($procedure, [['steps' => 2], ['steps' => 1]]);
        $phase = $draft->phases()->where('sequence', 1)->sole();

        $this->get(route('admin.procedures.steps.create', [$procedure, $draft, $phase]))->assertOk()->assertSee('Adım ekle');
        $this->post(route('admin.procedures.steps.store', [$procedure, $draft, $phase]), [
            'title' => 'Kuru temizlik yap',
            'description' => "Tozu vakumla alın.\nBezle silin.",
        ])->assertRedirectContains('#step-');

        $added = $phase->steps()->where('sequence', 3)->sole();
        $this->assertSame(['Kuru temizlik yap', "Tozu vakumla alın.\nBezle silin.", null], [$added->title, $added->description, $added->media_path]);

        $this->get(route('admin.procedures.steps.edit', [$procedure, $draft, $phase, $added]))->assertOk()->assertSee('value="Kuru temizlik yap"', false);
        $this->put(route('admin.procedures.steps.update', [$procedure, $draft, $phase, $added]), ['title' => 'Kuru temizlik', 'description' => ''])
            ->assertRedirect(route('admin.procedures.versions.show', [$procedure, $draft])."#step-{$added->id}");
        $this->assertSame(['Kuru temizlik', null], [$added->fresh()->title, $added->fresh()->description]);

        $this->post(route('admin.procedures.steps.move', [$procedure, $draft, $phase, $added, 'up']));
        $this->assertSame(['1. Faz 1 adım 1', '2. Kuru temizlik', '3. Faz 1 adım 2'], $this->outline($draft)['1. Faz 1']);

        $first = $phase->steps()->where('sequence', 1)->sole();
        $this->delete(route('admin.procedures.steps.destroy', [$procedure, $draft, $phase, $first]))
            ->assertRedirect(route('admin.procedures.versions.show', [$procedure, $draft])."#phase-{$phase->id}");
        $this->assertSame([
            '1. Faz 1' => ['1. Kuru temizlik', '2. Faz 1 adım 2'],
            '2. Faz 2' => ['1. Faz 2 adım 1'],
        ], $this->outline($draft));

        $this->post(route('admin.procedures.steps.store', [$procedure, $draft, $phase]), ['title' => ''])->assertSessionHasErrors('title');
        $this->assertSame(2, $phase->steps()->count());
    }

    public function test_editor_shows_forms_for_a_draft_and_none_for_a_published_version(): void
    {
        $procedure = $this->procedure();
        $v1 = $this->publishVersion($procedure, [['steps' => 2]]);
        $draft = $this->draft($procedure, [['steps' => 2], ['steps' => 0]]);

        $editor = $this->get(route('admin.procedures.versions.show', [$procedure, $draft]))->assertOk();
        $this->one($editor, 'form[action="'.route('admin.procedures.phases.store', [$procedure, $draft]).'"]');
        $this->one($editor, 'form[action="'.route('admin.procedures.versions.publish', [$procedure, $draft]).'"]');
        $this->assertCount(2, $this->page($editor)->querySelectorAll('.procedure-phase'));
        $this->assertStringContainsString('Taslak', $this->text($this->one($editor, '.status-badge')));
        $this->assertStringContainsString('“Faz 2” fazına en az bir adım ekleyin.', $this->text($this->one($editor, '.procedure-publish__blockers')));

        // Uçtaki taşıma düğmeleri pasif.
        $firstPhase = $draft->phases()->first();
        $up = $this->one($editor, 'form[action="'.route('admin.procedures.phases.move', [$procedure, $draft, $firstPhase, 'up']).'"] button');
        $this->assertTrue($up->hasAttribute('disabled'));

        $published = $this->get(route('admin.procedures.versions.show', [$procedure, $v1]))->assertOk();
        $this->assertNull($this->page($published)->querySelector('form[action*="/phases"]'));
        $this->assertNull($this->page($published)->querySelector('form[action$="/publish"]'));
        $this->assertStringContainsString('Yayında', $this->text($this->one($published, '.status-badge')));
        $published->assertSee('Faz 1 adım 2');
    }

    public function test_published_version_cannot_be_edited_through_the_screens(): void
    {
        $procedure = $this->procedure();
        $v1 = $this->publishVersion($procedure, [['steps' => 2], ['steps' => 1]]);
        $phase = $v1->phases()->first();
        $step = $phase->steps()->first();
        $before = $this->outline($v1);
        $back = route('admin.procedures.versions.show', [$procedure, $v1]);
        $message = 'v1 yayımlanmış; yayımlanmış versiyon değiştirilemez. Değişiklik için yeni taslak oluşturun (K-15).';

        $attempts = [
            fn () => $this->put(route('admin.procedures.versions.update', [$procedure, $v1]), ['material_required' => '1']),
            fn () => $this->post(route('admin.procedures.phases.store', [$procedure, $v1]), ['name' => 'Yeni', 'min_duration_minutes' => 1]),
            fn () => $this->put(route('admin.procedures.phases.update', [$procedure, $v1, $phase]), ['name' => 'Yeni', 'min_duration_minutes' => 1]),
            fn () => $this->delete(route('admin.procedures.phases.destroy', [$procedure, $v1, $phase])),
            fn () => $this->post(route('admin.procedures.phases.move', [$procedure, $v1, $phase, 'down'])),
            fn () => $this->post(route('admin.procedures.steps.store', [$procedure, $v1, $phase]), ['title' => 'Yeni']),
            fn () => $this->put(route('admin.procedures.steps.update', [$procedure, $v1, $phase, $step]), ['title' => 'Yeni']),
            fn () => $this->delete(route('admin.procedures.steps.destroy', [$procedure, $v1, $phase, $step])),
            fn () => $this->post(route('admin.procedures.steps.move', [$procedure, $v1, $phase, $step, 'down'])),
            fn () => $this->post(route('admin.procedures.versions.publish', [$procedure, $v1]), ['when' => 'now']),
            fn () => $this->get(route('admin.procedures.phases.edit', [$procedure, $v1, $phase])),
            fn () => $this->get(route('admin.procedures.steps.create', [$procedure, $v1, $phase])),
            fn () => $this->get(route('admin.procedures.steps.edit', [$procedure, $v1, $phase, $step])),
        ];

        foreach ($attempts as $attempt) {
            $this->from($back);
            $attempt()->assertRedirect($back)->assertSessionHasErrors(['version' => $message]);
        }

        $this->followingRedirects()->from($back)
            ->post(route('admin.procedures.phases.store', [$procedure, $v1]), ['name' => 'Yeni', 'min_duration_minutes' => 1])
            ->assertOk()
            ->assertSee($message);

        $this->assertSame($before, $this->outline($v1));
        $this->assertFalse($v1->fresh()->material_required);
        $this->assertTrue($v1->fresh()->published_at->eq(now()));
    }

    public function test_nested_records_must_belong_to_the_address(): void
    {
        $procedure = $this->procedure('PRC-A');
        $draft = $this->draft($procedure, [['steps' => 1]]);
        $other = $this->procedure('PRC-B');
        $otherDraft = $this->draft($other, [['steps' => 1]]);
        $otherPhase = $otherDraft->phases()->first();
        $otherStep = $otherPhase->steps()->first();
        $phase = $draft->phases()->first();

        $this->get(route('admin.procedures.versions.show', [$procedure, $otherDraft]))->assertNotFound();
        $this->get(route('admin.procedures.phases.edit', [$procedure, $draft, $otherPhase]))->assertNotFound();
        $this->delete(route('admin.procedures.phases.destroy', [$procedure, $draft, $otherPhase]))->assertNotFound();
        $this->get(route('admin.procedures.steps.edit', [$procedure, $draft, $phase, $otherStep]))->assertNotFound();
        $this->delete(route('admin.procedures.steps.destroy', [$procedure, $draft, $phase, $otherStep]))->assertNotFound();

        $this->assertNotNull($otherPhase->fresh());
        $this->assertNotNull($otherStep->fresh());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function phaseRows(ProcedureVersion $version): array
    {
        return $version->phases()->get()
            ->map(fn (ProcedurePhase $phase) => $phase->only(['sequence', 'name', 'min_duration_seconds', 'include_gaps']))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function stepRows(ProcedureVersion $version): array
    {
        return $version->phases()->with('steps')->get()
            ->flatMap(fn (ProcedurePhase $phase) => $phase->steps->map(fn (ProcedureStep $step) => [
                'phase' => $phase->sequence,
                ...$step->only(['sequence', 'title', 'description', 'media_path']),
            ]))
            ->all();
    }
}
