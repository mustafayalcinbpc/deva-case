<?php

namespace Tests\Feature\Reports\Concerns;

use App\Enums\CleaningType;
use App\Models\Cleaning;
use App\Models\Facility;
use App\Models\Line;
use App\Models\Machine;
use App\Models\Procedure;
use App\Models\User;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Testing\TestResponse;

/**
 * Rapor testlerinin ortak yardımcıları. Kayıtlar gerçek workflow ile, sunucu saati at()/travel
 * ile ilerletilerek oluşturulur; süreler bu yüzden saniyesi saniyesine bilinir.
 * BuildsCleaningFixtures ve InteractsWithCleaningWorkflow ile birlikte kullanılır.
 */
trait BuildsReportScenarios
{
    /**
     * Başka bir tesis / hatta, kendi prosedürüyle bir makine.
     *
     * @param  list<array{steps?: int, min_seconds?: int, include_gaps?: bool}>  $phases
     */
    protected function makeMachineAt(string $facilityCode, string $lineCode, string $code, array $phases = [['steps' => 1]]): Machine
    {
        $facility = Facility::firstOrCreate(['code' => $facilityCode], ['name' => "{$facilityCode} Tesisi"]);
        $line = Line::firstOrCreate(['facility_id' => $facility->id, 'code' => $lineCode], ['name' => "Hat {$lineCode}"]);
        $procedure = Procedure::create(['code' => "PRC-{$facilityCode}-{$code}", 'name' => "{$code} prosedürü"]);
        $this->publishVersion($procedure, $phases);

        return Machine::create([
            'line_id' => $line->id,
            'procedure_id' => $procedure->id,
            'code' => $code,
            'name' => "Makine {$code}",
        ]);
    }

    /**
     * Açılıştan tamamlanmaya kadar tek kişiyle çalışılan bir kayıt: her adım $secondsPerStep
     * sürer, adımlar arasında boşluk yoktur. Kayıt $closeAt (UTC) anında tamamlanır.
     */
    protected function completedCleaning(
        User $owner,
        Machine $machine,
        string $closeAt,
        int $secondsPerStep = 600,
        CleaningType $type = CleaningType::Planned,
    ): Cleaning {
        $steps = $machine->procedure->currentVersion()->phases()->withCount('steps')->get()->sum('steps_count');
        [$date, $time] = explode(' ', $closeAt);
        $start = $this->at($time, $date)->subSeconds($steps * $secondsPerStep + 60);

        $this->travelTo($start);
        $cleaning = $this->openCleaning($owner, $machine, type: $type);
        $this->travel(60)->seconds();
        $this->completeRemainingSteps($owner, $cleaning, $secondsPerStep);

        return $cleaning->refresh();
    }

    protected function page(TestResponse $response): HTMLDocument
    {
        return HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR);
    }

    protected function one(TestResponse $response, string $selector): Element
    {
        $element = $this->page($response)->querySelector($selector);
        $this->assertNotNull($element, "Sayfada '{$selector}' yok.");

        return $element;
    }

    /**
     * Seçiciye uyan öğelerin boşlukları sadeleştirilmiş metinleri.
     *
     * @return list<string>
     */
    protected function texts(TestResponse $response, string $selector): array
    {
        return array_map(
            fn (Element $element) => $this->text($element),
            iterator_to_array($this->page($response)->querySelectorAll($selector)),
        );
    }

    protected function text(Element $element): string
    {
        return trim(preg_replace('/\s+/u', ' ', $element->textContent));
    }
}
