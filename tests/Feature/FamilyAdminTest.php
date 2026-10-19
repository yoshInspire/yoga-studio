<?php

namespace Tests\Feature;

use App\Enums\ClassSessionStatus;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\FamilyMember;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BookingService;
use App\Services\FamilyService;
use App\Services\ReportService;
use App\Services\VisitControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Семья из админки: веб (Filament), API мобильной админки, журнал и отчёты.
 */
class FamilyAdminTest extends TestCase
{
    use RefreshDatabase;

    private int $phone = 0;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config([
            'studio.admin_activity_notifications.enabled' => false,
            'studio.mailings.welcome_visit.enabled' => false,
        ]);

        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
    }

    private function client(string $firstName): User
    {
        return User::create([
            'first_name' => $firstName,
            'last_name' => 'Петрова',
            'patronymic' => null,
            'phone' => '+7999200'.str_pad((string) ++$this->phone, 4, '0', STR_PAD_LEFT),
            'role' => UserRole::Client,
            'password' => 'secret123',
            'offer_accepted_at' => now(),
        ]);
    }

    private function subscription(User $user): Subscription
    {
        return Subscription::create([
            'user_id' => $user->id,
            'type' => SubscriptionType::Group,
            'sessions_total' => 8,
            'sessions_used' => 0,
            'purchased_at' => now()->subDays(3)->toDateString(),
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);
    }

    private function classAt(int $daysAhead = 2): ClassSession
    {
        return ClassSession::create([
            'topic' => 'Хатха',
            'starts_at' => now()->addDays($daysAhead)->setTime(10, 0),
            'type' => SubscriptionType::Group,
            'capacity' => 6,
            'status' => ClassSessionStatus::Scheduled,
        ]);
    }

    public function test_filament_admin_adds_member_and_opens_subscription(): void
    {
        $owner = $this->client('Анна');
        $member = $this->client('Мария');
        $sub = $this->subscription($owner);

        $this->actingAs($this->admin);

        Livewire::test(EditUser::class, ['record' => $owner->getKey()])
            ->callAction('familyAdd', ['member_id' => $member->id])
            ->assertNotified('Клиент добавлен в семью');

        $this->assertSame(2, FamilyMember::query()->count());
        $this->assertTrue(app(FamilyService::class)->membershipOf($owner)->isOwner());

        Livewire::test(EditUser::class, ['record' => $owner->getKey()])
            ->assertSee('Состав:')
            ->callAction('familyShares', ['subscription_id' => $sub->id, 'user_ids' => [(string) $member->id]])
            ->assertNotified('Доступ к абонементу сохранён');

        $this->assertTrue($sub->isUsableBy($member));

        Livewire::test(EditUser::class, ['record' => $member->getKey()])
            ->callAction('familyRemove')
            ->assertNotified('Клиент убран из семьи');

        $this->assertNull(app(FamilyService::class)->membershipOf($member));
        $this->assertFalse($sub->fresh()->isUsableBy($member));
    }

    public function test_admin_api_family_card_and_actions(): void
    {
        $owner = $this->client('Анна');
        $member = $this->client('Мария');
        $sub = $this->subscription($owner);

        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/admin/clients/'.$owner->id.'/family', ['member_id' => $member->id])
            ->assertOk()
            ->assertJsonPath('family.group.members_count', 2);

        $this->putJson('/api/v1/admin/subscriptions/'.$sub->id.'/shares', ['user_ids' => [$member->id]])
            ->assertOk()
            ->assertJsonPath('family.my_subscriptions.0.shared_with', [$member->id]);

        $this->getJson('/api/v1/admin/clients/'.$member->id)
            ->assertOk()
            ->assertJsonPath('family.shared_with_me.0.owner_name', 'Анна Петрова');

        // Запись администратором: общий абонемент виден в вариантах с пометкой.
        $session = $this->classAt();
        $this->getJson('/api/v1/admin/bookings/options?user_id='.$member->id.'&class_session_id='.$session->id)
            ->assertOk()
            ->assertJsonPath('subscriptions.0.id', $sub->id)
            ->assertJsonPath('subscriptions.0.family_owner', 'Анна Петрова')
            ->assertJsonPath('subscriptions.0.label', 'Групповой · семья: Анна Петрова');

        $this->postJson('/api/v1/admin/bookings', [
            'user_id' => $member->id,
            'class_session_id' => $session->id,
            'subscription_id' => $sub->id,
        ])->assertOk();

        $this->assertSame($sub->id, Booking::query()->sole()->subscription_id);

        $this->deleteJson('/api/v1/admin/clients/'.$member->id.'/family')
            ->assertOk()
            ->assertJsonPath('family.group', null);
    }

    public function test_journal_and_reports_name_the_family_visitor(): void
    {
        $owner = $this->client('Анна');
        $member = $this->client('Мария');
        $family = app(FamilyService::class);
        $family->addByAdmin($owner, $member);
        $sub = $this->subscription($owner);
        $family->setShares($owner, $sub, [$member->id]);

        $session = $this->classAt(daysAhead: 0);
        $session->update(['starts_at' => now()->subMinute()]);
        app(BookingService::class)->bookForAdmin($member, $session->fresh(), $sub);

        $day = app(VisitControlService::class)->buildDay(now());
        $attendee = collect($day['sessions'] ?? $day)->flatMap(fn ($s) => $s['attendees'] ?? [])->first();
        $this->assertSame('Групповой · семья: Анна Петрова', $attendee['subscription_label']);
        $this->assertSame('Анна Петрова', $attendee['family_owner']);

        $dates = app(ReportService::class)->visitDatesForSubscription($sub->fresh());
        $this->assertStringContainsString('(Мария Петрова)', $dates);
    }
}
