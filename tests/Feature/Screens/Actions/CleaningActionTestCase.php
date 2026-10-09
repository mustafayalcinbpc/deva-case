<?php

namespace Tests\Feature\Screens\Actions;

use App\Models\Cleaning;
use App\Models\CleaningStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\TestCase;

/**
 * Kayıt detayındaki aksiyon uç noktalarının HTTP testleri için ortak kurulum. Detay sayfası
 * ayrı yazıldığı için yönlendirmeler takip edilmez; yalnızca hedef URL ve oturum doğrulanır.
 */
abstract class CleaningActionTestCase extends TestCase
{
    use BuildsCleaningFixtures, InteractsWithCleaningWorkflow, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('08:30:00');
    }

    /**
     * Detay sayfasının adresi; adım aksiyonlarında "Şimdi" kartının çapası (#now) eklenir.
     */
    protected function showUrl(Cleaning $cleaning, ?CleaningStep $step = null): string
    {
        $url = route('cleanings.show', $cleaning);

        return $step === null ? $url : "{$url}#now";
    }

    /**
     * İş kuralı ihlali merkezi olarak ele alınır: detay sayfasına geri dönülür, mesaj
     * `workflow` hatasıdır ve kural kodu `violation` flash'ındadır.
     */
    protected function assertViolation(TestResponse $response, Cleaning $cleaning, string $rule): TestResponse
    {
        return $response
            ->assertRedirect($this->showUrl($cleaning))
            ->assertSessionHasErrors('workflow')
            ->assertSessionHas('violation.rule', $rule)
            ->assertSessionMissing('status');
    }
}
