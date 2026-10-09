<?php

namespace Tests\Feature\Admin\Procedures;

use App\Models\Procedure;
use Illuminate\Http\UploadedFile;

/**
 * Prosedür yönetimi yalnızca yöneticiye açıktır (R-42, R-43): operatör her adreste 403 alır,
 * hiçbir şey değişmez; oturumsuz kullanıcı girişe yönlendirilir.
 */
class AccessTest extends ProcedureTestCase
{
    public function test_guest_is_redirected_to_login(): void
    {
        auth()->logout();

        $this->get(route('admin.procedures.index'))->assertRedirect(route('login'));
        $this->post(route('admin.procedures.store'), ['code' => 'PRC-X', 'name' => 'X'])->assertRedirect(route('login'));
    }

    public function test_operator_gets_403_everywhere_and_nothing_changes(): void
    {
        $procedure = $this->procedure();
        $published = $this->publishVersion($procedure, [['steps' => 2]]);
        $draft = $this->draft($procedure, [['steps' => 2], ['steps' => 1]]);
        $phase = $draft->phases()->first();
        $step = $phase->steps()->first();
        $before = $this->outline($draft);

        $this->actingAs($this->operator());

        $requests = [
            ['get', route('admin.procedures.index')],
            ['get', route('admin.procedures.create')],
            ['post', route('admin.procedures.store'), ['code' => 'PRC-YENI', 'name' => 'Yeni']],
            ['get', route('admin.procedures.show', $procedure)],
            ['get', route('admin.procedures.edit', $procedure)],
            ['put', route('admin.procedures.update', $procedure), ['code' => 'PRC-X', 'name' => 'X']],
            ['post', route('admin.procedures.versions.store', $procedure)],
            ['get', route('admin.procedures.versions.show', [$procedure, $published])],
            ['get', route('admin.procedures.versions.show', [$procedure, $draft])],
            ['put', route('admin.procedures.versions.update', [$procedure, $draft]), ['material_required' => '1']],
            ['delete', route('admin.procedures.versions.destroy', [$procedure, $draft])],
            ['post', route('admin.procedures.versions.publish', [$procedure, $draft]), ['when' => 'now']],
            ['post', route('admin.procedures.phases.store', [$procedure, $draft]), ['name' => 'F', 'min_duration_minutes' => 1]],
            ['get', route('admin.procedures.phases.edit', [$procedure, $draft, $phase])],
            ['put', route('admin.procedures.phases.update', [$procedure, $draft, $phase]), ['name' => 'F', 'min_duration_minutes' => 1]],
            ['delete', route('admin.procedures.phases.destroy', [$procedure, $draft, $phase])],
            ['post', route('admin.procedures.phases.move', [$procedure, $draft, $phase, 'down'])],
            ['get', route('admin.procedures.steps.create', [$procedure, $draft, $phase])],
            ['post', route('admin.procedures.steps.store', [$procedure, $draft, $phase]), ['title' => 'A', 'media' => UploadedFile::fake()->create('a.jpg', 10, 'image/jpeg')]],
            ['get', route('admin.procedures.steps.edit', [$procedure, $draft, $phase, $step])],
            ['put', route('admin.procedures.steps.update', [$procedure, $draft, $phase, $step]), ['title' => 'A']],
            ['delete', route('admin.procedures.steps.destroy', [$procedure, $draft, $phase, $step])],
            ['post', route('admin.procedures.steps.move', [$procedure, $draft, $phase, $step, 'down'])],
        ];

        foreach ($requests as $request) {
            [$method, $url, $data] = $request + [2 => []];
            $this->{$method}($url, $data)->assertForbidden();
        }

        $this->assertSame(1, Procedure::count());
        $this->assertSame('PRC-TEST', $procedure->fresh()->code);
        $this->assertSame(2, $procedure->versions()->count());
        $this->assertTrue($draft->fresh()->isDraft());
        $this->assertFalse($draft->fresh()->material_required);
        $this->assertSame($before, $this->outline($draft));
    }
}
