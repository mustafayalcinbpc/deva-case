<?php

namespace Tests\Feature\Screens;

use App\Models\Cleaning;
use App\Models\Machine;
use App\Models\Material;
use App\Models\User;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\TestCase;

/**
 * Kayıt detayının Malzemeler sekmesi (K-12–K-14): prosedürün beklediği malzemeler ve girilen
 * lotlar, eksik zorunlu malzemelerin adıyla uyarısı (Şimdi kartında da) ve lot seçerek ekleme
 * formu. Lot no ve SKT lot kaydından gelir; elle girilmez.
 */
class CleaningMaterialsTabTest extends TestCase
{
    use BuildsCleaningFixtures, InteractsWithCleaningWorkflow, RefreshDatabase;

    private Material $detergent;

    private Material $disinfectant;

    private Material $acid;

    private Machine $machine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('08:00:00');

        $this->detergent = $this->makeMaterial('DET-01');
        $this->disinfectant = $this->makeMaterial('DEZ-02');
        $this->acid = $this->makeMaterial('DUR-03');
        $this->machine = $this->makeMachine(materials: [[$this->detergent, true], [$this->disinfectant, true], [$this->acid, false]]);
    }

    public function test_expected_materials_show_the_lots_entered_and_the_missing_ones_are_named(): void
    {
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $this->machine, materials: [$this->entry($this->detergent, 'DT-1')]);

        $response = $this->show($ahmet, $cleaning);

        $items = $this->page($response)->querySelectorAll('#materials .materials-expected__item');
        $this->assertSame([
            'DET-01 — Malzeme DET-01 Zorunlu Lot: DT-1',
            'DEZ-02 — Malzeme DEZ-02 Zorunlu Lot girilmedi',
            'DUR-03 — Malzeme DUR-03 İsteğe bağlı Lot girilmedi',
        ], array_map(fn (Element $item) => $this->text($item), iterator_to_array($items)));
        $this->assertTrue($items[1]->classList->contains('materials-expected__item--missing'));
        $this->assertFalse($items[2]->classList->contains('materials-expected__item--missing'), 'İsteğe bağlı malzeme eksik sayılmaz.');

        $expected = 'Zorunlu malzemeler için lot girilmeden ilk adım başlatılamaz: DEZ-02 Malzeme DEZ-02.';
        $this->assertStringContainsString($expected, $this->text($this->one($response, '#materials .materials-card__missing')));
        $this->assertStringContainsString($expected, $this->text($this->one($response, '#now .now-card__alert')));
        $this->assertTrue($this->one($response, 'details.material-form')->hasAttribute('open'));

        // Geçersiz kılınan giriş sayılmaz; geçerli lot girilince uyarılar kalkar.
        $this->workflow()->addMaterial($ahmet, $cleaning, $this->entry($this->disinfectant, 'DZ-1'));
        $response = $this->show($ahmet, $cleaning);
        $this->assertNull($this->page($response)->querySelector('#materials .materials-card__missing'));
        $this->assertNull($this->page($response)->querySelector('#now .now-card__alert'));
        $this->assertFalse($this->one($response, 'details.material-form')->hasAttribute('open'));

        $this->workflow()->voidMaterial($ahmet, $cleaning->materials()->where('lot_no', 'DZ-1')->sole(), 'Yanlış lot seçildi');
        $this->assertStringContainsString('DEZ-02', $this->text($this->one($this->show($ahmet, $cleaning), '#materials .materials-card__missing')));
    }

    public function test_add_form_offers_only_usable_lots_grouped_by_material(): void
    {
        // K-14: lot ve malzemesi kullanımda, SKT geçmemiş (bugün geçerli) lotlar; lot no ve SKT girilmez.
        $this->lot($this->detergent, 'DT-2', '2027-06-30');
        $this->lot($this->detergent, 'DT-1', '2027-01-31');
        $this->lot($this->detergent, 'DT-ESKI', '2026-10-08');
        $this->lot($this->disinfectant, 'DZ-1', '2026-10-09');
        $this->lot($this->disinfectant, 'DZ-GERI', '2027-12-31')->update(['is_active' => false]);
        $retired = $this->makeMaterial('ALK-04');
        $this->lot($retired, 'AL-1', '2027-12-31');
        $retired->update(['is_active' => false]);
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $this->machine);

        $form = $this->one($this->show($ahmet, $cleaning), '#materials form[action="'.route('cleanings.materials.store', $cleaning).'"]');

        $groups = [];
        foreach ($form->querySelectorAll('select[name="material_lot_id"] optgroup') as $group) {
            $groups[$group->getAttribute('label')] = array_map(fn (Element $option) => $this->text($option), iterator_to_array($group->querySelectorAll('option')));
        }
        $this->assertSame([
            'DET-01 — Malzeme DET-01' => ['DT-1 · SKT 31.01.2027', 'DT-2 · SKT 30.06.2027'],
            'DEZ-02 — Malzeme DEZ-02' => ['DZ-1 · SKT 09.10.2026'],
        ], $groups);
        $this->assertNull($form->querySelector('input[name="lot_no"], input[name="expiry_date"], select[name="material_id"]'));
    }

    public function test_without_usable_lots_the_form_explains_instead_of_offering_a_choice(): void
    {
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $this->machine);

        $form = $this->one($this->show($ahmet, $cleaning), '#materials .material-form');

        $this->assertNull($form->querySelector('select[name="material_lot_id"]'));
        $this->assertNull($form->querySelector('button[type="submit"]'));
        $this->assertStringContainsString('Lotları yönetici tanımlar.', $this->text($form->querySelector('.material-form__no-lots')));
    }

    public function test_validation_error_and_old_choice_are_shown_next_to_the_lot_select(): void
    {
        $lot = $this->lot($this->detergent, 'DT-1');
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $this->machine, materials: [$this->entry($this->detergent, 'DT-1'), $this->entry($this->disinfectant, 'DZ-1')]);

        $response = $this->actingAs($ahmet)
            ->withSession([
                'errors' => $this->sessionErrors(['material_lot_id' => ['Seçilen lot geçersiz.']]),
                '_old_input' => ['material_lot_id' => (string) $lot->id],
            ])
            ->get(route('cleanings.show', $cleaning))
            ->assertOk();

        $select = $this->one($response, '#material-lot-id');
        $this->assertTrue($select->classList->contains('is-invalid'));
        $this->assertSame('Seçilen lot geçersiz.', $this->text($this->one($response, '#material-lot-error')));
        $this->assertSame((string) $lot->id, $select->querySelector('option[selected]')->getAttribute('value'));
        $this->assertTrue($this->one($response, 'details.material-form')->hasAttribute('open'), 'Hata varsa form açık gelir.');
    }

    public function test_viewer_without_a_role_sees_the_list_but_no_form(): void
    {
        $this->lot($this->detergent, 'DT-1');
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $this->machine, materials: [$this->entry($this->detergent, 'DT-1')]);

        $response = $this->show($this->operator('Ayşe'), $cleaning);

        $this->assertNull($this->page($response)->querySelector('#materials form'));
        $this->assertStringContainsString('Lot: DT-1', $this->text($this->one($response, '#materials .materials-expected')));
    }

    private function show(User $viewer, Cleaning $cleaning): TestResponse
    {
        return $this->actingAs($viewer)->get(route('cleanings.show', $cleaning))->assertOk();
    }

    private function page(TestResponse $response): HTMLDocument
    {
        return HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR);
    }

    private function one(TestResponse $response, string $selector): Element
    {
        $element = $this->page($response)->querySelector($selector);
        $this->assertNotNull($element, "Sayfada '{$selector}' yok.");

        return $element;
    }

    private function text(Element $element): string
    {
        return trim(preg_replace('/\s+/u', ' ', $element->textContent));
    }

    /**
     * Doğrulama hatası sonrası session'daki hata çantası (CleaningDetailTest ile aynı biçim).
     *
     * @param  array<string, list<string>>  $messages
     */
    private function sessionErrors(array $messages): mixed
    {
        $bag = new MessageBag($messages);

        return config('session.serialization') === 'json'
            ? ['default' => ['format' => $bag->getFormat(), 'messages' => $bag->getMessages()]]
            : (new ViewErrorBag)->put('default', $bag);
    }
}
