<?php

namespace Tests\Feature\Screens;

use App\Enums\CancelReason;
use App\Enums\CleaningType;
use App\Models\Cleaning;
use App\Models\CleaningEvent;
use App\Models\CleaningMaterial;
use App\Models\CleaningStep;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Cleaning\MaterialEntry;
use Closure;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\TestCase;

/**
 * Kayıt detayı: sekmeler ("Şimdi" kartı, kontrol listesi, özet, malzemeler, olay geçmişi) ve sağ
 * sütunda ilerleme ile iptal (docs/plan-detay-sekmeler.md).
 * Sayfa veri değiştirmez; formların doğru kullanıcıya, doğru durumda ve sözleşmedeki route ve
 * alan adlarıyla gösterildiği doğrulanır (docs/plan-ekranlar.md). Formlar burada gönderilmez.
 */
class CleaningDetailTest extends TestCase
{
    use BuildsCleaningFixtures, InteractsWithCleaningWorkflow, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('08:00:00');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $cleaning = $this->openCleaning($this->operator(), $this->makeMachine());

        $this->get(route('cleanings.show', $cleaning))->assertRedirect(route('login'));
    }

    public function test_unknown_record_returns_404(): void
    {
        $this->actingAs($this->operator())->get('/cleanings/999999')->assertNotFound();
    }

    public function test_sections_are_tabs_and_each_pane_shows_only_its_own_section(): void
    {
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine());
        $sections = ['now' => 'Şimdi', 'checklist' => 'Adımlar', 'summary' => 'Özet', 'materials' => 'Malzemeler', 'history' => 'Olay geçmişi'];

        // Operatör (sahip ya da yalnızca görüntüleyen) ve yönetici aynı sekmeleri görür.
        foreach ([$ahmet, $this->operator('İzleyici'), $this->manager()] as $viewer) {
            $page = $this->page($this->show($viewer, $cleaning));
            $tabs = iterator_to_array($page->querySelectorAll('[data-module~="section-tabs"] .cleaning-detail__nav [role="tab"]'));

            $this->assertSame(array_values($sections), array_map(fn (Element $tab) => $this->text($tab), $tabs));
            $this->assertSame(
                array_map(fn (string $section) => "#pane-{$section}", array_keys($sections)),
                array_map(fn (Element $tab) => $tab->getAttribute('data-bs-target'), $tabs),
            );

            // Sunucu "Şimdi"yi etkin getirir; diğer paneller sekmesi seçilince görünür.
            $this->assertSame(['true', 'false', 'false', 'false', 'false'], array_map(fn (Element $tab) => $tab->getAttribute('aria-selected'), $tabs));
            $this->assertSame(['pane-now'], array_map(
                fn (Element $pane) => $pane->id,
                iterator_to_array($page->querySelectorAll('[data-module~="section-tabs"] .tab-pane.active')),
            ));

            foreach (array_keys($sections) as $section) {
                $pane = $page->getElementById("pane-{$section}");
                $this->assertNotNull($pane, "#pane-{$section} yok.");
                $this->assertSame("tab-{$section}", $pane->getAttribute('aria-labelledby'));
                $this->assertSame([$section], $this->childIds($pane), "#pane-{$section} yalnızca kendi bölümünü içermeli.");
                $this->assertCount(1, $page->querySelectorAll("#{$section}"), "#{$section} sayfada bir kez olmalı.");
            }
        }
    }

    public function test_progress_and_cancel_are_in_the_side_column_next_to_the_tabs(): void
    {
        $cleaning = $this->openCleaning($this->operator('Ahmet'), $this->makeMachine());

        $page = $this->page($this->show($this->manager(), $cleaning));
        $this->assertSame(['progress', 'cancel'], $this->childIds($page->querySelector('.cleaning-detail__aside')));
        $this->assertNull($page->querySelector('.cleaning-detail__panes #progress'));
        $this->assertNull($page->querySelector('.cleaning-detail__panes #cancel'));

        // İptal edemeyen kullanıcının sağ sütununda yalnızca ilerleme var.
        $page = $this->page($this->show($this->operator('İzleyici'), $cleaning));
        $this->assertSame(['progress'], $this->childIds($page->querySelector('.cleaning-detail__aside')));
    }

    public function test_summary_answers_where_who_which_procedure_and_how_long(): void
    {
        $ahmet = $this->operator('Ahmet Yılmaz');
        $mehmet = $this->operator('Mehmet Kaya');
        $machine = $this->makeMachine();
        $workOrder = WorkOrder::create(['code' => 'WO-2026-001', 'machine_id' => $machine->id, 'description' => 'Ürün değişimi']);
        $cleaning = $this->workflow()->open($ahmet, $machine, CleaningType::Planned, [$mehmet->id], [], $workOrder, 'Alerjen sonrası temizlik');

        // 08:15–08:20 çalışıldı, 08:30'da devam edildi, şimdi 08:35: net 10 dk, brüt 20 dk,
        // iki kişi çalıştığı için efor 20 dk (R-26).
        $this->at('08:15:00');
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        $this->at('08:20:00');
        $this->workflow()->pauseStep($ahmet, $this->stepOf($cleaning, 1));
        $this->at('08:30:00');
        $this->workflow()->resumeStep($ahmet, $this->stepOf($cleaning, 1));
        $this->at('08:35:00');

        $response = $this->show($this->operator('İzleyici'), $cleaning->refresh());
        $summary = $this->text($this->one($response, '#summary'));

        $response->assertSee('<title>'.$cleaning->record_no, false);
        foreach ([
            'Kayıt no '.$cleaning->record_no,
            'Saha defteri referansı IST-SD-2026-0001',
            'Tür Planlı temizlik',
            'Durum Devam ediyor',
            'Tesis IST — İstanbul Tesisi',
            'Hat H01 — Hat 1',
            'Makine M03 — Makine M03',
            'Sorumlu Ahmet Yılmaz',
            'Prosedür PRC-M03 — M03 temizlik prosedürü versiyon 1',
            'İş emri WO-2026-001 — Ürün değişimi',
            'Açıklama Alerjen sonrası temizlik',
            'Açılış 09.10.2026 11:00:00',
            'Başlangıç 09.10.2026 11:15:00',
            'Net çalışma süresi 10 dk 00 sn',
            'Brüt süre 20 dk 00 sn',
            'İnsan eforu 20 dk 00 sn',
        ] as $expected) {
            $this->assertStringContainsString($expected, $summary);
        }
    }

    public function test_summary_shows_who_cancelled_when_and_why(): void
    {
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine());
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        $this->at('09:00:00');
        $this->workflow()->cancel($this->manager('Zeynep'), $cleaning, CancelReason::PersonnelLeft, 'Ahmet başka bölüme geçti');

        $summary = $this->text($this->one($this->show($ahmet, $cleaning), '#summary'));

        $this->assertStringContainsString('Durum İptal', $summary);
        $this->assertStringContainsString('İptal zamanı 09.10.2026 12:00:00', $summary);
        $this->assertStringContainsString('İptal eden Zeynep', $summary);
        $this->assertStringContainsString('Gerekçe Personel ayrıldı', $summary);
        $this->assertStringContainsString('Açıklama Ahmet başka bölüme geçti', $summary);
    }

    public function test_summary_explains_an_expired_record_and_unplanned_field_reference(): void
    {
        $cleaning = $this->openCleaning($this->operator(), $this->makeMachine(), type: CleaningType::Unplanned);
        $this->at('08:31:00');
        $this->workflow()->expireStale();

        $summary = $this->text($this->one($this->show($this->operator('İzleyici'), $cleaning), '#summary'));

        $this->assertStringContainsString('Durum Süresi doldu', $summary);
        $this->assertStringContainsString('Süre dolumu 09.10.2026 11:31:00', $summary);
        $this->assertStringContainsString('açıldıktan sonra 30 dakika içinde ilk adım başlatılmadı', $summary);
        $this->assertStringContainsString('Saha defteri referansı Yok (plansız müdahale saha defterine işlenmez)', $summary);
        $this->assertStringContainsString('Net çalışma süresi —', $summary);
    }

    public function test_now_card_offers_the_actions_of_the_current_step_state(): void
    {
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine([['steps' => 2]]));
        $first = $this->stepOf($cleaning, 1);
        $second = $this->stepOf($cleaning, 2);

        // Başlamamış kayıt: ilk adım bekliyor.
        $response = $this->show($ahmet, $cleaning);
        $this->assertSame([route('cleanings.steps.start', [$cleaning, $first])], $this->nowActions($response));
        $this->assertSame(['Başlat'], $this->nowButtons($response));
        $this->assertStringContainsString('Sıradaki adım', $this->text($this->one($response, '#now')));

        // Çalışan adım: Duraklat ve Tamamla.
        $this->workflow()->startStep($ahmet, $first);
        $response = $this->show($ahmet, $cleaning);
        $this->assertSame([
            route('cleanings.steps.pause', [$cleaning, $first]),
            route('cleanings.steps.complete', [$cleaning, $first]),
        ], $this->nowActions($response));
        $this->assertSame(['Duraklat', 'Tamamla'], $this->nowButtons($response));
        $this->assertStringContainsString('Devam eden adım', $this->text($this->one($response, '#now')));

        // Duraklatılmış adım: yalnızca Devam et.
        $this->workflow()->pauseStep($ahmet, $this->stepOf($cleaning, 1));
        $response = $this->show($ahmet, $cleaning);
        $this->assertSame([route('cleanings.steps.resume', [$cleaning, $first])], $this->nowActions($response));
        $this->assertSame(['Devam et'], $this->nowButtons($response));
        $this->assertStringContainsString('Duraklatılmış adım', $this->text($this->one($response, '#now')));

        // Adımlar arası: sıradaki bekleyen adım başlatılır.
        $this->workflow()->resumeStep($ahmet, $this->stepOf($cleaning, 1));
        $this->workflow()->completeStep($ahmet, $this->stepOf($cleaning, 1));
        $response = $this->show($ahmet, $cleaning);
        $this->assertSame([route('cleanings.steps.start', [$cleaning, $second])], $this->nowActions($response));

        // Bütün step formları POST + CSRF; görevli formu PUT.
        foreach ($this->page($response)->querySelectorAll('#now form') as $form) {
            $this->assertSame('POST', strtoupper($form->getAttribute('method')));
            $this->assertNotNull($form->querySelector('input[name="_token"]'));
        }
    }

    public function test_closed_records_show_no_forms(): void
    {
        $ahmet = $this->operator('Ahmet');
        $manager = $this->manager();

        $completed = $this->openCleaning($ahmet, $this->makeMachine(code: 'M01'));
        $this->completeRemainingSteps($ahmet, $completed);

        $cancelled = $this->openCleaning($ahmet, $this->makeMachine(code: 'M02'));
        $this->workflow()->startStep($ahmet, $this->stepOf($cancelled, 1));
        $this->workflow()->cancel($manager, $cancelled, CancelReason::Other, 'Arıza');

        $expired = $this->openCleaning($ahmet, $this->makeMachine(code: 'M03'));
        $this->at('09:00:00');
        $this->workflow()->expireStale();

        foreach ([
            [$completed, 'Temizlik tamamlandı ve kayıt kapandı'],
            [$cancelled, 'Kayıt iptal edildi'],
            [$expired, 'Kaydın süresi doldu'],
        ] as [$cleaning, $message]) {
            foreach ([$ahmet, $manager] as $viewer) {
                $response = $this->show($viewer, $cleaning);

                $this->assertNull($this->page($response)->querySelector('.cleaning-detail form'), "{$cleaning->status->value}: form olmamalı.");
                $this->assertNull($this->page($response)->querySelector('.checklist-step--current'));
                $this->assertStringContainsString($message, $this->text($this->one($response, '#now')));
            }
        }
    }

    public function test_only_the_owner_and_step_assignees_get_step_actions(): void
    {
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $ayse = $this->operator('Ayşe');
        $manager = $this->manager('Zeynep');
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine([['steps' => 2]]), helpers: [$mehmet]);
        $first = $this->stepOf($cleaning, 1);
        $start = route('cleanings.steps.start', [$cleaning, $first]);

        // Sahibi ve adımın görevlisi adımı yürütür, görevlileri değiştirir ve malzeme yönetir.
        foreach ([$ahmet, $mehmet] as $viewer) {
            $response = $this->show($viewer, $cleaning);
            $this->assertSame([$start], $this->nowActions($response), $viewer->name);
            $this->one($response, '#now form[action="'.route('cleanings.steps.workers', [$cleaning, $first]).'"]');
            $this->one($response, '#materials form[action="'.route('cleanings.materials.store', $cleaning).'"]');
        }

        // R-44, K-11: görmek çalıştırmak değildir. İlgisiz operatör hiçbir form görmez.
        $response = $this->show($ayse, $cleaning);
        $this->assertNull($this->page($response)->querySelector('.cleaning-detail form'));
        $this->assertStringContainsString('Bu adımda görevli değilsiniz', $this->text($this->one($response, '#now')));
        $this->assertStringContainsString('kaydın sahibi (Ahmet)', $this->text($this->one($response, '#now')));

        // Yönetici adım yürütemez ve malzeme yönetemez, ama kaydı iptal edebilir (K-08, K-11).
        $response = $this->show($manager, $cleaning);
        $this->assertSame([], $this->nowActions($response));
        $this->assertNull($this->page($response)->querySelector('#now form'));
        $this->assertNull($this->page($response)->querySelector('#materials form'));
        $this->assertStringContainsString('Bu adımda görevli değilsiniz', $this->text($this->one($response, '#now')));
        $this->one($response, '#cancel form[action="'.route('cleanings.cancel', $cleaning).'"]');

        // K-10: sahibi adımdan çıkarılsa da adımı yürütür. Mehmet 1. adımdan çıkarılınca o adımı
        // yürütemez; 2. adımda görevli olduğu için malzeme yönetmeye devam eder.
        $this->workflow()->setWorkers($ahmet, $first, [$ayse->id]);
        $this->assertSame([$start], $this->nowActions($this->show($ahmet, $cleaning)));
        $this->assertSame([$start], $this->nowActions($this->show($ayse, $cleaning)));
        $response = $this->show($mehmet, $cleaning);
        $this->assertSame([], $this->nowActions($response));
        $this->assertNull($this->page($response)->querySelector('#now form'));
        $this->one($response, '#materials form[action="'.route('cleanings.materials.store', $cleaning).'"]');
    }

    public function test_cancel_reasons_follow_k08_and_k09(): void
    {
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $manager = $this->manager();
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine(), helpers: [$mehmet]);
        $all = array_map(fn (CancelReason $reason) => $reason->value, CancelReason::cases());

        // Başlamamış kayıt: sahibi yalnızca "hatalı kayıt", yönetici her gerekçe.
        $this->assertSame(['invalid_record'], $this->cancelReasons($this->show($ahmet, $cleaning)));
        $this->assertSame($all, $this->cancelReasons($this->show($manager, $cleaning)));
        $this->assertNull($this->cancelReasons($this->show($mehmet, $cleaning)), 'Yardımcı iptal edemez.');
        $this->assertNull($this->cancelReasons($this->show($this->operator('Ayşe'), $cleaning)));

        // Başlamış kaydı yalnızca yönetici iptal eder.
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        $this->assertNull($this->cancelReasons($this->show($ahmet, $cleaning)));
        $this->assertSame($all, $this->cancelReasons($this->show($manager, $cleaning)));

        // Form sözleşmedeki alanlarla ve onayla gönderilir.
        $form = $this->one($this->show($manager, $cleaning), '#cancel form');
        $this->assertSame(route('cleanings.cancel', $cleaning), $form->getAttribute('action'));
        $this->assertStringContainsString('confirm-submit', $form->getAttribute('data-module'));
        $this->assertNotSame('', $form->getAttribute('data-confirm'));
        $this->assertNotNull($form->querySelector('textarea[name="cancel_note"][required]'));
        $this->assertSame('Seçin', trim($form->querySelector('select[name="cancel_reason"] option')->textContent));

        // Kapalı kayıtta iptal yok.
        $this->completeRemainingSteps($ahmet, $cleaning);
        $this->assertNull($this->cancelReasons($this->show($manager, $cleaning)));
    }

    public function test_deviation_reason_is_offered_on_the_last_step_of_a_phase_and_expanded_after_a_violation(): void
    {
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine([['steps' => 2, 'min_seconds' => 900], ['steps' => 1]]));
        $phase = $this->phaseOf($cleaning, 1);

        // Fazın son adımı olmayan adımda gerekçe alanı yok.
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        $response = $this->show($ahmet, $cleaning);
        $this->assertNull($this->page($response)->querySelector('[name="deviation_reason"]'));

        $this->travel(60)->seconds();
        $this->workflow()->completeStep($ahmet, $this->stepOf($cleaning, 1));
        $last = $this->stepOf($cleaning, 2);
        $this->workflow()->startStep($ahmet, $last);
        $this->travel(60)->seconds();

        // Fazın son adımı: minimum gösterilir, gerekçe alanı var ama kapalı ve isteğe bağlı.
        $response = $this->show($ahmet, $cleaning);
        $now = $this->text($this->one($response, '#now'));
        $this->assertStringContainsString('Faz minimum süresi 15 dk 00 sn', $now);
        $this->assertStringContainsString('Faz süresi (net) 2 dk 00 sn', $now);
        $details = $this->one($response, '#now details.deviation-field');
        $this->assertFalse($details->hasAttribute('open'));
        $completeForm = $this->one($response, 'form[action="'.route('cleanings.steps.complete', [$cleaning, $last]).'"]');
        $textarea = $this->one($response, 'textarea[name="deviation_reason"]');
        $this->assertSame($completeForm->getAttribute('id'), $textarea->getAttribute('form'), 'Gerekçe tamamlama formuyla gönderilmeli.');
        $this->assertFalse($textarea->hasAttribute('required'));

        // Workflow fazı minimumun altında buldu: alan açık, ölçülen ve minimum süre gösterilir.
        $response = $this->actingAs($ahmet)
            ->withSession(['violation' => ['rule' => 'below_minimum_duration', 'context' => [
                'phase_id' => $phase->id, 'measured_seconds' => 120, 'minimum_seconds' => 900,
            ]]])
            ->get(route('cleanings.show', $cleaning))->assertOk();
        $details = $this->one($response, '#now details.deviation-field');
        $this->assertTrue($details->hasAttribute('open'));
        $explanation = $this->text($details);
        $this->assertStringContainsString('1. faz minimum sürenin altında kaldı', $explanation);
        $this->assertStringContainsString('Ölçülen süre 2 dk 00 sn · Minimum 15 dk 00 sn', $explanation);

        // Başka fazın ihlali bu adımda alanı açmaz.
        $response = $this->actingAs($ahmet)
            ->withSession(['violation' => ['rule' => 'below_minimum_duration', 'context' => ['phase_id' => $phase->id + 1000]]])
            ->get(route('cleanings.show', $cleaning))->assertOk();
        $this->assertFalse($this->one($response, '#now details.deviation-field')->hasAttribute('open'));

        // Gerekçe kurala takılmadan kapanan fazda sapma notu yok; minimum altı fazda gerekçe görünür.
        $this->workflow()->completeStep($ahmet, $this->stepOf($cleaning, 2), 'Hat durdu, temizlik kısa kesildi');
        $phaseItem = $this->text($this->one($this->show($ahmet, $cleaning), '#phase-'.$phase->id));
        $this->assertStringContainsString('Minimum süre altında kapandı', $phaseItem);
        $this->assertStringContainsString('Hat durdu, temizlik kısa kesildi', $phaseItem);
        $this->assertStringContainsString('Ölçülen süre 2 dk 00 sn', $phaseItem);
    }

    public function test_validation_errors_and_old_input_are_shown_next_to_their_fields(): void
    {
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $material = $this->makeMaterial('DET-01');
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine([['steps' => 1, 'min_seconds' => 900]]), helpers: [$mehmet], materials: [$this->entry($material)]);
        $item = CleaningMaterial::query()->where('cleaning_id', $cleaning->id)->firstOrFail();
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));

        $errors = $this->sessionErrors([
            'deviation_reason' => ['Gerekçe alanı zorunlu.'],
            'user_ids' => ['Görevliler alanı zorunlu.'],
            'material_id' => ['Malzeme geçersiz.'],
            'lot_no' => ['Lot numarası alanı zorunlu.'],
            'expiry_date' => ['Son kullanma tarihi geçmiş.'],
            'void_reason' => ['Gerekçe en az 3 karakter olmalı.'],
            'cancel_reason' => ['İptal gerekçesi geçersiz.'],
            'cancel_note' => ['Açıklama alanı zorunlu.'],
        ]);
        $old = [
            'deviation_reason' => 'Kısa sürdü',
            'user_ids' => [(string) $mehmet->id],
            'material_id' => (string) $material->id,
            'lot_no' => 'LOT-XYZ',
            'expiry_date' => '2027-06-30',
            'void_material_id' => (string) $item->id,
            'void_reason' => 'Ya',
            'cancel_reason' => 'other',
            'cancel_note' => 'Taslak not',
        ];

        $response = $this->actingAs($ahmet)
            ->withSession(['errors' => $errors, '_old_input' => $old])
            ->get(route('cleanings.show', $cleaning))->assertOk();

        $this->assertFieldError($response, '#deviation-reason', 'Gerekçe alanı zorunlu.');
        $this->assertSame('Kısa sürdü', $this->one($response, '#deviation-reason')->textContent);
        $this->assertTrue($this->one($response, 'details.deviation-field')->hasAttribute('open'));

        // Görevli seçimi kullanıcının gönderdiği haliyle korunur.
        $workers = $this->one($response, 'details.workers-form');
        $this->assertTrue($workers->hasAttribute('open'));
        $this->assertStringContainsString('Görevliler alanı zorunlu.', $this->text($workers->querySelector('.invalid-feedback')));
        $this->assertTrue($this->one($response, '#worker-'.$mehmet->id)->hasAttribute('checked'));
        $this->assertFalse($this->one($response, '#worker-'.$ahmet->id)->hasAttribute('checked'));

        $this->assertFieldError($response, '#material-id', 'Malzeme geçersiz.');
        $this->assertFieldError($response, '#lot-no', 'Lot numarası alanı zorunlu.');
        $this->assertFieldError($response, '#expiry-date', 'Son kullanma tarihi geçmiş.');
        $this->assertTrue($this->one($response, 'details.material-form')->hasAttribute('open'));
        $this->assertTrue($this->one($response, '#material-id option[value="'.$material->id.'"]')->hasAttribute('selected'));
        $this->assertSame('LOT-XYZ', $this->one($response, '#lot-no')->getAttribute('value'));
        $this->assertSame('2027-06-30', $this->one($response, '#expiry-date')->getAttribute('value'));

        // Geçersiz kılma hatası yalnızca gönderilen satırda.
        $this->assertFieldError($response, '#void-reason-'.$item->id, 'Gerekçe en az 3 karakter olmalı.');
        $this->assertSame('Ya', $this->one($response, '#void-reason-'.$item->id)->textContent);
        $this->assertTrue($this->one($response, '#material-'.$item->id.' details')->hasAttribute('open'));
        $this->assertCount(1, $this->page($response)->querySelectorAll('.materials-list .invalid-feedback'));
    }

    public function test_cancel_form_keeps_old_input_and_errors(): void
    {
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine());

        $response = $this->actingAs($this->manager())
            ->withSession([
                'errors' => $this->sessionErrors([
                    'cancel_reason' => ['İptal gerekçesi geçersiz.'],
                    'cancel_note' => ['Açıklama alanı zorunlu.'],
                ]),
                '_old_input' => ['cancel_reason' => 'personnel_left', 'cancel_note' => 'Taslak'],
            ])
            ->get(route('cleanings.show', $cleaning))->assertOk();

        $this->assertTrue($this->one($response, 'details.cancel-form')->hasAttribute('open'));
        $this->assertFieldError($response, '#cancel-reason', 'İptal gerekçesi geçersiz.');
        $this->assertFieldError($response, '#cancel-note', 'Açıklama alanı zorunlu.');
        $this->assertTrue($this->one($response, '#cancel-reason option[value="personnel_left"]')->hasAttribute('selected'));
        $this->assertSame('Taslak', $this->one($response, '#cancel-note')->textContent);
    }

    public function test_workers_form_lists_active_users_with_current_assignees_checked(): void
    {
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $ayse = $this->operator('Ayşe');
        $passive = User::factory()->inactive()->create(['name' => 'Pasif Kişi']);
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine(), helpers: [$mehmet]);
        $step = $this->stepOf($cleaning, 1);

        $response = $this->show($ahmet, $cleaning);
        $form = $this->one($response, '#now form[action="'.route('cleanings.steps.workers', [$cleaning, $step]).'"]');

        $this->assertSame('POST', strtoupper($form->getAttribute('method')));
        $this->assertSame('PUT', $form->querySelector('input[name="_method"]')->getAttribute('value'));
        $this->assertFalse($this->one($response, 'details.workers-form')->hasAttribute('open'));

        $boxes = [];
        foreach ($form->querySelectorAll('input[type="checkbox"]') as $box) {
            $this->assertSame('user_ids[]', $box->getAttribute('name'));
            $boxes[(int) $box->getAttribute('value')] = $box->hasAttribute('checked');
        }

        ksort($boxes);
        $this->assertSame([$ahmet->id => true, $mehmet->id => true, $ayse->id => false], $boxes);
        $this->assertArrayNotHasKey($passive->id, $boxes, 'Pasif kullanıcı görevli seçilemez.');
        $this->assertStringContainsString('Ahmet (kayıt sahibi)', $this->text($form));
        $this->assertStringContainsString('Görevliler Ahmet, Mehmet', $this->text($this->one($response, '#now')));
    }

    public function test_materials_are_listed_with_their_void_state_and_forms(): void
    {
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $detergent = $this->makeMaterial('DET-01');
        $disinfectant = $this->makeMaterial('DEZ-02');
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine(materialRequired: true), materials: [$this->entry($detergent, 'LOT-001', '2027-12-31')]);

        $this->at('08:05:00');
        $wrong = $this->workflow()->addMaterial($ahmet, $cleaning, new MaterialEntry($disinfectant->id, 'LOT-YANLIS', '2027-03-01'));
        $this->at('08:06:00');
        $this->workflow()->voidMaterial($ahmet, $wrong, 'Lot numarası yanlış okundu');
        $valid = CleaningMaterial::query()->where('cleaning_id', $cleaning->id)->where('lot_no', 'LOT-001')->firstOrFail();

        $response = $this->show($ahmet, $cleaning);
        $validItem = $this->text($this->one($response, '#material-'.$valid->id));
        $voidedItem = $this->one($response, '#material-'.$wrong->id);

        $this->assertStringContainsString('DET-01 Malzeme DET-01', $validItem);
        $this->assertStringContainsString('Lot LOT-001', $validItem);
        $this->assertStringContainsString('Son kullanma 31.12.2027', $validItem);
        $this->assertStringContainsString('Ekleyen Ahmet, 09.10.2026 11:00:00', $validItem);

        $this->assertTrue($voidedItem->classList->contains('materials-list__item--voided'));
        $voidedText = $this->text($voidedItem);
        $this->assertStringContainsString('DEZ-02 Malzeme DEZ-02 Geçersiz', $voidedText);
        $this->assertStringContainsString('Geçersiz kılan Ahmet, 09.10.2026 11:06:00', $voidedText);
        $this->assertStringContainsString('Gerekçe Lot numarası yanlış okundu', $voidedText);

        // Geçersiz kılma formu yalnızca geçerli satırda; malzeme ekleme formu sözleşmedeki alanlarla.
        $voidForm = $this->one($response, '#material-'.$valid->id.' form');
        $this->assertSame(route('cleanings.materials.void', [$cleaning, $valid]), $voidForm->getAttribute('action'));
        $this->assertNotNull($voidForm->querySelector('textarea[name="void_reason"]'));
        $this->assertNull($voidedItem->querySelector('form'));

        $addForm = $this->one($response, '#materials form[action="'.route('cleanings.materials.store', $cleaning).'"]');
        $this->assertNotNull($addForm->querySelector('select[name="material_id"] option[value="'.$disinfectant->id.'"]'));
        $this->assertNotNull($addForm->querySelector('input[name="lot_no"]'));
        $this->assertNotNull($addForm->querySelector('input[name="expiry_date"][type="date"]'));

        // Görevli de malzeme yönetir; ilgisiz operatör listeyi görür ama form görmez.
        $this->workflow()->setWorkers($ahmet, $this->stepOf($cleaning, 2), [$ahmet->id, $mehmet->id]);
        $this->one($this->show($mehmet, $cleaning), '#materials form[action="'.route('cleanings.materials.store', $cleaning).'"]');
        $response = $this->show($this->operator('Ayşe'), $cleaning);
        $this->assertNull($this->page($response)->querySelector('#materials form'));
        $this->assertStringContainsString('LOT-001', $this->text($this->one($response, '#materials')));
    }

    public function test_missing_required_material_is_flagged_until_a_valid_one_is_added(): void
    {
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine(materialRequired: true));

        $response = $this->show($ahmet, $cleaning);
        $this->assertStringContainsString('Bu prosedürde malzeme kullanımı zorunludur.', $this->text($this->one($response, '#materials')));
        $this->assertStringContainsString('ilk adım başlatılamaz', $this->text($this->one($response, '#materials .alert')));
        $this->assertStringContainsString('malzeme zorunlu', $this->text($this->one($response, '#now .alert')));
        $this->assertTrue($this->one($response, 'details.material-form')->hasAttribute('open'));

        $this->workflow()->addMaterial($ahmet, $cleaning, $this->entry($this->makeMaterial()));
        $response = $this->show($ahmet, $cleaning);
        $this->assertNull($this->page($response)->querySelector('#materials .alert'));
        $this->assertNull($this->page($response)->querySelector('#now .alert'));
        $this->assertFalse($this->one($response, 'details.material-form')->hasAttribute('open'));
    }

    public function test_checklist_lists_phases_and_steps_and_highlights_the_current_step(): void
    {
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine([['steps' => 2, 'min_seconds' => 600], ['steps' => 1, 'include_gaps' => true]]), helpers: [$mehmet]);

        $this->runStep($ahmet, $cleaning, 1, 60);
        $this->at('08:05:00');
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 2));
        $this->at('08:07:00');

        $response = $this->show($ahmet, $cleaning);
        $steps = CleaningStep::query()->where('cleaning_id', $cleaning->id)->orderBy('sequence')->get();

        foreach ($steps as $step) {
            $item = $this->one($response, '#step-'.$step->id);
            $current = $step->sequence === 2;
            $this->assertSame($current, $item->classList->contains('checklist-step--current'), "{$step->sequence}. adım");
            $this->assertSame($current ? 'step' : null, $item->getAttribute('aria-current'));
        }

        $phase1 = $this->text($this->one($response, '#phase-'.$this->phaseOf($cleaning, 1)->id));
        $this->assertStringContainsString('1. faz Faz 1 Devam ediyor', $phase1);
        $this->assertStringContainsString('Minimum süre 10 dk 00 sn', $phase1);
        $this->assertStringContainsString('Süre ölçümü Net (adımlar arası boşluklar sayılmaz)', $phase1);
        $this->assertStringContainsString('Şu ana kadarki süre 3 dk 00 sn', $phase1);
        $phase2 = $this->text($this->one($response, '#phase-'.$this->phaseOf($cleaning, 2)->id));
        $this->assertStringContainsString('2. faz Faz 2 Bekliyor', $phase2);
        $this->assertStringContainsString('Süre ölçümü Brüt (adımlar arası boşluklar dahil)', $phase2);

        $first = $this->text($this->one($response, '#step-'.$steps[0]->id));
        $this->assertStringContainsString('1. Faz 1 adım 1 Tamamlandı', $first);
        $this->assertStringContainsString('Çalışanlar Ahmet, Mehmet', $first);
        $this->assertStringContainsString('İlk başlangıç 09.10.2026 11:00:00', $first);
        $this->assertStringContainsString('Tamamlanma 09.10.2026 11:01:00', $first);
        $this->assertStringContainsString('Net süre 1 dk 00 sn', $first);
        $this->assertStringContainsString('İnsan eforu 2 dk 00 sn', $first);
        $this->assertStringContainsString('Çalışma dilimi 1', $first);

        $second = $this->text($this->one($response, '#step-'.$steps[1]->id));
        $this->assertStringContainsString('Çalışıyor', $second);
        $this->assertStringContainsString('Görevliler Ahmet, Mehmet', $second);
        $this->assertStringContainsString('Net süre 2 dk 00 sn', $second);
        $this->one($response, '#step-'.$steps[1]->id.' a[href="#now"]');

        $third = $this->text($this->one($response, '#step-'.$steps[2]->id));
        $this->assertStringContainsString('Bekliyor', $third);
        $this->assertStringNotContainsString('Net süre', $third);
    }

    public function test_running_step_times_tick_live_from_the_open_slice(): void
    {
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine(), helpers: [$mehmet]);

        // 08:00–08:05 çalışıldı, 08:10'da devam edildi; şimdi 08:12: net 7 dk, efor 14 dk.
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        $this->at('08:05:00');
        $this->workflow()->pauseStep($ahmet, $this->stepOf($cleaning, 1));
        $this->at('08:10:00');
        $this->workflow()->resumeStep($ahmet, $this->stepOf($cleaning, 1));
        $this->at('08:12:00');

        $response = $this->show($ahmet, $cleaning);

        $net = $this->one($response, '#now .now-card__fact--net [data-module="live-duration"]');
        $this->assertSame('2026-10-09T08:10:00+00:00', $net->getAttribute('data-started-at'));
        $this->assertSame('300', $net->getAttribute('data-base-seconds'));
        $this->assertSame('1', $net->getAttribute('data-rate'));
        $this->assertSame('2026-10-09T08:12:00+00:00', $net->getAttribute('data-server-now'));
        $this->assertSame('7 dk 00 sn', $this->text($net), 'JS olmadan sayfa üretildiği andaki değer görünür.');

        $effort = $this->one($response, '#now .now-card__fact--effort [data-module="live-duration"]');
        $this->assertSame('600', $effort->getAttribute('data-base-seconds'));
        $this->assertSame('2', $effort->getAttribute('data-rate'));
        $this->assertSame('14 dk 00 sn', $this->text($effort));

        // Brüt süre ilk dilimin başlangıcından sayılır: 08:00'den 08:10'a 600 sn + açık dilim.
        $gross = $this->one($response, '#summary .cleaning-summary__time--gross [data-module="live-duration"]');
        $this->assertSame('600', $gross->getAttribute('data-base-seconds'));
        $this->assertSame('12 dk 00 sn', $this->text($gross));

        // Duraklatılmış adımda sayaç ilerlemez.
        $this->workflow()->pauseStep($ahmet, $this->stepOf($cleaning, 1));
        $response = $this->show($ahmet, $cleaning);
        $this->assertNull($this->page($response)->querySelector('[data-module="live-duration"]'));
        $this->assertSame('7 dk 00 sn', $this->text($this->one($response, '#now .now-card__fact--net .app-duration')));
    }

    public function test_step_media_is_rendered_from_storage(): void
    {
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine([['steps' => 3, 'step_attributes' => [
            1 => ['media_path' => 'procedures/sokme.jpg', 'description' => 'Kapakları sökün.'],
            2 => ['media_path' => 'procedures/yikama.mp4'],
        ]]]));
        [$first, $second, $third] = [$this->stepOf($cleaning, 1), $this->stepOf($cleaning, 2), $this->stepOf($cleaning, 3)];

        $response = $this->show($ahmet, $cleaning);
        $image = Storage::disk('public')->url('procedures/sokme.jpg');
        $video = Storage::disk('public')->url('procedures/yikama.mp4');

        $this->assertSame('Faz 1 adım 1', $this->one($response, '#now img[src="'.$image.'"]')->getAttribute('alt'));
        $this->assertStringContainsString('Kapakları sökün.', $this->text($this->one($response, '#now')));
        $this->one($response, '#step-'.$first->id.' img[src="'.$image.'"]');
        $this->assertTrue($this->one($response, '#step-'.$second->id.' video[src="'.$video.'"]')->hasAttribute('controls'));
        $this->assertNull($this->page($response)->querySelector('#step-'.$third->id.' .step-media'));
    }

    public function test_event_history_describes_every_event_and_verifies_the_chain(): void
    {
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $ayse = $this->operator('Ayşe');
        $material = $this->makeMaterial('DET-01');
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine([['steps' => 1, 'min_seconds' => 900], ['steps' => 1]]), helpers: [$mehmet], materials: [$this->entry($material, 'LOT-7', '2027-05-01')]);

        $this->at('08:10:00');
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        $this->at('08:12:00');
        $this->workflow()->pauseStep($mehmet, $this->stepOf($cleaning, 1));
        $this->at('08:20:00');
        $this->workflow()->resumeStep($ahmet, $this->stepOf($cleaning, 1));
        $this->workflow()->setWorkers($ahmet, $this->stepOf($cleaning, 1), [$ahmet->id, $ayse->id]);
        $this->at('08:22:00');
        $this->workflow()->completeStep($ahmet, $this->stepOf($cleaning, 1), 'Hat bekliyordu');
        $item = CleaningMaterial::query()->where('cleaning_id', $cleaning->id)->firstOrFail();
        $this->workflow()->addMaterial($ahmet, $cleaning, $this->entry($material, 'LOT-8'));
        $this->workflow()->voidMaterial($ahmet, $item, 'Yanlış lot');
        $this->runStep($ahmet, $cleaning, 2, 60);

        $response = $this->show($this->manager('Zeynep'), $cleaning);
        $titles = array_map(fn (Element $title) => $this->text($title), iterator_to_array($this->page($response)->querySelectorAll('.event-history__title')));

        $this->assertSame([
            'Kayıt açıldı',
            'Malzeme eklendi',
            'Temizlik başladı (ilk adım başlatıldı)',
            '1. faz başladı',
            '1. adım başlatıldı',
            '1. adım duraklatıldı',
            '1. adım devam ettirildi',
            '1. adım görevlileri değişti',
            '1. adım tamamlandı',
            '1. faz tamamlandı',
            'Malzeme eklendi',
            'Malzeme geçersiz kılındı',
            '2. faz başladı',
            '2. adım başlatıldı',
            '2. adım tamamlandı',
            '2. faz tamamlandı',
            'Temizlik tamamlandı',
        ], $titles);

        $items = iterator_to_array($this->page($response)->querySelectorAll('.event-history__item'));
        $this->assertStringContainsString('#1 09.10.2026 11:00:00 Ahmet Kayıt açıldı Tür Planlı temizlik', $this->text($items[0]));
        $this->assertStringContainsString('Yardımcı personel Mehmet', $this->text($items[0]));
        $this->assertStringContainsString('Malzeme DET-01 — Malzeme DET-01 Lot LOT-7 Son kullanma tarihi 01.05.2027', $this->text($items[1]));
        $this->assertStringContainsString('Saha defteri referansı IST-SD-2026-0001', $this->text($items[2]));
        $this->assertStringContainsString('Adım Faz 1 adım 1 Görevliler Ahmet, Mehmet', $this->text($items[4]));
        $this->assertStringContainsString('09.10.2026 11:12:00 Mehmet 1. adım duraklatıldı', $this->text($items[5]));
        $this->assertStringContainsString('Eklenen Ayşe Çıkarılan Mehmet', $this->text($items[7]));
        $phaseCompleted = $this->text($items[9]);
        $this->assertStringContainsString('Ölçülen süre 4 dk 00 sn Minimum süre 15 dk 00 sn Sapma Minimum sürenin altında Gerekçe Hat bekliyordu', $phaseCompleted);
        $this->assertStringContainsString('Malzeme DET-01 — Malzeme DET-01 Lot LOT-7 Gerekçe Yanlış lot', $this->text($items[11]));
        $this->assertStringContainsString('Net çalışma süresi 5 dk 00 sn', $this->text($items[16]));
        $this->assertStringContainsString('İnsan eforu', $this->text($items[16]));

        $this->assertStringContainsString('Kayıt bütünlüğü doğrulandı', $this->text($this->one($response, '#history .event-history__integrity--verified')));
        $this->assertNull($this->page($response)->querySelector('.event-history__integrity--broken'));
    }

    public function test_system_and_cancellation_events_are_described(): void
    {
        $ahmet = $this->operator('Ahmet');
        $expired = $this->openCleaning($ahmet, $this->makeMachine(code: 'M01'));
        $cancelled = $this->openCleaning($ahmet, $this->makeMachine(code: 'M02'));
        $this->workflow()->cancel($ahmet, $cancelled, CancelReason::InvalidRecord, 'Yanlış makine seçildi');
        $this->at('08:31:00');
        $this->workflow()->expireStale();

        $last = fn (Cleaning $cleaning) => $this->text(collect($this->page($this->show($ahmet, $cleaning))->querySelectorAll('.event-history__item'))->last());

        $this->assertStringContainsString('Sistem Kaydın süresi doldu Neden Açıldıktan sonra 30 dakika içinde ilk adım başlatılmadı.', $last($expired));
        $this->assertStringContainsString('Ahmet Kayıt iptal edildi Gerekçe Hatalı kayıt Açıklama Yanlış makine seçildi', $last($cancelled));
    }

    public function test_a_broken_event_chain_is_reported(): void
    {
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine());
        $last = CleaningEvent::query()->where('cleaning_id', $cleaning->id)->orderByDesc('sequence')->firstOrFail();

        // Zincire uymayan bir olay araya sokuldu (olaylar değiştirilemez, ama eklenebilir).
        DB::table('cleaning_events')->insert([
            'cleaning_id' => $cleaning->id,
            'sequence' => $last->sequence + 1,
            'type' => 'step.completed',
            'actor_id' => $ahmet->id,
            'payload' => json_encode(['step_id' => $this->stepOf($cleaning, 1)->id]),
            'occurred_at' => now()->subDay(),
            'previous_hash' => $last->hash,
            'hash' => str_repeat('0', 64),
        ]);

        $response = $this->show($ahmet, $cleaning);

        $this->assertStringContainsString('Kayıt bütünlüğü doğrulanamadı', $this->text($this->one($response, '#history .event-history__integrity--broken')));
        $this->assertNull($this->page($response)->querySelector('.event-history__integrity--verified'));
    }

    public function test_query_count_does_not_grow_with_the_number_of_steps(): void
    {
        $small = $this->scenario([['steps' => 2]], 'M01');
        $large = $this->scenario([['steps' => 4, 'min_seconds' => 600], ['steps' => 4], ['steps' => 4]], 'M02');

        // Tembel yüklenen ilişki varsa test patlar.
        Model::preventLazyLoading();

        try {
            foreach (['owner', 'helper', 'manager'] as $role) {
                $this->assertSame(
                    $this->countQueries(fn () => $this->show($small[$role], $small['cleaning'])),
                    $this->countQueries(fn () => $this->show($large[$role], $large['cleaning'])),
                    "{$role}: sorgu sayısı adım sayısıyla büyümemeli.",
                );
            }
        } finally {
            Model::preventLazyLoading(false);
        }
    }

    // ---------------------------------------------------------------------------------------

    /**
     * İlk adımı tamamlanmış, ikinci adımı çalışan, malzemesi ve yardımcısı olan kayıt.
     *
     * @param  list<array{steps?: int, min_seconds?: int, include_gaps?: bool}>  $phases
     * @return array{cleaning: Cleaning, owner: User, helper: User, manager: User}
     */
    private function scenario(array $phases, string $code): array
    {
        $owner = $this->operator("Sahip {$code}");
        $helper = $this->operator("Yardımcı {$code}");
        $cleaning = $this->openCleaning($owner, $this->makeMachine($phases, code: $code), helpers: [$helper], materials: [$this->entry($this->makeMaterial("MAT-{$code}"))]);
        $this->runStep($owner, $cleaning, 1, 60);
        $this->workflow()->startStep($helper, $this->stepOf($cleaning, 2));

        return ['cleaning' => $cleaning, 'owner' => $owner, 'helper' => $helper, 'manager' => $this->manager("Yönetici {$code}")];
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
     * Elemanın doğrudan alt elemanlarının id'leri, sırasıyla.
     *
     * @return list<string>
     */
    private function childIds(?Element $element): array
    {
        $this->assertNotNull($element);
        $ids = [];

        for ($child = $element->firstElementChild; $child !== null; $child = $child->nextElementSibling) {
            $ids[] = $child->id;
        }

        return $ids;
    }

    /**
     * "Şimdi" kartındaki büyük aksiyon formlarının adresleri, sırasıyla.
     *
     * @return list<string>
     */
    private function nowActions(TestResponse $response): array
    {
        return array_map(
            fn (Element $form) => $form->getAttribute('action'),
            iterator_to_array($this->page($response)->querySelectorAll('#now .now-card__actions form')),
        );
    }

    /**
     * @return list<string>
     */
    private function nowButtons(TestResponse $response): array
    {
        return array_map(
            fn (Element $button) => $this->text($button),
            iterator_to_array($this->page($response)->querySelectorAll('#now .now-card__actions button')),
        );
    }

    /**
     * İptal formundaki gerekçe seçenekleri; form yoksa null.
     *
     * @return list<string>|null
     */
    private function cancelReasons(TestResponse $response): ?array
    {
        $select = $this->page($response)->querySelector('#cancel select[name="cancel_reason"]');

        if ($select === null) {
            return null;
        }

        return array_values(array_filter(array_map(
            fn (Element $option) => $option->getAttribute('value'),
            iterator_to_array($select->querySelectorAll('option')),
        )));
    }

    private function assertFieldError(TestResponse $response, string $selector, string $message): void
    {
        $field = $this->one($response, $selector);
        $this->assertTrue($field->classList->contains('is-invalid'), "{$selector} hatalı olarak işaretlenmeli.");

        $feedback = $field->parentElement->querySelector('.invalid-feedback');
        $this->assertNotNull($feedback, "{$selector} için hata mesajı yok.");
        $this->assertSame($message, $this->text($feedback));
    }

    /**
     * Doğrulama hatası sonrası session'daki hata çantası. Session JSON ile saklandığında
     * StartSession onu dizi halinden ViewErrorBag'e çevirir; testte de o biçimde verilir.
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

    private function countQueries(Closure $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
