<?php

namespace Tests\Feature\Admin\Procedures;

use App\Models\Machine;

/**
 * Taslağı yayımlama (K-15, R-11, R-13): hemen ya da ileri bir tarihte. Tarih gösterim saat
 * diliminde (İstanbul, UTC+3) girilir, UTC saklanır. İleri tarihli versiyon tarih gelene kadar
 * yeni kayıtlara uygulanmaz; açık kayıtlar açıldıkları versiyonda kalır.
 */
class PublicationTest extends ProcedureTestCase
{
    public function test_publishing_now_applies_to_new_records_and_open_records_keep_their_version(): void
    {
        $machine = $this->makeMachine([['steps' => 2]]);
        $procedure = $machine->procedure;
        $v1 = $procedure->currentVersion();
        $open = $this->openCleaning($this->operator('Ahmet'), $machine);
        $draft = $this->draft($procedure, [['steps' => 3], ['steps' => 1]], materialRequired: true);

        $this->at('08:30:15');
        $this->post(route('admin.procedures.versions.publish', [$procedure, $draft]), ['when' => 'now'])
            ->assertRedirect(route('admin.procedures.show', $procedure))
            ->assertSessionHas('status', 'v2 yayımlandı; bundan sonra açılan kayıtlara uygulanır. Açık kayıtlar kendi versiyonuyla devam eder.');

        $this->assertSame('2026-10-09 08:30:15', $draft->fresh()->published_at->utc()->format('Y-m-d H:i:s'));
        $this->assertTrue($draft->is($procedure->currentVersion()));

        $new = $this->openCleaning($this->operator('Mehmet'), Machine::find($machine->id));
        $this->assertSame($draft->id, $new->procedure_version_id);
        $this->assertSame(4, $new->steps()->count());
        $this->assertSame($v1->id, $open->fresh()->procedure_version_id);
        $this->assertSame(2, $open->steps()->count());
    }

    public function test_future_version_is_not_used_until_its_date_arrives(): void
    {
        $machine = $this->makeMachine([['steps' => 2]], code: 'M01');
        $procedure = $machine->procedure;
        $v1 = $procedure->currentVersion();
        $draft = $this->draft($procedure, [['steps' => 1], ['steps' => 1], ['steps' => 1]]);

        // İstanbul 10.10.2026 09:00 = UTC 06:00.
        $this->post(route('admin.procedures.versions.publish', [$procedure, $draft]), [
            'when' => 'scheduled',
            'publish_at' => '2026-10-10T09:00',
        ])->assertSessionHas('status', 'v2 yayımlandı; 10.10.2026 09:00 itibarıyla açılan yeni kayıtlara uygulanacak.');

        $draft->refresh();
        $this->assertSame('2026-10-10 06:00:00', $draft->published_at->utc()->format('Y-m-d H:i:s'));
        $this->assertTrue($draft->isScheduled());
        $this->assertTrue($v1->is($procedure->currentVersion()));
        $this->assertSame('v1', $this->summaryVersion($machine));
        $this->assertSame($v1->id, $this->openCleaning($this->operator('Ahmet'), $machine)->procedure_version_id);
        $this->assertStringContainsString('Yayımlanacak', $this->text($this->one($this->get(route('admin.procedures.show', $procedure)), "#version-{$draft->id}")));

        $this->at('05:59:59', '2026-10-10');
        $this->assertTrue($v1->is($procedure->currentVersion()));

        $this->at('06:00:00', '2026-10-10');
        $this->assertTrue($draft->is($procedure->currentVersion()));
        $this->assertSame('v2', $this->summaryVersion($machine));
        $this->assertSame($draft->id, $this->openCleaning($this->operator('Mehmet'), Machine::find($machine->id))->procedure_version_id);

        $page = $this->get(route('admin.procedures.show', $procedure));
        $this->assertStringContainsString('Yayında', $this->text($this->one($page, "#version-{$draft->id}")));
        $this->assertStringContainsString('Eski', $this->text($this->one($page, "#version-{$v1->id}")));
    }

    public function test_machine_whose_only_version_is_scheduled_is_offered_once_the_date_arrives(): void
    {
        $procedure = $this->procedure('PRC-YENI');
        $draft = $this->draft($procedure, [['steps' => 1]]);
        $machine = $this->makeMachine(code: 'M02');
        $machine->update(['procedure_id' => $procedure->id]);

        $this->post(route('admin.procedures.versions.publish', [$procedure, $draft]), ['when' => 'scheduled', 'publish_at' => '2026-10-09T12:00']);

        $this->assertNull($procedure->currentVersion());
        $this->actingAs($this->operator());
        $before = $this->page($this->get(route('cleanings.create'))->assertOk());
        $this->assertNull($before->querySelector('#machine_id option[value="'.$machine->id.'"]'));

        $this->at('09:00:00'); // İstanbul 12:00
        $after = $this->page($this->get(route('cleanings.create'))->assertOk());
        $this->assertNotNull($after->querySelector('#machine_id option[value="'.$machine->id.'"]'));
    }

    public function test_structure_rules_are_checked_before_publishing(): void
    {
        $procedure = $this->procedure();
        $empty = $this->draft($procedure, []);
        $back = route('admin.procedures.versions.show', [$procedure, $empty]);

        $this->from($back)->post(route('admin.procedures.versions.publish', [$procedure, $empty]), ['when' => 'now'])
            ->assertRedirect($back)
            ->assertSessionHasErrors(['phases' => 'Taslakta faz yok. Yayımlamak için en az bir faz ve adım tanımlayın (R-03).']);
        $this->assertTrue($empty->fresh()->isDraft());

        $empty->delete();
        $draft = $this->draft($procedure, [['steps' => 2], ['steps' => 0], ['steps' => 0]]);
        $draft->phases()->where('sequence', 3)->update(['name' => 'Kontrol']);

        $response = $this->post(route('admin.procedures.versions.publish', [$procedure, $draft]), ['when' => 'now']);
        $response->assertSessionHasErrors('phases');
        $this->assertSame([
            '“Faz 2” fazında adım yok. Her fazda en az bir adım olmalı (R-03).',
            '“Kontrol” fazında adım yok. Her fazda en az bir adım olmalı (R-03).',
        ], session('errors')->get('phases'));
        $this->assertTrue($draft->fresh()->isDraft());

        // Hata yayın kartında gösterilir.
        $this->followingRedirects()->from(route('admin.procedures.versions.show', [$procedure, $draft]))
            ->post(route('admin.procedures.versions.publish', [$procedure, $draft]), ['when' => 'now'])
            ->assertOk()
            ->assertSee('“Kontrol” fazında adım yok.');
    }

    public function test_scheduled_publication_needs_a_future_date(): void
    {
        $procedure = $this->procedure();
        $draft = $this->draft($procedure, [['steps' => 1]]);
        $publish = fn (array $data) => $this->post(route('admin.procedures.versions.publish', [$procedure, $draft]), $data);

        $publish(['when' => 'scheduled'])->assertSessionHasErrors(['publish_at' => 'İleri tarihli yayın için tarih ve saat girin.']);
        $publish(['when' => 'scheduled', 'publish_at' => '10.10.2026 09:00'])->assertSessionHasErrors('publish_at');
        $publish(['when' => 'later'])->assertSessionHasErrors('when');
        // Tarih girilip "Hemen" seçilmişse belirsizdir; hemen yayımlanmaz.
        $publish(['when' => 'now', 'publish_at' => '2026-10-10T09:00'])->assertSessionHasErrors('publish_at');
        // Şu an İstanbul saatiyle 11:00; 11:00 geçmiş sayılır.
        $publish(['when' => 'scheduled', 'publish_at' => '2026-10-09T11:00'])->assertSessionHasErrors('published_at');
        $publish(['when' => 'scheduled', 'publish_at' => '2026-10-08T23:00'])->assertSessionHasErrors('published_at');

        $this->assertTrue($draft->fresh()->isDraft());

        $publish(['when' => 'scheduled', 'publish_at' => '2026-10-09T11:01'])->assertSessionHasNoErrors();
        $this->assertSame('2026-10-09 08:01:00', $draft->fresh()->published_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_new_version_cannot_take_effect_before_an_already_scheduled_one(): void
    {
        $procedure = $this->procedure();
        $this->publishVersion($procedure, [['steps' => 1]]);
        $v2 = $this->publishVersion($procedure, [['steps' => 1]], publishedAt: now()->addDays(3)); // İstanbul 12.10.2026 11:00
        $draft = $this->draft($procedure, [['steps' => 2]]);

        $this->post(route('admin.procedures.versions.publish', [$procedure, $draft]), ['when' => 'now'])
            ->assertSessionHasErrors(['published_at' => 'v2 12.10.2026 11:00 tarihinde yayına girecek. Yeni versiyon bu tarihten önce yayımlanamaz; daha sonraki bir tarih seçin.']);
        $this->post(route('admin.procedures.versions.publish', [$procedure, $draft]), ['when' => 'scheduled', 'publish_at' => '2026-10-11T09:00'])
            ->assertSessionHasErrors('published_at');
        $this->assertTrue($draft->fresh()->isDraft());

        $this->post(route('admin.procedures.versions.publish', [$procedure, $draft]), ['when' => 'scheduled', 'publish_at' => '2026-10-12T11:00'])
            ->assertSessionHasNoErrors();

        $this->travelTo($v2->published_at);
        $this->assertTrue($draft->is($procedure->currentVersion()), 'Aynı anda yürürlüğe girenlerden büyük numaralı geçerlidir.');
    }

    public function test_publish_form_explains_the_timezone_and_the_pending_schedule(): void
    {
        $procedure = $this->procedure();
        $this->publishVersion($procedure, [['steps' => 1]], publishedAt: now()->addDay());
        $draft = $this->draft($procedure);

        $response = $this->get(route('admin.procedures.versions.show', [$procedure, $draft]))->assertOk();

        $input = $this->one($response, '#publish_at');
        $this->assertSame('datetime-local', $input->getAttribute('type'));
        $this->assertSame('2026-10-09T11:00', $input->getAttribute('min'));
        $help = $this->text($this->one($response, '#publish_at-help'));
        $this->assertStringContainsString('Europe/Istanbul', $help);
        $this->assertStringContainsString('v1 10.10.2026 11:00 tarihinde yayına girecek', $help);
        $this->assertTrue($this->one($response, '#when-now')->hasAttribute('checked'));
    }

    /**
     * Kayıt açma formunun makine özetindeki versiyon (K-15, K-18).
     */
    private function summaryVersion(Machine $machine): string
    {
        $response = $this->actingAs($this->operator('Gözlemci '.uniqid()))->get(route('cleanings.create'))->assertOk();
        $this->actingAs($this->admin);

        foreach ($this->page($response)->querySelectorAll('[data-machine-summary="'.$machine->id.'"] .machine-summary__fact') as $fact) {
            if ($this->text($fact->querySelector('dt')) === 'Versiyon') {
                return $this->text($fact->querySelector('dd'));
            }
        }

        $this->fail('Makine özetinde versiyon yok.');
    }
}
