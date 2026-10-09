<?php

namespace Tests\Feature\Admin\Catalog;

use App\Models\Material;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * R-42, R-43: malzeme, iş emri ve kullanıcı tanımlarını yalnızca yönetici yönetir.
 */
class CatalogAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function endpoints(): iterable
    {
        yield 'malzeme listesi' => ['GET', 'admin.materials.index'];
        yield 'malzeme formu' => ['GET', 'admin.materials.create'];
        yield 'malzeme ekleme' => ['POST', 'admin.materials.store'];
        yield 'malzeme düzenleme' => ['GET', 'admin.materials.edit'];
        yield 'malzeme güncelleme' => ['PUT', 'admin.materials.update'];
        yield 'malzemeyi kaldırma' => ['POST', 'admin.materials.deactivate'];
        yield 'malzemeyi kullanıma alma' => ['POST', 'admin.materials.activate'];
        yield 'iş emri listesi' => ['GET', 'admin.work-orders.index'];
        yield 'iş emri formu' => ['GET', 'admin.work-orders.create'];
        yield 'iş emri ekleme' => ['POST', 'admin.work-orders.store'];
        yield 'iş emri düzenleme' => ['GET', 'admin.work-orders.edit'];
        yield 'iş emri güncelleme' => ['PUT', 'admin.work-orders.update'];
        yield 'kullanıcı listesi' => ['GET', 'admin.users.index'];
        yield 'kullanıcı formu' => ['GET', 'admin.users.create'];
        yield 'kullanıcı ekleme' => ['POST', 'admin.users.store'];
        yield 'kullanıcı düzenleme' => ['GET', 'admin.users.edit'];
        yield 'kullanıcı güncelleme' => ['PUT', 'admin.users.update'];
        yield 'şifre sıfırlama' => ['PUT', 'admin.users.password'];
        yield 'pasife alma' => ['POST', 'admin.users.deactivate'];
        yield 'aktif etme' => ['POST', 'admin.users.activate'];
    }

    #[DataProvider('endpoints')]
    public function test_operator_is_forbidden(string $method, string $route): void
    {
        $operator = User::factory()->create();
        $target = User::factory()->create(['name' => 'Hedef']);

        $this->actingAs($operator)
            ->call($method, $this->url($route, $target), ['name' => 'X', 'code' => 'X'])
            ->assertForbidden();

        $this->assertSame('Hedef', $target->fresh()->name);
        $this->assertTrue($target->fresh()->is_active);
    }

    #[DataProvider('endpoints')]
    public function test_guest_is_redirected_to_login(string $method, string $route): void
    {
        $this->call($method, $this->url($route, User::factory()->create()))->assertRedirect(route('login'));
    }

    public function test_manager_sees_the_screens_and_the_menu_items(): void
    {
        $manager = User::factory()->manager()->create();

        $page = $this->actingAs($manager)->get(route('admin.materials.index'))->assertOk();

        foreach (['admin.materials.index', 'admin.work-orders.index', 'admin.users.index'] as $route) {
            $page->assertSee('href="'.route($route).'"', false);
            $this->actingAs($manager)->get(route($route))->assertOk();
        }
    }

    public function test_operator_does_not_see_the_menu_items(): void
    {
        $page = $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk();

        foreach (['admin.materials.index', 'admin.work-orders.index', 'admin.users.index'] as $route) {
            $page->assertDontSee('href="'.route($route).'"', false);
        }
    }

    private function url(string $route, User $target): string
    {
        return match (true) {
            str_starts_with($route, 'admin.materials.') && ! in_array($route, ['admin.materials.index', 'admin.materials.create', 'admin.materials.store'], true) => route($route, Material::firstOrCreate(['code' => 'DET-01'], ['name' => 'Deterjan'])),
            str_starts_with($route, 'admin.work-orders.') && in_array($route, ['admin.work-orders.edit', 'admin.work-orders.update'], true) => route($route, WorkOrder::firstOrCreate(['code' => 'IE-1'])),
            str_starts_with($route, 'admin.users.') && ! in_array($route, ['admin.users.index', 'admin.users.create', 'admin.users.store'], true) => route($route, $target),
            default => route($route),
        };
    }
}
