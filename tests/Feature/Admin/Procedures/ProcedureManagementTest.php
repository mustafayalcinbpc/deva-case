<?php

namespace Tests\Feature\Admin\Procedures;

use App\Models\Machine;
use App\Models\Procedure;
use Dom\Element;

/**
 * Prosedür listesi, ekleme, düzenleme ve prosedür sayfası (R-01, R-02, K-15, K-18).
 */
class ProcedureManagementTest extends ProcedureTestCase
{
    public function test_index_lists_current_version_draft_machines_and_record_count(): void
    {
        $machine = $this->makeMachine([['steps' => 2]], code: 'M03'); // PRC-M03 v1, 08:00 UTC = 11:00 İstanbul
        $this->openCleaning($this->operator(), $machine);
        $this->openCleaning($this->operator('Mehmet'), $machine);
        $this->publishVersion($machine->procedure, [['steps' => 1]], publishedAt: now()->addDay());
        $this->draft($machine->procedure);

        $other = $this->procedure('PRC-YENI');
        $this->draft($other, []);

        $response = $this->get(route('admin.procedures.index'))->assertOk()
            ->assertSee('<title>Prosedürler', false);

        $rows = $this->page($response)->querySelectorAll('.procedure-list__table tbody tr');
        $this->assertCount(2, $rows);

        $first = $this->text($rows[0]);
        $this->assertStringContainsString('PRC-M03', $first);
        $this->assertStringContainsString('v1 09.10.2026 11:00', $first);
        $this->assertStringContainsString('v2: 10.10.2026 11:00 itibarıyla', $first);
        $this->assertStringContainsString('v3 taslağı', $first);
        $this->assertStringContainsString('H01 / M03', $first);
        $this->assertSame('2', $this->text($rows[0]->querySelector('td:last-child')));

        $second = $this->text($rows[1]);
        $this->assertStringContainsString('PRC-YENI', $second);
        $this->assertStringContainsString('Yayında versiyon yok', $second);
        $this->assertStringContainsString('v1 taslağı', $second);
        $this->assertStringContainsString('Makine yok', $second);
    }

    public function test_creating_a_procedure_opens_an_empty_draft_v1(): void
    {
        $this->get(route('admin.procedures.create'))->assertOk()->assertSee('Yeni prosedür');

        $response = $this->post(route('admin.procedures.store'), ['code' => ' prc-dol ', 'name' => ' Dolum temizliği ']);

        $procedure = Procedure::sole();
        $draft = $procedure->versions()->sole();
        $response->assertRedirect(route('admin.procedures.versions.show', [$procedure, $draft]));
        $this->assertSame('PRC-DOL', $procedure->code);
        $this->assertSame('Dolum temizliği', $procedure->name);
        $this->assertSame(1, $draft->version);
        $this->assertTrue($draft->isDraft());
        $this->assertFalse($draft->material_required);
        $this->assertSame(0, $draft->phases()->count());
        $this->assertNull($procedure->currentVersion());
    }

    public function test_code_must_be_unique_and_well_formed(): void
    {
        $this->procedure('PRC-DOL');

        $this->from(route('admin.procedures.create'))
            ->post(route('admin.procedures.store'), ['code' => 'prc-dol', 'name' => 'Başka'])
            ->assertRedirect(route('admin.procedures.create'))
            ->assertSessionHasErrors(['code' => 'kod zaten kullanılıyor.']);

        $this->post(route('admin.procedures.store'), ['code' => 'PRC DOL', 'name' => 'Başka'])
            ->assertSessionHasErrors('code');
        $this->post(route('admin.procedures.store'), ['code' => '-PRC', 'name' => 'Başka'])
            ->assertSessionHasErrors('code');
        $this->post(route('admin.procedures.store'), ['code' => '', 'name' => ''])
            ->assertSessionHasErrors(['code', 'name']);

        $this->assertSame(1, Procedure::count());
    }

    public function test_code_and_name_can_be_edited(): void
    {
        $procedure = $this->procedure('PRC-OLD');
        $this->procedure('PRC-TAKEN');

        $this->get(route('admin.procedures.edit', $procedure))->assertOk()->assertSee('value="PRC-OLD"', false);

        $this->put(route('admin.procedures.update', $procedure), ['code' => 'PRC-TAKEN', 'name' => 'Yeni ad'])
            ->assertSessionHasErrors('code');

        // Kendi kodu benzersizlik kontrolüne takılmaz.
        $this->put(route('admin.procedures.update', $procedure), ['code' => 'PRC-OLD', 'name' => 'Yeni ad'])
            ->assertRedirect(route('admin.procedures.show', $procedure));
        $this->put(route('admin.procedures.update', $procedure), ['code' => 'prc-new', 'name' => 'Yeni ad'])
            ->assertRedirect(route('admin.procedures.show', $procedure));

        $this->assertSame(['PRC-NEW', 'Yeni ad'], [$procedure->fresh()->code, $procedure->fresh()->name]);
    }

    public function test_procedure_page_lists_every_version_with_its_status_and_counts(): void
    {
        $machine = $this->makeMachine([['steps' => 2]], code: 'M03');
        $procedure = $machine->procedure;
        $v1 = $procedure->currentVersion();
        $this->openCleaning($this->operator(), $machine);

        $this->at('09:00:00');
        $v2 = $this->publishVersion($procedure, [['steps' => 2], ['steps' => 3]], materialRequired: true);
        $this->openCleaning($this->operator('Mehmet'), Machine::find($machine->id));
        $v3 = $this->publishVersion($procedure, [['steps' => 1]], publishedAt: now()->addDays(2));
        $v4 = $this->draft($procedure, [['steps' => 4]]);

        $response = $this->get(route('admin.procedures.show', $procedure))->assertOk();

        $row = fn ($version) => $this->text($this->one($response, "#version-{$version->id}"));
        $this->assertSame('v1 Eski 09.10.2026 11:00 Zorunlu değil 1 2 1 Görüntüle', $row($v1));
        $this->assertSame('v2 Yayında 09.10.2026 12:00 Zorunlu 2 5 1 Görüntüle', $row($v2));
        $this->assertSame('v3 Yayımlanacak 11.10.2026 12:00 Zorunlu değil 1 1 0 Görüntüle', $row($v3));
        $this->assertSame('v4 Taslak — Zorunlu değil 1 4 0 Düzenle Sil', $row($v4));

        // Taslak varken ikinci taslak açılamaz: düğme yerine taslağa bağlantı gösterilir.
        $this->assertNull($this->page($response)->querySelector('form[action="'.route('admin.procedures.versions.store', $procedure).'"]'));
        $response->assertSee('v4 taslağını düzenle');
        $this->assertStringContainsString('H01 / M03', $this->text($this->one($response, '.procedure-machines')));
    }

    public function test_procedure_page_sections_are_tabs_and_each_pane_shows_only_its_own_section(): void
    {
        $machine = $this->makeMachine([['steps' => 2]], code: 'M03');
        $procedure = $machine->procedure;
        $v1 = $procedure->currentVersion();
        $this->draft($procedure);
        $sections = ['versions', 'summary', 'machines', 'history'];

        $page = $this->page($this->get(route('admin.procedures.show', $procedure))->assertOk());
        $tabs = iterator_to_array($page->querySelectorAll('[data-module~="section-tabs"] .procedure-tabs__nav [role="tab"]'));

        // Sekme adı kartın başlığıdır; versiyon ve makine sayısı yanında yazılır.
        $this->assertSame(['Versiyonlar 2', 'Özet', 'Kullanan makineler 1', 'Değişiklik geçmişi'], array_map(fn (Element $tab) => $this->text($tab), $tabs));
        $this->assertSame(
            array_map(fn (string $section) => "#pane-{$section}", $sections),
            array_map(fn (Element $tab) => $tab->getAttribute('data-bs-target'), $tabs),
        );

        // Versiyonlar etkin gelir: yayımlama ve taslak silme bu sayfaya döner.
        $this->assertSame(['true', 'false', 'false', 'false'], array_map(fn (Element $tab) => $tab->getAttribute('aria-selected'), $tabs));
        $this->assertSame(['pane-versions'], array_map(
            fn (Element $pane) => $pane->id,
            iterator_to_array($page->querySelectorAll('[data-module~="section-tabs"] .tab-pane.active')),
        ));

        foreach ($sections as $section) {
            $pane = $page->getElementById("pane-{$section}");
            $this->assertNotNull($pane, "#pane-{$section} yok.");
            $this->assertSame("tab-{$section}", $pane->getAttribute('aria-labelledby'));
            $this->assertSame([$section], $this->childIds($pane), "#pane-{$section} yalnızca kendi bölümünü içermeli.");
            $this->assertCount(1, $page->querySelectorAll("#{$section}"), "#{$section} sayfada bir kez olmalı.");
        }

        $this->assertNotNull($page->querySelector("#pane-versions #version-{$v1->id}"));
        $this->assertNotNull($page->querySelector('#pane-summary .procedure-facts'));
        $this->assertNotNull($page->querySelector('#pane-machines .procedure-machines__item'));
        $this->assertNotNull($page->querySelector('#pane-history .definition-history'));
    }

    /**
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
}
