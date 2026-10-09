<?php

namespace Tests\Feature\Admin\Procedures;

use App\Models\Procedure;
use App\Models\ProcedureVersion;
use App\Models\User;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\TestCase;

/**
 * Prosedür yönetimi testlerinin ortak kurulumu: saat sabit (UTC 08:00 = İstanbul 11:00),
 * oturum yöneticide; taslak kurma ve sayfa okuma yardımcıları.
 */
abstract class ProcedureTestCase extends TestCase
{
    use BuildsCleaningFixtures, InteractsWithCleaningWorkflow, RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.display_timezone' => 'Europe/Istanbul']);
        $this->at('08:00:00');
        $this->admin = $this->manager();
        $this->actingAs($this->admin);
    }

    protected function procedure(string $code = 'PRC-TEST'): Procedure
    {
        return Procedure::create(['code' => $code, 'name' => "{$code} prosedürü"]);
    }

    /**
     * Yayımlanmamış bir sonraki versiyon (fazlar ve adımlar publishVersion ile aynı biçimde).
     *
     * @param  list<array{steps?: int, min_seconds?: int, include_gaps?: bool, step_attributes?: array<int, array<string, mixed>>}>  $phases
     */
    protected function draft(Procedure $procedure, array $phases = [['steps' => 2]], bool $materialRequired = false): ProcedureVersion
    {
        $version = $procedure->versions()->create([
            'version' => ($procedure->versions()->max('version') ?? 0) + 1,
            'material_required' => $materialRequired,
        ]);

        foreach (array_values($phases) as $index => $phase) {
            $procedurePhase = $version->phases()->create([
                'sequence' => $index + 1,
                'name' => 'Faz '.($index + 1),
                'min_duration_seconds' => $phase['min_seconds'] ?? 0,
                'include_gaps' => $phase['include_gaps'] ?? false,
            ]);

            for ($step = 1; $step <= ($phase['steps'] ?? 2); $step++) {
                $procedurePhase->steps()->create([
                    'sequence' => $step,
                    'title' => 'Faz '.($index + 1)." adım {$step}",
                    ...($phase['step_attributes'][$step] ?? []),
                ]);
            }
        }

        return $version;
    }

    /**
     * Versiyon sayfasının route parametreleri.
     *
     * @return list<mixed>
     */
    protected function editor(ProcedureVersion $version): array
    {
        return [$version->procedure_id, $version];
    }

    /**
     * Versiyonun faz adları ve adım başlıkları, sırasıyla.
     *
     * @return array<string, list<string>>
     */
    protected function outline(ProcedureVersion $version): array
    {
        return $version->phases()->with('steps')->get()
            ->mapWithKeys(fn ($phase) => ["{$phase->sequence}. {$phase->name}" => $phase->steps
                ->map(fn ($step) => "{$step->sequence}. {$step->title}")
                ->all()])
            ->all();
    }

    protected function page(TestResponse $response): HTMLDocument
    {
        return HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR);
    }

    protected function one(TestResponse $response, string $selector): Element
    {
        $element = $this->page($response)->querySelector($selector);
        $this->assertNotNull($element, "Sayfada {$selector} yok.");

        return $element;
    }

    protected function text(Element $element): string
    {
        return trim(preg_replace('/\s+/u', ' ', $element->textContent));
    }
}
