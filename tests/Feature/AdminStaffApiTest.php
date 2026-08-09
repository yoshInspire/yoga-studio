<?php

namespace Tests\Feature;

use App\Enums\ClassSessionStatus;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Mail\StudioNotificationMail;
use App\Models\ClassSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Сотрудники студии из приложения (ADMIN_PLAN_2.md, фаза H).
 *
 * Проверяем то, чего в веб-админке нет и что нужно именно телефону: пароль
 * администратора не уходит письмом, свою роль сменить нельзя, последнего
 * администратора не понизить, а снимок для витрины сайта не путается
 * с фотографией человека в приложении.
 */
class AdminStaffApiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $overrides = []): User
    {
        return User::factory()->create([...$overrides, 'role' => UserRole::Admin]);
    }

    private function trainer(array $overrides = []): User
    {
        return User::factory()->create([...$overrides, 'role' => UserRole::Trainer]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Мария',
            'last_name' => 'Соколова',
            'patronymic' => null,
            'phone' => '+7 (912) 000-11-22',
            'email' => 'sokolova@example.com',
            'role' => UserRole::Trainer->value,
        ], $overrides);
    }

    public function test_trainer_cannot_reach_staff(): void
    {
        $this->actingAs($this->trainer(), 'sanctum')
            ->getJson('/api/v1/admin/staff')
            ->assertForbidden();
    }

    public function test_index_shows_only_staff(): void
    {
        $this->trainer(['last_name' => 'Абрамова']);
        $this->admin(['last_name' => 'Яковлева']);
        User::factory()->create(['role' => UserRole::Client, 'last_name' => 'Клиентов']);

        $data = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/staff')
            ->assertOk()
            ->json('data');

        $names = array_column($data, 'role');
        $this->assertNotContains(UserRole::Client->value, $names);

        // Тренеры выше администраторов: с ними работают чаще.
        $this->assertSame(UserRole::Trainer->value, $data[0]['role']);

        $filtered = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/staff?role=admin')
            ->assertOk()
            ->json('data');

        $this->assertSame([UserRole::Admin->value], array_values(array_unique(array_column($filtered, 'role'))));
    }

    public function test_new_trainer_gets_access_and_appears_on_site(): void
    {
        Mail::fake();

        $id = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/staff', $this->payload())
            ->assertCreated()
            ->json('id');

        $trainer = User::findOrFail($id);

        $this->assertSame(UserRole::Trainer, $trainer->role);
        $this->assertSame('79120001122', $trainer->phone);
        // Тренера заводят ради витрины — он появляется на сайте сразу.
        $this->assertTrue($trainer->show_on_site);
    }

    public function test_new_admin_gets_no_password_letter(): void
    {
        Mail::fake();

        $id = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/staff', $this->payload([
                'role' => UserRole::Admin->value,
                'email' => 'newadmin@example.com',
            ]))
            ->assertCreated()
            ->json('id');

        $this->assertSame(UserRole::Admin, User::findOrFail($id)->role);
        Mail::assertNothingSent();
    }

    public function test_client_role_cannot_be_created_here(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/staff', $this->payload(['role' => UserRole::Client->value]))
            ->assertStatus(422);
    }

    public function test_card_is_refused_for_a_client(): void
    {
        $client = User::factory()->create(['role' => UserRole::Client]);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/staff/'.$client->id)
            ->assertStatus(422);
    }

    public function test_card_counts_upcoming_classes(): void
    {
        $trainer = $this->trainer();

        foreach ([now()->addDays(2), now()->subDays(2)] as $at) {
            ClassSession::create([
                'topic' => 'Хатха-йога',
                'trainer_id' => $trainer->id,
                'starts_at' => $at,
                'type' => SubscriptionType::Group,
                'capacity' => 6,
                'status' => ClassSessionStatus::Scheduled,
            ]);
        }

        $payload = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/staff/'.$trainer->id)
            ->assertOk()
            ->json();

        $this->assertSame(1, $payload['stats']['upcoming']);
        $this->assertSame(1, $payload['stats']['past']);
    }

    public function test_site_profile_is_saved(): void
    {
        $trainer = $this->trainer();

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/admin/staff/'.$trainer->id, $this->payload([
                'phone' => $trainer->phone,
                'email' => $trainer->email,
                'show_on_site' => false,
                'site_sort_order' => 3,
                'trainer_title' => 'Тренер · аэройога',
                'trainer_bio' => 'Ведёт аэройогу пятый год.',
            ]))
            ->assertOk()
            ->assertJsonPath('data.trainer_title', 'Тренер · аэройога');

        $trainer->refresh();
        $this->assertFalse($trainer->show_on_site);
        $this->assertSame(3, $trainer->site_sort_order);
        // Роль правкой данных не меняется — для неё отдельное действие.
        $this->assertSame(UserRole::Trainer, $trainer->role);
    }

    public function test_own_role_cannot_be_changed(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/staff/'.$admin->id.'/role', ['role' => UserRole::Trainer->value])
            ->assertStatus(422);

        $this->assertSame(UserRole::Admin, $admin->fresh()->role);
    }

    public function test_last_admin_cannot_be_demoted(): void
    {
        $admin = $this->admin();
        $other = $this->admin();

        // Пока администраторов двое — понижение проходит.
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/staff/'.$other->id.'/role', ['role' => UserRole::Trainer->value])
            ->assertOk();

        // Остался один, и он же действующий — дальше некуда.
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/staff/'.$admin->id.'/role', ['role' => UserRole::Trainer->value])
            ->assertStatus(422);

        $this->assertSame(UserRole::Admin, $admin->fresh()->role);
    }

    public function test_trainer_can_become_an_admin(): void
    {
        $trainer = $this->trainer();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/staff/'.$trainer->id.'/role', ['role' => UserRole::Admin->value])
            ->assertOk()
            ->assertJsonPath('role', UserRole::Admin->value);

        $this->assertSame(UserRole::Admin, $trainer->fresh()->role);
    }

    public function test_access_for_a_trainer_is_sent_by_letter(): void
    {
        Mail::fake();

        $trainer = $this->trainer(['email' => 'trainer@example.com']);
        $was = $trainer->password;

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/staff/'.$trainer->id.'/access')
            ->assertOk();

        $this->assertNotSame($was, $trainer->fresh()->password);
        Mail::assertSent(StudioNotificationMail::class);
    }

    public function test_access_for_an_admin_is_shown_and_not_mailed(): void
    {
        Mail::fake();

        $target = $this->admin(['email' => 'chief@example.com']);
        $adminWas = $target->password;

        $payload = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/staff/'.$target->id.'/access')
            ->assertOk()
            ->json();

        // Пароль администратора возвращается на экран и не уходит письмом:
        // почтового пути к панели студии сейчас нет даже у восстановления.
        $this->assertNotEmpty($payload['password']);
        $this->assertStringContainsString($payload['password'], $payload['message']);
        $this->assertNotSame($adminWas, $target->fresh()->password);
        Mail::assertNothingSent();
    }

    public function test_showcase_photo_is_stored_separately_from_the_avatar(): void
    {
        Storage::fake('public');
        $trainer = $this->trainer();

        $this->actingAs($this->admin(), 'sanctum')
            ->post('/api/v1/admin/staff/'.$trainer->id.'/photo', [
                'photo' => UploadedFile::fake()->image('coach.jpg', 1200, 900),
            ])
            ->assertOk();

        $trainer->refresh();
        $this->assertNotNull($trainer->trainer_photo_path);
        $this->assertStringStartsWith('trainers/', $trainer->trainer_photo_path);
        // Снимок витрины не трогает фотографию человека в приложении.
        $this->assertNull($trainer->avatar_path);
        Storage::disk('public')->assertExists($trainer->trainer_photo_path);

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson('/api/v1/admin/staff/'.$trainer->id.'/photo')
            ->assertOk();

        $this->assertNull($trainer->fresh()->trainer_photo_path);
    }

    public function test_admin_has_no_showcase(): void
    {
        Storage::fake('public');
        $target = $this->admin();

        $this->actingAs($this->admin(), 'sanctum')
            ->post('/api/v1/admin/staff/'.$target->id.'/photo', [
                'photo' => UploadedFile::fake()->image('chief.jpg', 800, 600),
            ])
            ->assertStatus(422);
    }
}
