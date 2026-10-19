<?php

namespace Tests\Feature;

use App\Enums\ClassSessionStatus;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Filament\Resources\ClassSessions\Pages\CreateClassSession;
use App\Filament\Resources\ClassSessions\Pages\EditClassSession;
use App\Models\ClassSession;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Поле «Для кого открыта запись» в форме занятия веб-админки.
 */
class ReservedSessionAdminFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['studio.mailings.welcome_visit.enabled' => false]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    }

    private function clientWithSubscription(): User
    {
        $client = User::factory()->create(['role' => UserRole::Client]);

        Subscription::create([
            'user_id' => $client->id,
            'type' => SubscriptionType::Individual,
            'sessions_total' => 4,
            'sessions_used' => 0,
            'purchased_at' => now(),
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);

        return $client;
    }

    private function formData(array $overrides = []): array
    {
        return array_merge([
            'topic' => 'Индивидуальная практика',
            'starts_at' => now()->addDays(2)->setTime(10, 0)->format('Y-m-d H:i:s'),
            'type' => SubscriptionType::Individual->value,
            'duration_minutes' => 60,
            'capacity' => 1,
        ], $overrides);
    }

    public function test_creating_session_for_client_books_them_right_away(): void
    {
        $client = $this->clientWithSubscription();

        Livewire::test(CreateClassSession::class)
            ->fillForm($this->formData(['reservedClients' => [$client->id]]))
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified($client->fullName().' записан(а) на занятие');

        $session = ClassSession::query()->sole();
        $this->assertTrue($session->isReservedFor($client));
        $this->assertSame(1, $session->confirmedCount());
    }

    public function test_client_without_subscription_is_kept_but_not_booked(): void
    {
        $client = User::factory()->create(['role' => UserRole::Client]);

        Livewire::test(CreateClassSession::class)
            ->fillForm($this->formData(['reservedClients' => [$client->id]]))
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified($client->fullName().' пока не записан(а)');

        $session = ClassSession::query()->sole();
        $this->assertTrue($session->isReservedFor($client));
        $this->assertSame(0, $session->confirmedCount());
    }

    public function test_more_clients_than_seats_is_rejected(): void
    {
        $a = $this->clientWithSubscription();
        $b = $this->clientWithSubscription();

        Livewire::test(CreateClassSession::class)
            ->fillForm($this->formData(['reservedClients' => [$a->id, $b->id]]))
            ->call('create')
            ->assertHasFormErrors(['reservedClients']);

        $this->assertSame(0, ClassSession::query()->count());
    }

    public function test_editing_books_only_newly_added_client(): void
    {
        $session = ClassSession::create([
            'topic' => 'Парная практика',
            'starts_at' => now()->addDays(2)->setTime(10, 0),
            'type' => SubscriptionType::Individual,
            'duration_minutes' => 60,
            'capacity' => 2,
            'status' => ClassSessionStatus::Scheduled,
        ]);
        $already = $this->clientWithSubscription();
        $session->reservedClients()->attach($already->id);
        $added = $this->clientWithSubscription();

        Livewire::test(EditClassSession::class, ['record' => $session->getKey()])
            ->fillForm(['reservedClients' => [$already->id, $added->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($session->bookings()->where('user_id', $added->id)->exists());
        $this->assertFalse($session->bookings()->where('user_id', $already->id)->exists());
    }
}
