<?php

namespace Tests\Feature\Admin\DefinitionChanges;

use App\Models\DefinitionChange;
use App\Models\User;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\TestCase;

/**
 * Tanım değişiklik günlüğü testlerinin ortak kurulumu (R-49): saat sabit (UTC 08:00 = İstanbul
 * 11:00). Oturum açılmadan kurulan veriler "Sistem" tarafından yapılmış sayılır; ekran işlemleri
 * `$this->admin` oturumuyla yapılır.
 */
abstract class DefinitionChangesTestCase extends TestCase
{
    use BuildsCleaningFixtures, InteractsWithCleaningWorkflow, RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.display_timezone' => 'Europe/Istanbul']);
        $this->at('08:00:00');
        $this->admin = $this->manager('Zeynep Arslan');
    }

    /**
     * Tanımın kendi satırları, eskiden yeniye.
     *
     * @return Collection<int, DefinitionChange>
     */
    protected function changesOf(Model $subject): Collection
    {
        return DefinitionChange::query()
            ->where('subject_type', DefinitionChange::typeOf($subject))
            ->where('subject_id', $subject->getKey())
            ->orderBy('id')
            ->get();
    }

    protected function lastChangeOf(Model $subject): DefinitionChange
    {
        $change = $this->changesOf($subject)->last();
        $this->assertNotNull($change, class_basename($subject).' için günlük satırı yok.');

        return $change;
    }

    /**
     * Satırın işlem adı ve alanları: ['updated', ['name' => ['Eski', 'Yeni']]]. MySQL JSON alan
     * sırasını korumadığı için alanlar ada göre sıralanır.
     *
     * @return array{0: string, 1: array<string, array{0: mixed, 1: mixed}>}
     */
    protected function summary(DefinitionChange $change): array
    {
        $changes = array_map(fn (array $entry) => [$entry['old'], $entry['new']], $change->fields ?? []);
        ksort($changes);

        return [$change->action->value, $changes];
    }

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $fields  alan => [eski, yeni]
     */
    protected function assertChange(string $action, array $fields, DefinitionChange $change): void
    {
        ksort($fields);
        $this->assertSame([$action, $fields], $this->summary($change));
    }

    /**
     * Tek alanın saklanan hali (old, new, *_label), anahtar sırasından bağımsız.
     *
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     */
    protected function assertEntry(array $expected, array $actual): void
    {
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
    }

    /**
     * @return list<string>
     */
    protected function actionsOf(Model $subject): array
    {
        return $this->changesOf($subject)->map(fn (DefinitionChange $change) => $change->action->value)->all();
    }

    protected function page(TestResponse $response): HTMLDocument
    {
        return HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR);
    }

    protected function text(Element $element): string
    {
        return trim(preg_replace('/\s+/u', ' ', $element->textContent));
    }
}
