<?php

namespace Tests\Feature\Admin\Catalog;

use App\Enums\CancelReason;
use App\Enums\UserRole;
use App\Models\User;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\TestCase;

/**
 * Kullanıcılar ve rolleri (R-36, R-40–R-43). Kullanıcı silinmez, pasife alınır. Yönetici kendini
 * pasife alamaz ve kendi yönetici rolünü kaldıramaz. Pasife alınacak kişinin açık kayıtları
 * gösterilir (K-08), ama pasife almak engellenmez.
 */
class UserManagementTest extends TestCase
{
    use BuildsCleaningFixtures, InteractsWithCleaningWorkflow, RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('08:00:00');
        $this->manager = User::factory()->manager()->create(['name' => 'Zeynep Arslan', 'email' => 'yonetici1@demo.test']);
    }

    public function test_list_shows_role_state_and_open_records(): void
    {
        $machine = $this->makeMachine(code: 'M01');
        $other = $this->makeMachine(code: 'M02');
        $ahmet = User::factory()->create(['name' => 'Ahmet Yılmaz', 'email' => 'operator1@demo.test']);
        $mehmet = User::factory()->create(['name' => 'Mehmet Kaya', 'email' => 'operator2@demo.test']);
        User::factory()->inactive()->create(['name' => 'Eski Personel', 'email' => 'operator4@demo.test']);

        // Ahmet: iki açık kaydın sahibi (biri başlamış), bir kapanmış kaydın sahibi.
        $this->openCleaning($ahmet, $machine, helpers: [$mehmet]);
        $started = $this->openCleaning($ahmet, $other);
        $this->workflow()->startStep($ahmet, $this->stepOf($started, 1));
        $closed = $this->openCleaning($ahmet, $machine);
        $this->workflow()->cancel($ahmet, $closed, CancelReason::InvalidRecord, 'Yanlış makine');

        $page = $this->page($this->actingAs($this->manager)->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('<title>Kullanıcılar', false));

        // Aktifler önce, sonra ada göre.
        $this->assertSame([
            ['Ahmet Yılmaz', 'operator1@demo.test', 'Operatör', 'Aktif', '2', '0'],
            ['Mehmet Kaya', 'operator2@demo.test', 'Operatör', 'Aktif', '0', '1'],
            ['Zeynep Arslan siz', 'yonetici1@demo.test', 'Yönetici', 'Aktif', '0', '0'],
            ['Eski Personel', 'operator4@demo.test', 'Operatör', 'Pasif', '0', '0'],
        ], array_map(
            fn (Element $row) => array_map(fn (Element $cell) => $this->text($cell), array_slice(iterator_to_array($row->querySelectorAll('td')), 0, 6)),
            iterator_to_array($page->querySelectorAll('tbody tr')),
        ));

        $this->assertSame(route('admin.users.edit', $ahmet), $page->querySelector("#user-{$ahmet->id} a")->getAttribute('href'));
    }

    public function test_assignment_counts_only_unfinished_steps_where_the_person_is_still_assigned(): void
    {
        $machine = $this->makeMachine([['steps' => 1], ['steps' => 1]], code: 'M01');
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $ayse = $this->operator('Ayşe');

        $cleaning = $this->openCleaning($ahmet, $machine, helpers: [$mehmet, $ayse]);

        // Ayşe ikinci adımdan çıkarılır, ilk adım tamamlanır: Ayşe'nin bitmemiş görevi kalmaz.
        $this->workflow()->setWorkers($ahmet, $this->stepOf($cleaning, 2), [$ahmet->id, $mehmet->id]);
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        $this->workflow()->completeStep($ahmet, $this->stepOf($cleaning, 1));

        $this->actingAs($this->manager);
        $this->assertSame(['1', '0'], $this->openCounts($ahmet));
        $this->assertSame(['0', '1'], $this->openCounts($mehmet));
        $this->assertSame(['0', '0'], $this->openCounts($ayse));
    }

    public function test_manager_creates_a_user_who_can_log_in(): void
    {
        $page = $this->page($this->actingAs($this->manager)->get(route('admin.users.create'))->assertOk());
        $this->assertSame(['operator', 'manager'], array_map(fn (Element $option) => $option->getAttribute('value'), iterator_to_array($page->querySelectorAll('#role option'))));
        $this->assertSame('operator', $page->querySelector('#role option[selected]')->getAttribute('value'));

        $this->actingAs($this->manager)->post(route('admin.users.store'), [
            'name' => 'Ali Veli',
            'email' => 'operator5@demo.test',
            'role' => 'operator',
            'password' => '1234',
            'password_confirmation' => '1234',
        ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('status', 'Kullanıcı eklendi: Ali Veli')
            ->assertSessionHasNoErrors();

        $user = User::query()->where('email', 'operator5@demo.test')->sole();
        $this->assertSame(['Ali Veli', UserRole::Operator, true], [$user->name, $user->role, $user->is_active]);
        $this->assertTrue(Hash::check('1234', $user->password));

        $this->post(route('logout'));
        $this->post(route('login.store'), ['email' => 'operator5@demo.test', 'password' => '1234'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_create_validation_messages_are_turkish(): void
    {
        $this->actingAs($this->manager)->from(route('admin.users.create'))->post(route('admin.users.store'), [])
            ->assertRedirect(route('admin.users.create'))
            ->assertSessionHasErrors([
                'name' => 'ad soyad zorunludur.',
                'email' => 'e-posta zorunludur.',
                'role' => 'rol zorunludur.',
                'password' => 'şifre zorunludur.',
            ]);

        $this->actingAs($this->manager)->from(route('admin.users.create'))->post(route('admin.users.store'), [
            'name' => 'Ali',
            'email' => 'yonetici1@demo.test',
            'role' => 'admin',
            'password' => '123',
            'password_confirmation' => '123',
        ])->assertSessionHasErrors([
            'email' => 'e-posta zaten kullanılıyor.',
            'role' => 'Seçilen rol geçersiz.',
            'password' => 'şifre en az 4 karakter olmalıdır.',
        ]);

        $this->actingAs($this->manager)->from(route('admin.users.create'))->post(route('admin.users.store'), [
            'name' => 'Ali',
            'email' => 'gecersiz',
            'role' => 'operator',
            'password' => '1234',
            'password_confirmation' => '4321',
        ])->assertSessionHasErrors([
            'email' => 'e-posta geçerli bir e-posta adresi olmalıdır.',
            'password' => 'şifre onayı eşleşmiyor.',
        ]);

        $this->assertSame(1, User::count());

        $page = $this->page($this->actingAs($this->manager)->from(route('admin.users.create'))->followingRedirects()
            ->post(route('admin.users.store'), ['name' => 'Ali', 'email' => 'yonetici1@demo.test', 'role' => 'manager'])
            ->assertOk());
        $this->assertSame('e-posta zaten kullanılıyor.', $this->text($page->getElementById('email-error')));
        $this->assertSame('Ali', $page->getElementById('name')->getAttribute('value'));
        $this->assertSame('manager', $page->querySelector('#role option[selected]')->getAttribute('value'));
        $this->assertNull($page->getElementById('password')->getAttribute('value'), 'Şifre forma geri yazılmaz.');
    }

    public function test_manager_edits_name_email_and_role(): void
    {
        $ahmet = User::factory()->create(['name' => 'Ahmet', 'email' => 'operator1@demo.test']);
        User::factory()->create(['email' => 'operator2@demo.test']);

        $page = $this->page($this->actingAs($this->manager)->get(route('admin.users.edit', $ahmet))->assertOk());
        $this->assertSame('operator1@demo.test', $page->getElementById('email')->getAttribute('value'));

        $this->actingAs($this->manager)->put(route('admin.users.update', $ahmet), [
            'name' => 'Ahmet Yılmaz', 'email' => 'operator1@demo.test', 'role' => 'manager',
        ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('status', 'Kullanıcı güncellendi: Ahmet Yılmaz');

        $this->assertSame(['Ahmet Yılmaz', UserRole::Manager], [$ahmet->fresh()->name, $ahmet->fresh()->role]);
        $this->assertTrue($ahmet->fresh()->can('manage-definitions'));

        $this->actingAs($this->manager)->from(route('admin.users.edit', $ahmet))->put(route('admin.users.update', $ahmet), [
            'name' => 'Ahmet Yılmaz', 'email' => 'operator2@demo.test', 'role' => 'operator',
        ])->assertSessionHasErrors(['email' => 'e-posta zaten kullanılıyor.']);

        $this->assertSame(UserRole::Manager, $ahmet->fresh()->role);
    }

    public function test_manager_cannot_remove_their_own_manager_role(): void
    {
        $page = $this->page($this->actingAs($this->manager)->get(route('admin.users.edit', $this->manager))->assertOk());
        $this->assertStringContainsString('Kendi yönetici rolünüzü kaldıramazsınız.', $this->text($page->getElementById('role-help')));

        $this->actingAs($this->manager)->from(route('admin.users.edit', $this->manager))->put(route('admin.users.update', $this->manager), [
            'name' => 'Zeynep', 'email' => 'yonetici1@demo.test', 'role' => 'operator',
        ])
            ->assertRedirect(route('admin.users.edit', $this->manager))
            ->assertSessionHasErrors(['role' => 'Kendi yönetici rolünüzü kaldıramazsınız.']);

        $this->assertSame(['Zeynep Arslan', UserRole::Manager], [$this->manager->fresh()->name, $this->manager->fresh()->role]);

        // Kendi adını ve e-postasını değiştirebilir.
        $this->actingAs($this->manager)->put(route('admin.users.update', $this->manager), [
            'name' => 'Zeynep A.', 'email' => 'zeynep@demo.test', 'role' => 'manager',
        ])->assertSessionHasNoErrors();
        $this->assertSame('zeynep@demo.test', $this->manager->fresh()->email);

        // Başka bir yöneticinin rolü kaldırılabilir.
        $other = User::factory()->manager()->create();
        $this->actingAs($this->manager)->put(route('admin.users.update', $other), [
            'name' => $other->name, 'email' => $other->email, 'role' => 'operator',
        ])->assertSessionHasNoErrors();
        $this->assertSame(UserRole::Operator, $other->fresh()->role);
    }

    public function test_manager_resets_a_password(): void
    {
        $ahmet = User::factory()->create(['email' => 'operator1@demo.test', 'remember_token' => 'eski-token']);

        $this->actingAs($this->manager)->from(route('admin.users.edit', $ahmet))->put(route('admin.users.password', $ahmet), [
            'password' => '12',
            'password_confirmation' => '21',
        ])->assertSessionHasErrors(['password' => 'yeni şifre en az 4 karakter olmalıdır.']);

        $this->actingAs($this->manager)->from(route('admin.users.edit', $ahmet))->put(route('admin.users.password', $ahmet), [
            'password' => 'yeni-sifre',
            'password_confirmation' => 'baska',
        ])->assertSessionHasErrors(['password' => 'yeni şifre onayı eşleşmiyor.']);

        $this->assertTrue(Hash::check('password', $ahmet->fresh()->password));

        $this->actingAs($this->manager)->put(route('admin.users.password', $ahmet), [
            'password' => 'yeni-sifre',
            'password_confirmation' => 'yeni-sifre',
        ])
            ->assertRedirect(route('admin.users.edit', $ahmet))
            ->assertSessionHas('status', "{$ahmet->name} için yeni şifre kaydedildi.");

        $ahmet->refresh();
        $this->assertTrue(Hash::check('yeni-sifre', $ahmet->password));
        $this->assertNotSame('eski-token', $ahmet->remember_token, '"Beni hatırla" çerezleri geçersiz olur.');

        $this->post(route('logout'));
        $this->post(route('login.store'), ['email' => 'operator1@demo.test', 'password' => 'yeni-sifre'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($ahmet);
    }

    public function test_deactivating_a_person_without_open_records(): void
    {
        $ahmet = $this->operator('Ahmet');

        $page = $this->page($this->actingAs($this->manager)->get(route('admin.users.edit', $ahmet))->assertOk());
        $this->assertStringContainsString('Açık kaydı ya da görevli olduğu bitmemiş adım yok.', $this->text($page->getElementById('status')));
        $form = $page->querySelector('form[action="'.route('admin.users.deactivate', $ahmet).'"]');
        $this->assertNotNull($form);
        $this->assertSame('Ahmet pasife alınacak; giriş yapamayacak ve açık oturumu kapanacak. Devam edilsin mi?', $form->getAttribute('data-confirm'));

        $this->actingAs($this->manager)->post(route('admin.users.deactivate', $ahmet))
            ->assertRedirect(route('admin.users.edit', $ahmet))
            ->assertSessionHas('status', 'Ahmet pasife alındı; açık oturumu bir sonraki işleminde kapanır.');

        $this->assertFalse($ahmet->fresh()->is_active);
        $this->assertSame(2, User::count(), 'Kullanıcı silinmez.');

        $page = $this->page($this->actingAs($this->manager)->get(route('admin.users.edit', $ahmet))->assertOk());
        $this->assertStringContainsString('Pasif', $this->text($page->getElementById('status')));
        $this->assertNull($page->querySelector('form[action="'.route('admin.users.deactivate', $ahmet).'"]'));
        $this->assertNotNull($page->querySelector('form[action="'.route('admin.users.activate', $ahmet).'"]'));

        $this->actingAs($this->manager)->post(route('admin.users.activate', $ahmet))
            ->assertRedirect(route('admin.users.edit', $ahmet))
            ->assertSessionHas('status', 'Ahmet yeniden aktif.');
        $this->assertTrue($ahmet->fresh()->is_active);
    }

    public function test_deactivation_shows_open_records_but_is_still_allowed(): void
    {
        $machine = $this->makeMachine(code: 'M01');
        $other = $this->makeMachine(code: 'M02');
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');

        $owned = $this->openCleaning($ahmet, $machine);
        $this->workflow()->startStep($ahmet, $this->stepOf($owned, 1));
        $helping = $this->openCleaning($mehmet, $other, helpers: [$ahmet]);

        $page = $this->page($this->actingAs($this->manager)->get(route('admin.users.edit', $ahmet))->assertOk());
        $status = $page->getElementById('status');

        $this->assertStringContainsString('Bu kişinin açık kayıtları var. Pasife alınması bu kayıtları kapatmaz.', $this->text($status));
        $this->assertStringContainsString('Personel ayrıldı', $this->text($status));

        // Sahibi olduğu kayıt iptal formuna, görevli olduğu kayıt kaydın sayfasına bağlanır.
        $this->assertSame(
            [route('cleanings.show', $owned).'#cancel'],
            array_map(fn (Element $link) => $link->getAttribute('href'), iterator_to_array($status->querySelectorAll('.user-status__owned a'))),
        );
        $this->assertSame($owned->record_no, $this->text($status->querySelector('.user-status__owned a')));
        $this->assertStringContainsString('Devam ediyor', $this->text($status->querySelector('.user-status__owned')));
        $this->assertSame(
            [route('cleanings.show', $helping)],
            array_map(fn (Element $link) => $link->getAttribute('href'), iterator_to_array($status->querySelectorAll('.user-status__assigned a'))),
        );
        $this->assertStringContainsString('Sorumlu: Mehmet', $this->text($status->querySelector('.user-status__assigned')));

        $this->assertStringContainsString(
            'Sahibi olduğu 1 ve görevli olduğu 1 açık kayıt kendiliğinden kapanmaz.',
            $page->querySelector('form[action="'.route('admin.users.deactivate', $ahmet).'"]')->getAttribute('data-confirm'),
        );

        $this->actingAs($this->manager)->post(route('admin.users.deactivate', $ahmet))
            ->assertRedirect(route('admin.users.edit', $ahmet))
            ->assertSessionHas('status', 'Ahmet pasife alındı; açık oturumu bir sonraki işleminde kapanır. Açık kayıtları aşağıda listelenmiştir.');
        $this->assertFalse($ahmet->fresh()->is_active);

        // Kayıtlar açık kalır ve listelenmeye devam eder; yönetici iptal edebilir (K-08).
        $page = $this->page($this->actingAs($this->manager)->get(route('admin.users.edit', $ahmet))->assertOk());
        $this->assertStringContainsString('Pasif kişinin açık kayıtları var; kendiliğinden kapanmazlar.', $this->text($page->getElementById('status')));
        $this->assertCount(1, $page->querySelectorAll('.user-status__owned li'));

        $this->actingAs($this->manager)->post(route('cleanings.cancel', $owned), [
            'cancel_reason' => CancelReason::PersonnelLeft->value,
            'cancel_note' => 'Ahmet işten ayrıldı.',
        ])->assertSessionHasNoErrors();

        $page = $this->page($this->actingAs($this->manager)->get(route('admin.users.edit', $ahmet))->assertOk());
        $this->assertNull($page->querySelector('.user-status__owned'));
        $this->assertNotNull($page->querySelector('.user-status__assigned'));
    }

    public function test_manager_cannot_deactivate_themselves(): void
    {
        $page = $this->page($this->actingAs($this->manager)->get(route('admin.users.edit', $this->manager))->assertOk());
        $this->assertNull($page->querySelector('form[action="'.route('admin.users.deactivate', $this->manager).'"]'));
        $this->assertStringContainsString('Kendi hesabınızı pasife alamazsınız.', $this->text($page->getElementById('status')));

        $this->actingAs($this->manager)->from(route('admin.users.edit', $this->manager))->post(route('admin.users.deactivate', $this->manager))
            ->assertRedirect(route('admin.users.edit', $this->manager))
            ->assertSessionHasErrors(['is_active' => 'Kendi hesabınızı pasife alamazsınız.']);

        $this->assertTrue($this->manager->fresh()->is_active);
        $this->assertAuthenticatedAs($this->manager);

        $page = $this->page($this->actingAs($this->manager)->from(route('admin.users.edit', $this->manager))->followingRedirects()
            ->post(route('admin.users.deactivate', $this->manager))
            ->assertOk());
        $this->assertSame('Kendi hesabınızı pasife alamazsınız.', $this->text($page->querySelector('.user-status__error')));
    }

    public function test_deactivated_user_is_logged_out_on_the_next_request(): void
    {
        $ahmet = $this->operator('Ahmet');
        $managerSession = $this->manager;

        // Ahmet'in oturumu açık.
        $this->actingAs($ahmet)->get(route('dashboard'))->assertOk();

        // Yönetici pasife alır (aynı test istemcisinde oturum kullanıcısını değiştirerek).
        $this->actingAs($managerSession)->post(route('admin.users.deactivate', $ahmet))->assertSessionHasNoErrors();

        // Ahmet'in bir sonraki isteği oturumu kapatır.
        $this->actingAs($ahmet->fresh())->get(route('cleanings.index'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Hesabınız pasif. Yöneticinize başvurun.']);
        $this->assertGuest();

        // Pasif kişi yeniden giriş yapamaz.
        $this->post(route('login.store'), ['email' => $ahmet->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    // ---------------------------------------------------------------------------------------

    /**
     * Kullanıcı listesindeki "sahibi olduğu" ve "görevli olduğu" açık kayıt sayıları.
     *
     * @return array{0: string, 1: string}
     */
    private function openCounts(User $user): array
    {
        $page = $this->page($this->get(route('admin.users.index'))->assertOk());

        return [
            $this->text($page->querySelector("#user-{$user->id} .user-list__owned")),
            $this->text($page->querySelector("#user-{$user->id} .user-list__assigned")),
        ];
    }

    private function page(TestResponse $response): HTMLDocument
    {
        return HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR);
    }

    private function text(Element $element): string
    {
        return trim(preg_replace('/\s+/u', ' ', $element->textContent));
    }
}
