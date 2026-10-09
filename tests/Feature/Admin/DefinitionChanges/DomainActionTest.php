<?php

namespace Tests\Feature\Admin\DefinitionChanges;

use App\Models\DefinitionChange;
use App\Models\Procedure;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * İş anlamı olan değişiklikler kendi işlem adıyla kaydedilir, "güncellendi" olarak değil:
 * makineyi kullanımdan kaldırma/yeniden kullanıma alma (K-16), versiyon yayımlama (K-15),
 * malzeme ve kullanıcıyı pasife alma/aktif etme (R-36) ve şifre sıfırlama. Şifre ve "beni
 * hatırla" anahtarının değeri günlüğe hiçbir zaman yazılmaz.
 */
class DomainActionTest extends DefinitionChangesTestCase
{
    public function test_retiring_and_reinstating_a_machine(): void
    {
        $machine = $this->makeMachine(code: 'M01');
        $this->actingAs($this->admin);

        $this->post(route('admin.machines.retire', $machine))->assertRedirect();
        $this->post(route('admin.machines.reinstate', $machine))->assertRedirect();

        [, $retired, $reinstated] = $this->changesOf($machine)->all();
        $this->assertChange('retired', ['is_active' => [true, false]], $retired);
        $this->assertChange('reinstated', ['is_active' => [false, true]], $reinstated);
        $this->assertSame($this->admin->id, $retired->actor_id);
    }

    public function test_publishing_a_draft(): void
    {
        $procedure = Procedure::create(['code' => 'PRC-YAYIN', 'name' => 'Yayın']);
        $version = $procedure->versions()->create(['version' => 1, 'material_required' => false]);
        $phase = $version->phases()->create(['sequence' => 1, 'name' => 'Faz 1', 'min_duration_seconds' => 0, 'include_gaps' => false]);
        $phase->steps()->create(['sequence' => 1, 'title' => 'Adım 1']);

        $this->actingAs($this->admin)
            ->post(route('admin.procedures.versions.publish', [$procedure, $version]), ['when' => 'now'])
            ->assertRedirect();

        $this->assertSame(['created', 'published'], $this->actionsOf($version));
        $published = $this->lastChangeOf($version);
        $this->assertChange('published', ['published_at' => [null, '2026-10-09T08:00:00+00:00']], $published);
        $this->assertSame(['procedure', $procedure->id], [$published->root_type, $published->root_id]);
        $this->assertSame('PRC-YAYIN v1', $published->subject_label);
    }

    public function test_deactivating_and_activating_a_material(): void
    {
        $material = $this->makeMaterial('DET-01');
        $this->actingAs($this->admin);

        $this->post(route('admin.materials.deactivate', $material))->assertRedirect();
        $this->post(route('admin.materials.activate', $material))->assertRedirect();

        $this->assertSame(['created', 'deactivated', 'activated'], $this->actionsOf($material));
        $this->assertChange('deactivated', ['is_active' => [true, false]], $this->changesOf($material)[1]);
    }

    public function test_deactivating_activating_and_resetting_the_password_of_a_user(): void
    {
        $user = $this->operator('Ahmet');
        $this->actingAs($this->admin);

        $this->post(route('admin.users.deactivate', $user))->assertRedirect();
        $this->post(route('admin.users.activate', $user))->assertRedirect();
        $this->put(route('admin.users.password', $user), [
            'password' => 'yeni-sifre-123',
            'password_confirmation' => 'yeni-sifre-123',
        ])->assertRedirect();

        // Şifre sıfırlamada "beni hatırla" anahtarı da yenilenir; o ayrıca kaydedilmez.
        $this->assertSame(['created', 'deactivated', 'activated', 'password_reset'], $this->actionsOf($user));
        $this->assertChange('deactivated', ['is_active' => [true, false]], $this->changesOf($user)[1]);

        $reset = $this->lastChangeOf($user);
        $this->assertNull($reset->fields);
        $this->assertSame($this->admin->id, $reset->actor_id);
    }

    public function test_password_and_remember_token_values_never_reach_the_log(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('admin.users.store'), [
            'name' => 'Ayşe Demir',
            'email' => 'ayse@demo.test',
            'role' => 'operator',
            'password' => 'ilk-sifre-123',
            'password_confirmation' => 'ilk-sifre-123',
        ])->assertRedirect();
        $user = User::where('email', 'ayse@demo.test')->firstOrFail();
        $firstHash = $user->password;

        $this->put(route('admin.users.password', $user), ['password' => 'ikinci-sifre-456', 'password_confirmation' => 'ikinci-sifre-456'])->assertRedirect();

        // Ad ve şifre birlikte değişirse ad "güncellendi", şifre yalnızca işlem olarak kaydedilir.
        $user->refresh()->forceFill(['name' => 'Ayşe Yılmaz', 'password' => 'ucuncu-sifre-789', 'remember_token' => 'hatirla-beni-token'])->save();
        $user->refresh();

        $this->assertSame(['created', 'password_reset', 'updated', 'password_reset'], $this->actionsOf($user));
        $this->assertChange('updated', ['name' => ['Ayşe Demir', 'Ayşe Yılmaz']], $this->changesOf($user)[2]);

        $raw = DB::table('definition_changes')->pluck('fields')->filter()->implode("\n");

        foreach (['ilk-sifre-123', 'ikinci-sifre-456', 'ucuncu-sifre-789', $firstHash, $user->password, 'hatirla-beni-token', '"password"', '"remember_token"'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }
    }

    public function test_remember_token_rotation_alone_records_nothing(): void
    {
        $user = $this->operator('Ahmet');

        // Giriş ve çıkışta oturum anahtarı yenilenir; tanım değişikliği değildir.
        Auth::login($user, remember: true);
        Auth::logout();
        $user->refresh()->forceFill(['remember_token' => 'baska-bir-token'])->save();

        $this->assertSame(['created'], $this->actionsOf($user));
    }

    public function test_combined_changes_are_split_into_their_actions(): void
    {
        $machine = $this->makeMachine(code: 'M01');
        $this->actingAs($this->admin);

        $machine->refresh()->update(['name' => 'Eski dolum makinesi', 'is_active' => false]);

        [, $updated, $retired] = $this->changesOf($machine)->all();
        $this->assertChange('updated', ['name' => ['Makine M01', 'Eski dolum makinesi']], $updated);
        $this->assertChange('retired', ['is_active' => [true, false]], $retired);
        $this->assertEquals($updated->occurred_at, $retired->occurred_at);
    }

    public function test_actions_are_shown_with_turkish_labels(): void
    {
        $machine = $this->makeMachine(code: 'M01');
        $this->actingAs($this->admin)->post(route('admin.machines.retire', $machine));

        $this->get(route('admin.definition-changes.index', ['action' => 'retired']))
            ->assertOk()
            ->assertSee('Kullanımdan kaldırıldı')
            ->assertSee('definition-change-action--retired', false);

        $this->assertSame(1, DefinitionChange::query()->where('action', 'retired')->count());
    }
}
