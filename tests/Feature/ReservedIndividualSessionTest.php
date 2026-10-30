<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\ClassSessionStatus;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Mail\StudioNotificationMail;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Индивидуальное занятие, открытое под конкретного клиента: записаться может
 * только он, остальные видят «Забронировано» (после его записи — «Мест нет»),
 * а его отмена снимает слот.
 */
class ReservedIndividualSessionTest extends TestCase
{
    use RefreshDatabase;

    private int $phone = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'studio.admin_email' => 'admin@example.com',
            'studio.admin_activity_notifications.enabled' => true,
            'studio.mailings.welcome_visit.enabled' => false,
        ]);
    }

    private function client(string $lastName, bool $withSubscription = true): User
    {
        $user = User::create([
            'first_name' => 'Клиент',
            'last_name' => $lastName,
            'phone' => '+7999000'.str_pad((string) ++$this->phone, 4, '0', STR_PAD_LEFT),
            'email' => mb_strtolower('c'.$this->phone).'@example.com',
            'role' => UserRole::Client,
            'password' => 'secret123',
            'offer_accepted_at' => now(),
        ]);

        if ($withSubscription) {
            Subscription::create([
                'user_id' => $user->id,
                'type' => SubscriptionType::Individual,
                'sessions_total' => 4,
                'sessions_used' => 0,
                'purchased_at' => now(),
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addMonth(),
            ]);
        }

        return $user;
    }

    private function individual(int $capacity = 1): ClassSession
    {
        return ClassSession::create([
            'topic' => 'Индивидуальная практика',
            'starts_at' => now()->addDays(2)->setTime(10, 0),
            'type' => SubscriptionType::Individual,
            'capacity' => $capacity,
            'status' => ClassSessionStatus::Scheduled,
        ]);
    }

    private function slotFor(ClassSession $session, ?User $viewer): array
    {
        $days = app(BookingService::class)->buildRollingSchedule(now()->startOfDay(), $viewer);

        foreach ($days as $day) {
            foreach ($day['slots'] as $slot) {
                if ($slot['id'] === $session->id) {
                    return $slot;
                }
            }
        }

        $this->fail('Занятия нет в расписании.');
    }

    public function test_open_individual_session_is_bookable_by_any_client(): void
    {
        $session = $this->individual();
        $anyone = $this->client('Любой');

        $this->assertTrue($this->slotFor($session, $anyone)['bookable']);

        $this->actingAs($anyone);
        $booking = app(BookingService::class)->book($anyone, $session);

        $this->assertTrue($booking->isConfirmed());
    }

    public function test_only_reserved_client_can_book_and_others_see_it_taken(): void
    {
        $session = $this->individual();
        $reserved = $this->client('Своя');
        $other = $this->client('Чужой');
        $session->reservedClients()->attach($reserved->id);

        $forOther = $this->slotFor($session, $other);
        $this->assertSame('full', $forOther['status']);
        $this->assertSame('Забронировано', $forOther['full_label']);
        $this->assertSame(0, $forOther['free']);
        $this->assertFalse($forOther['bookable']);

        $forGuest = $this->slotFor($session, null);
        $this->assertSame('full', $forGuest['status']);

        $forReserved = $this->slotFor($session, $reserved);
        $this->assertSame('open', $forReserved['status']);
        $this->assertTrue($forReserved['bookable']);

        $this->actingAs($other);
        try {
            app(BookingService::class)->book($other, $session);
            $this->fail('Чужой клиент записался на закреплённый слот.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Это время уже занято.', $e->getMessage());
        }

        $this->actingAs($reserved);
        $this->assertTrue(app(BookingService::class)->book($reserved, $session)->isConfirmed());

        // Клиент записался — бронь превратилась в занятое время.
        $this->assertSame('Мест нет', $this->slotFor($session, $other)['full_label']);
        $this->assertSame('Мест нет', $this->slotFor($session, null)['full_label']);
    }

    public function test_admin_can_still_book_anyone_into_reserved_slot(): void
    {
        $session = $this->individual();
        $session->reservedClients()->attach($this->client('Своя', withSubscription: false)->id);
        $other = $this->client('Чужой');

        $booking = app(BookingService::class)->bookForAdmin($other, $session);

        $this->assertTrue($booking->isConfirmed());
    }

    public function test_reserved_client_cancelling_withdraws_slot_and_admin_is_told(): void
    {
        Mail::fake();

        $session = $this->individual();
        $reserved = $this->client('Своя');
        $session->reservedClients()->attach($reserved->id);
        $booking = app(BookingService::class)->bookForAdmin($reserved, $session);

        $this->actingAs($reserved)
            ->post(route('bookings.cancel', $booking))
            ->assertRedirect();

        $session->refresh();
        $this->assertSame(ClassSessionStatus::Cancelled, $session->status);
        $this->assertSame(ClassSession::RESERVED_CLIENT_CANCELLED_REASON, $session->cancellation_reason);
        $this->assertSame(BookingStatus::CancelledByClient, $booking->fresh()->status);

        Mail::assertSent(
            StudioNotificationMail::class,
            fn (StudioNotificationMail $mail) => $mail->hasTo('admin@example.com')
                && $mail->heading === 'Отмена записи'
                && collect($mail->lines)->contains(fn (string $l) => str_contains($l, 'снято из расписания')),
        );

        // Снятое занятие в клиентском расписании больше не показывается.
        $ids = collect(app(BookingService::class)->buildRollingSchedule(now()->startOfDay(), $this->client('Новый')))
            ->flatMap(fn (array $day) => $day['slots'])
            ->pluck('id');
        $this->assertNotContains($session->id, $ids);
    }

    public function test_cancelling_open_individual_session_keeps_it_in_schedule(): void
    {
        $session = $this->individual();
        $anyone = $this->client('Любой');

        $this->actingAs($anyone);
        $booking = app(BookingService::class)->book($anyone, $session);
        app(BookingService::class)->cancelByClient($booking);

        $this->assertSame(ClassSessionStatus::Scheduled, $session->fresh()->status);
    }

    public function test_paired_session_stays_while_the_other_client_is_booked(): void
    {
        $session = $this->individual(capacity: 2);
        $first = $this->client('Первая');
        $second = $this->client('Вторая');
        $session->reservedClients()->attach([$first->id, $second->id]);

        app(BookingService::class)->bookForAdmin($first, $session);
        app(BookingService::class)->bookForAdmin($second, $session);

        $booking = Booking::query()->where('user_id', $first->id)->firstOrFail();
        $this->actingAs($first);
        app(BookingService::class)->cancelByClient($booking);

        $this->assertSame(ClassSessionStatus::Scheduled, $session->fresh()->status);

        // Второй тоже ушёл — занятие снимается.
        $booking = Booking::query()->where('user_id', $second->id)->firstOrFail();
        $this->actingAs($second);
        app(BookingService::class)->cancelByClient($booking);

        $this->assertSame(ClassSessionStatus::Cancelled, $session->fresh()->status);
    }

    public function test_rescheduling_out_of_reserved_slot_withdraws_it(): void
    {
        $session = $this->individual();
        $other = $this->individual();
        $reserved = $this->client('Своя');
        $session->reservedClients()->attach($reserved->id);
        $booking = app(BookingService::class)->bookForAdmin($reserved, $session);

        $this->actingAs($reserved);
        app(BookingService::class)->rescheduleByClient($booking, $other);

        $this->assertSame(ClassSessionStatus::Cancelled, $session->fresh()->status);
    }

    public function test_others_cannot_reschedule_into_reserved_slot(): void
    {
        $reservedSlot = $this->individual();
        $reservedSlot->reservedClients()->attach($this->client('Своя', withSubscription: false)->id);
        $open = $this->individual();
        $other = $this->client('Чужой');
        $booking = app(BookingService::class)->bookForAdmin($other, $open);

        $this->actingAs($other);
        $this->expectException(InvalidArgumentException::class);
        app(BookingService::class)->rescheduleByClient($booking, $reservedSlot);
    }

    public function test_api_create_keeps_slot_without_booking_anyone(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $withSub = $this->client('Сабонементом');
        $withoutSub = $this->client('Безабонемента', withSubscription: false);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/sessions', [
                'topic' => 'Парная практика',
                'date' => now()->addDays(3)->toDateString(),
                'time' => '11:00',
                'type' => 'individual',
                'capacity' => 2,
                'reserved_client_ids' => [$withSub->id, $withoutSub->id],
            ])
            ->assertOk();

        $session = ClassSession::query()->findOrFail($response->json('id'));

        $this->assertEqualsCanonicalizing([$withSub->id, $withoutSub->id], $session->reservedClients()->pluck('users.id')->all());
        // Абонемент есть, но запись — дело самого клиента.
        $this->assertSame(0, $session->confirmedCount());

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/sessions?offset=0')
            ->assertOk()
            ->assertJsonCount(2, 'sessions.0.reserved_clients');
    }

    public function test_api_opens_one_seat_for_a_pair_and_either_can_take_it(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $first = $this->client('Первая');
        $second = $this->client('Вторая');

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/sessions', [
                'topic' => 'Парная практика',
                'date' => now()->addDays(3)->toDateString(),
                'time' => '11:00',
                'type' => 'individual',
                'capacity' => 1,
                'reserved_client_ids' => [$first->id, $second->id],
            ])
            ->assertOk();

        $session = ClassSession::query()->findOrFail($response->json('id'));
        $this->assertSame(0, $session->confirmedCount());

        // Чья очередь платить, та и записывается; второй место уже занято.
        $this->assertTrue($this->slotFor($session, $second)['bookable']);
        $this->actingAs($second);
        app(BookingService::class)->book($second, $session);

        $this->assertFalse($this->slotFor($session, $first)['bookable']);
    }

    public function test_api_rejects_more_than_two_clients_on_one_seat(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/sessions', [
                'topic' => 'Индивидуальная',
                'date' => now()->addDays(3)->toDateString(),
                'time' => '11:00',
                'type' => 'individual',
                'capacity' => 1,
                'reserved_client_ids' => [$this->client('А')->id, $this->client('Б')->id, $this->client('В')->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reserved_client_ids');

        $this->assertSame(0, ClassSession::query()->count());
    }

    public function test_api_update_books_nobody_and_type_change_clears(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $session = $this->individual(capacity: 2);
        $cancelledEarlier = $this->client('Отменила');
        $session->reservedClients()->attach($cancelledEarlier->id);
        $added = $this->client('Новая');

        $payload = [
            'topic' => 'Парная практика',
            'date' => $session->starts_at->toDateString(),
            'time' => $session->starts_at->format('H:i'),
            'type' => 'individual',
            'capacity' => 2,
            'reserved_client_ids' => [$cancelledEarlier->id, $added->id],
        ];

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/admin/sessions/'.$session->id, $payload)
            ->assertOk();

        $this->assertTrue($session->isReservedFor($added));
        $this->assertSame(0, $session->confirmedCount());

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/admin/sessions/'.$session->id, [...$payload, 'type' => 'group', 'capacity' => 6])
            ->assertOk();

        $this->assertSame(0, $session->reservedClients()->count());
    }

    public function test_old_app_update_without_field_keeps_reservation(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $session = $this->individual();
        $session->reservedClients()->attach($this->client('Своя', withSubscription: false)->id);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/admin/sessions/'.$session->id, [
                'topic' => 'Новая тема',
                'date' => $session->starts_at->toDateString(),
                'time' => $session->starts_at->format('H:i'),
                'type' => 'individual',
                'capacity' => 1,
            ])
            ->assertOk();

        $this->assertSame(1, $session->reservedClients()->count());
    }
}
