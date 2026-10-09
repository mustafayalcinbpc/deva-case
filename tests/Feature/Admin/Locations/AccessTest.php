<?php

namespace Tests\Feature\Admin\Locations;

use App\Models\Facility;
use App\Models\Line;

/**
 * R-42, R-43: tesis, hat ve makine tanımlarını yalnızca yönetici yönetir. Operatör bütün
 * adreslerde 403 alır ve menüde bu öğeleri görmez.
 */
class AccessTest extends LocationsTestCase
{
    public function test_operator_gets_403_on_every_route_and_nothing_changes(): void
    {
        $machine = $this->makeMachine();
        $line = $machine->line;
        $facility = $line->facility;
        $operator = $this->operator();

        $requests = [
            ['get', route('admin.facilities.index')],
            ['get', route('admin.facilities.create')],
            ['post', route('admin.facilities.store'), ['code' => 'ANK', 'name' => 'Ankara']],
            ['get', route('admin.facilities.edit', $facility)],
            ['put', route('admin.facilities.update', $facility), ['code' => 'IZM', 'name' => 'İzmir']],
            ['get', route('admin.lines.create', $facility)],
            ['post', route('admin.lines.store', $facility), ['code' => 'H09', 'name' => 'Hat 9']],
            ['get', route('admin.lines.edit', $line)],
            ['put', route('admin.lines.update', $line), ['code' => 'H09', 'name' => 'Hat 9']],
            ['get', route('admin.machines.index')],
            ['get', route('admin.machines.create')],
            ['post', route('admin.machines.store'), ['line_id' => $line->id, 'code' => 'M99', 'name' => 'Yeni', 'procedure_id' => $machine->procedure_id]],
            ['get', route('admin.machines.show', $machine)],
            ['get', route('admin.machines.edit', $machine)],
            ['put', route('admin.machines.update', $machine), ['line_id' => $line->id, 'code' => 'M99', 'name' => 'Yeni', 'procedure_id' => $machine->procedure_id]],
            ['post', route('admin.machines.retire', $machine)],
            ['post', route('admin.machines.reinstate', $machine)],
        ];

        foreach ($requests as $request) {
            [$method, $url, $data] = $request + [2 => []];
            $this->actingAs($operator)->{$method}($url, $data)->assertForbidden();
        }

        $this->assertSame(['IST'], Facility::pluck('code')->all());
        $this->assertSame(['H01'], Line::pluck('code')->all());
        $machine->refresh();
        $this->assertSame('M03', $machine->code);
        $this->assertTrue($machine->is_active);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.facilities.index'))->assertRedirect(route('login'));
        $this->get(route('admin.machines.index'))->assertRedirect(route('login'));
    }

    public function test_manager_sees_the_menu_items_and_operator_does_not(): void
    {
        $this->actingAs($this->manager)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Tesis ve Hatlar')
            ->assertSee('href="'.route('admin.facilities.index').'"', false)
            ->assertSee('Makineler')
            ->assertSee('href="'.route('admin.machines.index').'"', false);

        $this->actingAs($this->operator())->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Tesis ve Hatlar')
            ->assertDontSee('href="'.route('admin.facilities.index').'"', false)
            ->assertDontSee('href="'.route('admin.machines.index').'"', false);
    }
}
