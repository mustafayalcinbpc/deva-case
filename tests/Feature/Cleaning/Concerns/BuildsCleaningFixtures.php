<?php

namespace Tests\Feature\Cleaning\Concerns;

use App\Enums\CleaningStatus;
use App\Enums\CleaningType;
use App\Models\Cleaning;
use App\Models\Facility;
use App\Models\Line;
use App\Models\Machine;
use App\Models\Material;
use App\Models\Procedure;
use App\Models\ProcedureVersion;
use App\Models\User;
use Illuminate\Support\Str;

trait BuildsCleaningFixtures
{
    /**
     * IST tesisi / H01 hattında bir makine ve ona bağlı, yayımlanmış v1 prosedürü oluşturur.
     *
     * @param  list<array{steps?: int, min_seconds?: int, include_gaps?: bool}>  $phases
     */
    protected function makeMachine(array $phases = [['steps' => 2]], bool $materialRequired = false, string $code = 'M03'): Machine
    {
        $facility = Facility::firstOrCreate(['code' => 'IST'], ['name' => 'İstanbul Tesisi']);
        $line = Line::firstOrCreate(['facility_id' => $facility->id, 'code' => 'H01'], ['name' => 'Hat 1']);
        $procedure = Procedure::create(['code' => "PRC-{$code}", 'name' => "{$code} temizlik prosedürü"]);

        $this->publishVersion($procedure, $phases, $materialRequired);

        return Machine::create([
            'line_id' => $line->id,
            'procedure_id' => $procedure->id,
            'code' => $code,
            'name' => "Makine {$code}",
        ]);
    }

    /**
     * Prosedürün bir sonraki versiyonunu yayımlar.
     *
     * @param  list<array{steps?: int, min_seconds?: int, include_gaps?: bool}>  $phases
     */
    protected function publishVersion(Procedure $procedure, array $phases, bool $materialRequired = false): ProcedureVersion
    {
        $version = $procedure->versions()->create([
            'version' => ($procedure->versions()->max('version') ?? 0) + 1,
            'material_required' => $materialRequired,
            'published_at' => now(),
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

    protected function makeMaterial(string $code = 'DET-01'): Material
    {
        return Material::create(['code' => $code, 'name' => "Malzeme {$code}"]);
    }

    protected function operator(string $name = 'Ahmet'): User
    {
        return User::factory()->create(['name' => $name]);
    }

    protected function manager(string $name = 'Yönetici'): User
    {
        return User::factory()->manager()->create(['name' => $name]);
    }

    /**
     * Workflow'u kullanmadan, doğrudan veritabanına ham bir temizlik kaydı yazar.
     * Yalnızca yardımcı servislerin (sayaç, olay zinciri) testleri içindir.
     */
    protected function makeCleaningRecord(Machine $machine, User $owner): Cleaning
    {
        $machine->loadMissing('line', 'procedure');

        return Cleaning::create([
            'record_no' => 'TEST-'.Str::upper(Str::random(8)),
            'type' => CleaningType::Planned,
            'status' => CleaningStatus::Created,
            'facility_id' => $machine->line->facility_id,
            'line_id' => $machine->line_id,
            'machine_id' => $machine->id,
            'procedure_version_id' => $machine->procedure->currentVersion()->id,
            'owner_id' => $owner->id,
        ]);
    }
}
