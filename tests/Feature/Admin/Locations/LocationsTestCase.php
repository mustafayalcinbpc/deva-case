<?php

namespace Tests\Feature\Admin\Locations;

use App\Models\Procedure;
use App\Models\ProcedureVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\TestCase;

/**
 * Tesis, hat ve makine yönetimi testlerinin ortak kurulumu: saat sabit, oturum yöneticide.
 */
abstract class LocationsTestCase extends TestCase
{
    use BuildsCleaningFixtures, InteractsWithCleaningWorkflow, RefreshDatabase;

    protected User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('08:00:00');
        $this->manager = $this->manager();
    }

    /**
     * Ortak yardımcıyla aynı sonuç, ama yayımlanmış versiyon değiştirilemediği için (K-15)
     * versiyon taslak olarak kurulur, sonra yayımlanır.
     *
     * @param  list<array{steps?: int, min_seconds?: int, include_gaps?: bool}>  $phases
     */
    protected function publishVersion(Procedure $procedure, array $phases, bool $materialRequired = false): ProcedureVersion
    {
        $version = $this->draftVersion($procedure, $phases, $materialRequired);
        $version->update(['published_at' => now()]);

        return $version;
    }

    /**
     * @param  list<array{steps?: int, min_seconds?: int, include_gaps?: bool}>  $phases
     */
    protected function draftVersion(Procedure $procedure, array $phases = [['steps' => 1]], bool $materialRequired = false): ProcedureVersion
    {
        $version = $procedure->versions()->create([
            'version' => ($procedure->versions()->max('version') ?? 0) + 1,
            'material_required' => $materialRequired,
            'published_at' => null,
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
                ]);
            }
        }

        return $version;
    }

    /**
     * Yalnızca taslak versiyonu olan prosedür (K-18: makineye atanamaz).
     */
    protected function draftProcedure(string $code = 'PRC-TASLAK'): Procedure
    {
        $procedure = Procedure::create(['code' => $code, 'name' => 'Taslak prosedür']);
        $this->draftVersion($procedure);

        return $procedure;
    }

    protected function publishedProcedure(string $code = 'PRC-YENI'): Procedure
    {
        $procedure = Procedure::create(['code' => $code, 'name' => "{$code} prosedürü"]);
        $this->publishVersion($procedure, [['steps' => 1]]);

        return $procedure;
    }
}
