<?php

namespace Tests\Feature\Ui;

use App\Exceptions\CleaningRuleViolation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RuleViolationResponseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->post('/_test/violation', fn () => throw CleaningRuleViolation::noWorkers());
    }

    public function test_web_request_goes_back_with_message_and_rule(): void
    {
        $this->actingAs(User::factory()->create())
            ->from('/somewhere')
            ->post('/_test/violation', ['field' => 'value'])
            ->assertRedirect('/somewhere')
            ->assertSessionHasErrors(['workflow' => 'Adımda en az bir görevli olmalı.'])
            ->assertSessionHas('violation', ['rule' => 'no_workers', 'context' => []])
            ->assertSessionHasInput('field', 'value');
    }

    public function test_json_request_gets_422_with_rule(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/_test/violation')
            ->assertStatus(422)
            ->assertExactJson(['rule' => 'no_workers', 'message' => 'Adımda en az bir görevli olmalı.', 'context' => []]);
    }

    public function test_message_is_shown_by_the_layout_after_redirect(): void
    {
        $this->actingAs(User::factory()->create())
            ->from(route('dashboard'))
            ->followingRedirects()
            ->post('/_test/violation')
            ->assertOk()
            ->assertSee('Adımda en az bir görevli olmalı.');
    }
}
