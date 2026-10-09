<?php

namespace Tests\Feature\Screens\Actions;

use App\Enums\CleaningStatus;
use App\Enums\StepStatus;
use App\Models\Cleaning;
use App\Models\CleaningMaterial;
use App\Models\CleaningStep;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Bütün aksiyon uç noktalarında ortak davranış: oturum, kayda bağlı adım/malzeme (scoped
 * binding) ve JSON isteklerinin yanıtı.
 */
class ActionRoutingTest extends CleaningActionTestCase
{
    /**
     * @return iterable<string, array{string, Closure(Cleaning, CleaningStep, CleaningMaterial): string}>
     */
    public static function endpoints(): iterable
    {
        yield 'adımı başlat' => ['post', fn ($cleaning, $step) => route('cleanings.steps.start', [$cleaning, $step])];
        yield 'adımı duraklat' => ['post', fn ($cleaning, $step) => route('cleanings.steps.pause', [$cleaning, $step])];
        yield 'adıma devam et' => ['post', fn ($cleaning, $step) => route('cleanings.steps.resume', [$cleaning, $step])];
        yield 'adımı tamamla' => ['post', fn ($cleaning, $step) => route('cleanings.steps.complete', [$cleaning, $step])];
        yield 'görevlileri değiştir' => ['put', fn ($cleaning, $step) => route('cleanings.steps.workers', [$cleaning, $step])];
        yield 'malzeme ekle' => ['post', fn ($cleaning) => route('cleanings.materials.store', $cleaning)];
        yield 'malzemeyi geçersiz kıl' => ['post', fn ($cleaning, $step, $material) => route('cleanings.materials.void', [$cleaning, $material])];
        yield 'kaydı iptal et' => ['post', fn ($cleaning) => route('cleanings.cancel', $cleaning)];
    }

    /**
     * @param  Closure(Cleaning, CleaningStep, CleaningMaterial): string  $url
     */
    #[DataProvider('endpoints')]
    public function test_guest_is_redirected_to_login(string $method, Closure $url): void
    {
        $ahmet = $this->operator();
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine(), materials: [$this->entry($this->makeMaterial())]);

        $this->call($method, $url($cleaning, $this->stepOf($cleaning, 1), $cleaning->materials()->sole()))
            ->assertRedirect(route('login'));

        $this->assertSame(['cleaning.opened', 'material.added'], $this->eventTypes($cleaning));
    }

    /**
     * @return iterable<string, array{string, string, array<string, mixed>}>
     */
    public static function stepEndpoints(): iterable
    {
        yield 'adımı başlat' => ['post', 'cleanings.steps.start', []];
        yield 'adımı duraklat' => ['post', 'cleanings.steps.pause', []];
        yield 'adıma devam et' => ['post', 'cleanings.steps.resume', []];
        yield 'adımı tamamla' => ['post', 'cleanings.steps.complete', []];
        yield 'görevlileri değiştir' => ['put', 'cleanings.steps.workers', ['user_ids' => [1]]];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('stepEndpoints')]
    public function test_step_of_another_cleaning_is_not_found(string $method, string $route, array $payload): void
    {
        // Kullanıcı iki kaydın da sahibi; yine de adım URL'deki kayda ait değilse 404.
        $ahmet = $this->operator();
        $mine = $this->openCleaning($ahmet, $this->makeMachine(code: 'M03'));
        $other = $this->openCleaning($ahmet, $this->makeMachine(code: 'M04'));
        $foreignStep = $this->stepOf($other, 1);

        $this->actingAs($ahmet)
            ->call($method, route($route, [$mine, $foreignStep]), $payload)
            ->assertNotFound();

        $this->assertSame(StepStatus::Pending, $foreignStep->fresh()->status);
        $this->assertSame(CleaningStatus::Created, $other->fresh()->status);
    }

    public function test_material_of_another_cleaning_is_not_found(): void
    {
        $ahmet = $this->operator();
        $mine = $this->openCleaning($ahmet, $this->makeMachine(code: 'M03'));
        $other = $this->openCleaning($ahmet, $this->makeMachine(code: 'M04'), materials: [$this->entry($this->makeMaterial())]);
        $foreignItem = $other->materials()->sole();

        $this->actingAs($ahmet)
            ->post(route('cleanings.materials.void', [$mine, $foreignItem]), ['void_reason' => 'Yanlış lot girildi'])
            ->assertNotFound();

        $this->assertNull($foreignItem->fresh()->voided_at);
    }

    public function test_unknown_cleaning_is_not_found(): void
    {
        $this->actingAs($this->operator())
            ->post(route('cleanings.cancel', 999999), ['cancel_reason' => 'invalid_record', 'cancel_note' => 'Yok'])
            ->assertNotFound();
    }

    public function test_json_request_gets_the_rule_violation_as_422(): void
    {
        $ahmet = $this->operator();
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine());
        $step = $this->stepOf($cleaning, 1);

        $this->actingAs($this->operator('Zeynep'))
            ->postJson(route('cleanings.steps.start', [$cleaning, $step]))
            ->assertStatus(422)
            ->assertExactJson([
                'rule' => 'not_allowed',
                'message' => 'Bu işlem için yetkiniz yok: adımı başlatma.',
                'context' => ['action' => 'adımı başlatma'],
            ]);

        $this->assertSame(StepStatus::Pending, $step->fresh()->status);
    }

    public function test_json_request_below_minimum_carries_the_phase(): void
    {
        $ahmet = $this->operator();
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine([['steps' => 1, 'min_seconds' => 900]]));
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        $this->travel(600)->seconds();

        $this->actingAs($ahmet)
            ->postJson(route('cleanings.steps.complete', [$cleaning, $this->stepOf($cleaning, 1)]))
            ->assertStatus(422)
            ->assertJsonPath('rule', 'below_minimum_duration')
            ->assertJsonPath('context.phase_id', $this->phaseOf($cleaning, 1)->id)
            ->assertJsonPath('context.measured_seconds', 600)
            ->assertJsonPath('context.minimum_seconds', 900);
    }

    public function test_json_request_gets_validation_errors_in_turkish(): void
    {
        $ahmet = $this->operator();
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine());

        $this->actingAs($ahmet)
            ->postJson(route('cleanings.cancel', $cleaning), ['cancel_reason' => 'invalid_record'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cancel_note' => 'açıklama zorunludur.']);
    }
}
