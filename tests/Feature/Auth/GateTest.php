<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function managerOnlyAbilities(): array
    {
        return [
            'tanımları yönetme (R-42)' => ['manage-definitions'],
            'raporları görme (R-43)' => ['view-reports'],
        ];
    }

    #[DataProvider('managerOnlyAbilities')]
    public function test_manager_is_allowed(string $ability): void
    {
        $this->assertTrue(User::factory()->manager()->create()->can($ability));
    }

    #[DataProvider('managerOnlyAbilities')]
    public function test_operator_is_denied(string $ability): void
    {
        $this->assertTrue(User::factory()->create()->cannot($ability));
    }
}
