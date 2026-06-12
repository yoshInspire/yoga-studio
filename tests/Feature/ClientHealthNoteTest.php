<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\ClassSessionStatus;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\User;
use App\Services\TrainerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientHealthNoteTest extends TestCase
{
    use RefreshDatabase;

    private function profilePayload(User $user, array $overrides = []): array
    {
        return array_merge([
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'patronymic' => $user->patronymic,
            'birth_day' => $user->birth_day ?? 1,
            'birth_month' => $user->birth_month ?? 1,
            'birth_year' => $user->birth_year,
            'phone' => $user->formattedPhone() ?? '+79991234567',
            'email' => $user->email,
        ], $overrides);
    }

    public function test_client_can_update_health_note_from_profile(): void
    {
        $client = User::factory()->create([
            'role' => UserRole::Client,
            'birth_day' => 5,
            'birth_month' => 6,
        ]);

        $response = $this->actingAs($client)->put(route('account.profile.update'), $this->profilePayload($client, [
            'health_note' => 'Гипертония, не давать перевёрнутые асаны.',
            'health_note_visible_to_trainer' => '1',
        ]));

        $response->assertRedirect(route('account'));

        $client->refresh();
        $this->assertSame('Гипертония, не давать перевёрнутые асаны.', $client->health_note);
        $this->assertTrue($client->health_note_visible_to_trainer);
    }

    public function test_client_sees_own_health_note_on_account_page(): void
    {
        $client = User::factory()->create([
            'role' => UserRole::Client,
            'health_note' => 'Сколиоз, осторожно с наклонами.',
            'health_note_visible_to_trainer' => true,
            'password' => 'secret123',
        ]);

        $this->actingAs($client)
            ->get(route('account'))
            ->assertOk()
            ->assertSee('Сколиоз, осторожно с наклонами.')
            ->assertSee('Тренер видит это примечание');
    }

    public function test_trainer_sees_health_note_only_when_client_allows(): void
    {
        $trainer = User::factory()->create([
            'role' => UserRole::Trainer,
            'first_name' => 'Ирина',
            'last_name' => 'Коленцева',
        ]);

        $visibleClient = User::factory()->create([
            'role' => UserRole::Client,
            'first_name' => 'Анна',
            'last_name' => 'Смирнова',
            'health_note' => 'Видно тренеру',
            'health_note_visible_to_trainer' => true,
        ]);

        $hiddenClient = User::factory()->create([
            'role' => UserRole::Client,
            'first_name' => 'Борис',
            'last_name' => 'Орлов',
            'health_note' => 'Скрыто от тренера',
            'health_note_visible_to_trainer' => false,
        ]);

        $weekStart = now()->startOfWeek();
        $session = ClassSession::create([
            'trainer_id' => $trainer->id,
            'topic' => 'Аэройога',
            'starts_at' => $weekStart->copy()->addDays(2)->setTime(15, 0),
            'type' => SubscriptionType::Group,
            'capacity' => 5,
            'status' => ClassSessionStatus::Scheduled,
        ]);

        foreach ([$visibleClient, $hiddenClient] as $client) {
            Booking::create([
                'user_id' => $client->id,
                'class_session_id' => $session->id,
                'status' => BookingStatus::Confirmed,
            ]);
        }

        $week = app(TrainerService::class)->buildWeekSchedule($trainer, $weekStart);
        $slots = collect($week)->flatMap(fn (array $day) => $day['slots']);
        $attendees = collect($slots->first()['attendees'] ?? []);

        $visible = $attendees->firstWhere('name', 'Анна Смирнова');
        $hidden = $attendees->firstWhere('name', 'Борис Орлов');

        $this->assertSame('Видно тренеру', $visible['health_note'] ?? null);
        $this->assertArrayNotHasKey('health_note', $hidden);
    }
}
