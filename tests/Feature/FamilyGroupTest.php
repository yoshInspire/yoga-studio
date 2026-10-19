<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\ClassSessionStatus;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Exceptions\SharedSubscriptionRequired;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\ClientNotification;
use App\Models\FamilyGroup;
use App\Models\FamilyInvite;
use App\Models\FamilyMember;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\BookingService;
use App\Services\FamilyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Семейные группы: приглашение, состав и запись по абонементу семьи.
 * Правила студии — в шапке `FamilyService`.
 */
class FamilyGroupTest extends TestCase
{
    use RefreshDatabase;

    private int $phone = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        config([
            'studio.admin_email' => 'admin@example.com',
            'studio.admin_activity_notifications.enabled' => false,
            'studio.mailings.welcome_visit.enabled' => false,
        ]);
    }

    private function family(): FamilyService
    {
        return app(FamilyService::class);
    }

    private function client(string $firstName): User
    {
        return User::create([
            'first_name' => $firstName,
            'last_name' => 'Иванов',
            'patronymic' => null,
            'phone' => '+7999100'.str_pad((string) ++$this->phone, 4, '0', STR_PAD_LEFT),
            'email' => 'f'.$this->phone.'@example.com',
            'role' => UserRole::Client,
            'password' => 'secret123',
            'offer_accepted_at' => now(),
        ]);
    }

    private function subscription(User $user, int $total = 8, int $perDay = 1, ?string $purchasedAt = null): Subscription
    {
        return Subscription::create([
            'user_id' => $user->id,
            'type' => SubscriptionType::Group,
            'sessions_total' => $total,
            'sessions_used' => 0,
            'sessions_per_day' => $perDay,
            'purchased_at' => $purchasedAt ?? now()->subDays(3)->toDateString(),
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);
    }

    private function classAt(int $daysAhead = 2, int $hour = 10, int $capacity = 6): ClassSession
    {
        return ClassSession::create([
            'topic' => 'Хатха',
            'starts_at' => now()->addDays($daysAhead)->setTime($hour, 0),
            'type' => SubscriptionType::Group,
            'capacity' => $capacity,
            'status' => ClassSessionStatus::Scheduled,
        ]);
    }

    /** Глава и участник в одной семье. @return array{0: User, 1: User} */
    private function pair(): array
    {
        $owner = $this->client('Анна');
        $member = $this->client('Пётр');

        $invite = $this->family()->createInvite($owner);
        $this->family()->accept($invite, $member);

        return [$owner, $member];
    }

    /* ---------------- Приглашения ---------------- */

    public function test_invite_creates_group_with_inviter_as_owner(): void
    {
        $owner = $this->client('Анна');

        $invite = $this->family()->createInvite($owner);

        $this->assertMatchesRegularExpression('/^[A-Z2-9]{8}$/', $invite->code);
        $this->assertTrue($invite->expires_at->between(now()->addDays(6), now()->addDays(8)));
        $this->assertSame($owner->id, FamilyGroup::query()->sole()->owner_id);
        $this->assertTrue($this->family()->membershipOf($owner)->isOwner());
        $this->assertStringContainsString('/family/join/'.$invite->code, $this->family()->inviteUrl($invite));
    }

    public function test_member_joins_by_code_in_any_spelling_via_api(): void
    {
        $owner = $this->client('Анна');
        $member = $this->client('Пётр');
        $invite = $this->family()->createInvite($owner);
        $typed = strtolower(substr($invite->code, 0, 4).'-'.substr($invite->code, 4));

        Sanctum::actingAs($member);

        $this->getJson('/api/v1/family/join/'.$typed)
            ->assertOk()
            ->assertJsonPath('inviter_name', 'Анна Иванов')
            ->assertJsonPath('blocker', null);

        $this->postJson('/api/v1/family/join/'.$typed)
            ->assertOk()
            ->assertJsonPath('family.group.members_count', 2);

        $this->assertNotNull($invite->fresh()->accepted_at);
        $this->assertTrue(ClientNotification::query()
            ->where('user_id', $owner->id)->where('type', 'family')->exists());

        // Приглашение одноразовое.
        $third = $this->client('Маша');
        Sanctum::actingAs($third);
        $this->postJson('/api/v1/family/join/'.$invite->code)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Это приглашение уже использовано. Попросите новое.');
    }

    public function test_preview_does_not_consume_the_invite(): void
    {
        $owner = $this->client('Анна');
        $invite = $this->family()->createInvite($owner);

        $this->get('/family/join/'.$invite->code)->assertOk()->assertSee('Анна Иванов');

        $this->assertTrue($invite->fresh()->isPending());
    }

    public function test_join_is_blocked_when_expired_revoked_or_already_in_another_family(): void
    {
        $owner = $this->client('Анна');
        $guest = $this->client('Пётр');

        $expired = $this->family()->createInvite($owner);
        $expired->update(['expires_at' => now()->subMinute()]);
        $this->assertSame('Срок приглашения истёк. Попросите новое.', $this->family()->joinBlocker($expired, $guest));

        $revoked = $this->family()->createInvite($owner);
        $this->family()->revokeInvite($owner, $revoked);
        $this->assertSame('Приглашение отозвано. Попросите новое.', $this->family()->joinBlocker($revoked->fresh(), $guest));

        $other = $this->client('Олег');
        $this->family()->accept($this->family()->createInvite($other), $guest);

        $this->assertStringStartsWith(
            'Вы уже состоите в другой семье',
            $this->family()->joinBlocker($this->family()->createInvite($owner), $guest),
        );
    }

    public function test_only_clients_can_join(): void
    {
        $owner = $this->client('Анна');
        $trainer = $this->client('Тренер');
        $trainer->update(['role' => UserRole::Trainer]);

        $this->assertSame(
            'Вступить в семью может только клиент студии.',
            $this->family()->joinBlocker($this->family()->createInvite($owner), $trainer),
        );
    }

    public function test_family_is_limited_to_five_people_including_pending_invites(): void
    {
        $owner = $this->client('Анна');

        foreach (['Б', 'В', 'Г'] as $name) {
            $this->family()->accept($this->family()->createInvite($owner), $this->client($name));
        }

        $last = $this->family()->createInvite($owner);

        try {
            $this->family()->createInvite($owner);
            $this->fail('Пятое место уже обещано приглашению.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringStartsWith('Неиспользованных приглашений', $e->getMessage());
        }

        $this->family()->accept($last, $this->client('Д'));
        $this->assertSame(5, FamilyMember::query()->count());

        $this->expectExceptionMessage('В семье уже 5 человек');
        $this->family()->createInvite($owner);
    }

    public function test_only_owner_invites_and_removes(): void
    {
        [$owner, $member] = $this->pair();
        $third = $this->client('Маша');
        $this->family()->accept($this->family()->createInvite($owner), $third);

        try {
            $this->family()->createInvite($member);
            $this->fail('Участник не приглашает.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Приглашать в семью может только глава семьи.', $e->getMessage());
        }

        $this->expectExceptionMessage('Исключать из семьи может только глава.');
        $this->family()->removeMember($member, $third);
    }

    /* ---------------- Запись по абонементу семьи ---------------- */

    public function test_shared_subscription_is_never_used_silently(): void
    {
        [$owner, $member] = $this->pair();
        $sub = $this->subscription($owner);
        $this->family()->setShares($owner, $sub, [$member->id]);
        $session = $this->classAt();

        Sanctum::actingAs($member);

        $this->postJson('/api/v1/bookings', ['class_session_id' => $session->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'shared_subscription_required')
            ->assertJsonPath('sources.0.id', $sub->id)
            ->assertJsonPath('sources.0.is_own', false)
            ->assertJsonPath('sources.0.owner_name', 'Анна Иванов');

        $this->assertSame(0, Booking::query()->count());
    }

    public function test_member_books_on_shared_subscription_when_chosen(): void
    {
        [$owner, $member] = $this->pair();
        $sub = $this->subscription($owner);
        $this->family()->setShares($owner, $sub, [$member->id]);
        $session = $this->classAt();

        Sanctum::actingAs($member);

        $this->postJson('/api/v1/bookings', ['class_session_id' => $session->id, 'subscription_id' => $sub->id])
            ->assertOk();

        $booking = Booking::query()->sole();
        $this->assertSame($member->id, $booking->user_id);
        $this->assertSame($sub->id, $booking->subscription_id);
        $this->assertSame(1, $sub->fresh()->sessions_used);
        $this->assertStringEndsWith('· Пётр Иванов', $booking->subscriptionUsage->description);

        // Владелец узнаёт о записи — лентой и пушем.
        $this->assertTrue(ClientNotification::query()
            ->where('user_id', $owner->id)
            ->where('title', 'Запись по вашему абонементу')
            ->exists());

        // Член семьи видит запись с пометкой и в истории — после списания.
        $this->getJson('/api/v1/account')
            ->assertJsonPath('bookings.0.family_owner', 'Анна Иванов')
            ->assertJsonPath('history.0.sub', 'Абонемент семьи · Анна Иванов');
    }

    public function test_not_shared_subscription_cannot_be_used(): void
    {
        [$owner, $member] = $this->pair();
        $sub = $this->subscription($owner);

        $this->expectExceptionMessage('Выбранный абонемент принадлежит другому клиенту.');
        app(BookingService::class)->book($member, $this->classAt(), $sub);
    }

    public function test_own_subscription_goes_first_and_sources_list_own_then_shared(): void
    {
        [$owner, $member] = $this->pair();
        $shared = $this->subscription($owner);
        $own = $this->subscription($member, total: 4);
        $this->family()->setShares($owner, $shared, [$member->id]);
        $session = $this->classAt();

        Sanctum::actingAs($member);

        $this->getJson('/api/v1/bookings/sources?class_session_id='.$session->id)
            ->assertOk()
            ->assertJsonPath('data.0.id', $own->id)
            ->assertJsonPath('data.0.label', 'Мой групповой')
            ->assertJsonPath('data.1.id', $shared->id);

        $this->postJson('/api/v1/bookings', ['class_session_id' => $session->id])->assertOk();

        $this->assertSame($own->id, Booking::query()->sole()->subscription_id);
        $this->assertSame(0, $shared->fresh()->sessions_used);
    }

    public function test_two_family_members_on_one_class_spend_two_sessions(): void
    {
        [$owner, $member] = $this->pair();
        $third = $this->client('Маша');
        $this->family()->accept($this->family()->createInvite($owner), $third);
        $sub = $this->subscription($owner, total: 2);
        $this->family()->setShares($owner, $sub, [$member->id, $third->id]);
        $session = $this->classAt();

        app(BookingService::class)->book($owner, $session);
        app(BookingService::class)->book($member, $session, $sub);

        $this->assertSame(2, $sub->fresh()->sessions_used);

        // Занятий больше нет: третья запись по нему не пройдёт, даже если
        // в руках устаревший объект абонемента — строка перечитывается.
        $this->expectException(InvalidArgumentException::class);
        app(BookingService::class)->book($third, $session, $sub);
    }

    public function test_pending_bookings_of_different_people_share_one_count(): void
    {
        [$owner, $member] = $this->pair();
        // Абонемент начнётся послезавтра: записи ждут списания.
        $sub = $this->subscription($owner, total: 1);
        $sub->update(['starts_at' => now()->addDays(2)->toDateString()]);
        $this->family()->setShares($owner, $sub, [$member->id]);

        app(BookingService::class)->book($owner, $this->classAt(daysAhead: 3), $sub);

        $this->expectException(InvalidArgumentException::class);
        app(BookingService::class)->book($member, $this->classAt(daysAhead: 4), $sub);
    }

    public function test_double_per_day_subscription_cannot_be_shared(): void
    {
        [$owner, $member] = $this->pair();
        $double = $this->subscription($owner, perDay: 2);

        $this->expectExceptionMessage('«2 занятия в день»');
        $this->family()->setShares($owner, $double, [$member->id]);
    }

    public function test_cannot_share_with_people_outside_the_family(): void
    {
        [$owner] = $this->pair();
        $stranger = $this->client('Олег');

        $this->expectExceptionMessage('Открыть абонемент можно только участникам своей семьи.');
        $this->family()->setShares($owner, $this->subscription($owner), [$stranger->id]);
    }

    public function test_returned_own_session_pulls_booking_off_the_shared_subscription(): void
    {
        [$owner, $member] = $this->pair();
        $shared = $this->subscription($owner);
        $own = $this->subscription($member, total: 1);
        $this->family()->setShares($owner, $shared, [$member->id]);

        $first = app(BookingService::class)->book($member, $this->classAt(daysAhead: 2));
        $second = app(BookingService::class)->book($member, $this->classAt(daysAhead: 3), $shared);
        $this->assertSame($own->id, $first->subscription_id);
        $this->assertSame(1, $shared->fresh()->sessions_used);

        $this->actingAs($member);
        app(BookingService::class)->cancelByClient($first);

        $this->assertSame($own->id, $second->fresh()->subscription_id);
        $this->assertSame(0, $shared->fresh()->sessions_used);
    }

    /* ---------------- Закрытие доступа и выход ---------------- */

    public function test_closing_access_cancels_future_bookings_and_returns_sessions(): void
    {
        [$owner, $member] = $this->pair();
        $sub = $this->subscription($owner);
        $this->family()->setShares($owner, $sub, [$member->id]);
        $booking = app(BookingService::class)->book($member, $this->classAt(), $sub);
        $this->assertSame(1, $sub->fresh()->sessions_used);

        $this->family()->setShares($owner, $sub, []);

        $booking->refresh();
        $this->assertSame(BookingStatus::CancelledByAdmin, $booking->status);
        $this->assertSame('Владелец закрыл доступ к абонементу', $booking->cancellation_reason);
        $this->assertSame(0, $sub->fresh()->sessions_used);
        $this->assertTrue(ClientNotification::query()
            ->where('user_id', $member->id)->where('title', 'Доступ к абонементу закрыт')->exists());
    }

    public function test_leaving_cancels_shared_bookings_in_both_directions(): void
    {
        [$owner, $member] = $this->pair();
        $ownerSub = $this->subscription($owner);
        $memberSub = $this->subscription($member);
        $this->family()->setShares($owner, $ownerSub, [$member->id]);
        $this->family()->setShares($member, $memberSub, [$owner->id]);

        $memberOnOwner = app(BookingService::class)->book($member, $this->classAt(daysAhead: 2), $ownerSub);
        $ownerOnMember = app(BookingService::class)->book($owner, $this->classAt(daysAhead: 3), $memberSub);
        $ownOwn = app(BookingService::class)->book($owner, $this->classAt(daysAhead: 4), $ownerSub);

        $this->family()->leave($member);

        $this->assertSame(BookingStatus::CancelledByAdmin, $memberOnOwner->fresh()->status);
        $this->assertSame(BookingStatus::CancelledByAdmin, $ownerOnMember->fresh()->status);
        $this->assertSame(BookingStatus::Confirmed, $ownOwn->fresh()->status);
        $this->assertSame(1, $ownerSub->fresh()->sessions_used);
        $this->assertSame(0, $memberSub->fresh()->sessions_used);
        $this->assertSame(0, Subscription::query()->whereHas('sharedWith')->count());
        $this->assertNull($this->family()->membershipOf($member));
    }

    public function test_past_bookings_stay_when_access_is_closed(): void
    {
        [$owner, $member] = $this->pair();
        $sub = $this->subscription($owner);
        $this->family()->setShares($owner, $sub, [$member->id]);
        $booking = app(BookingService::class)->book($member, $this->classAt(), $sub);
        $booking->classSession->update(['starts_at' => now()->subHour()]);

        $this->family()->leave($member);

        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
        $this->assertSame(1, $sub->fresh()->sessions_used);
    }

    public function test_owner_leaving_hands_family_to_the_oldest_member(): void
    {
        [$owner, $member] = $this->pair();
        $this->travel(1)->minutes();
        $this->family()->accept($this->family()->createInvite($owner), $this->client('Маша'));
        $pending = $this->family()->createInvite($owner);

        $this->family()->leave($owner);

        $this->assertTrue($this->family()->membershipOf($member)->isOwner());
        $this->assertSame($member->id, FamilyGroup::query()->sole()->owner_id);
        $this->assertNotNull($pending->fresh()->revoked_at);
        $this->assertTrue(ClientNotification::query()
            ->where('user_id', $member->id)->where('title', 'Вы теперь глава семьи')->exists());
    }

    public function test_last_person_leaving_removes_the_family(): void
    {
        [$owner, $member] = $this->pair();

        $this->family()->removeMember($owner, $member);
        $this->family()->leave($owner);

        $this->assertSame(0, FamilyGroup::query()->count());
        $this->assertSame(0, FamilyInvite::query()->count());
    }

    public function test_dissolve_releases_everyone(): void
    {
        [$owner, $member] = $this->pair();
        $sub = $this->subscription($owner);
        $this->family()->setShares($owner, $sub, [$member->id]);
        $booking = app(BookingService::class)->book($member, $this->classAt(), $sub);

        Sanctum::actingAs($owner);
        $this->deleteJson('/api/v1/family')->assertOk()->assertJsonPath('family.group', null);

        $this->assertSame(0, FamilyMember::query()->count());
        $this->assertSame(BookingStatus::CancelledByAdmin, $booking->fresh()->status);
        $this->assertTrue(ClientNotification::query()
            ->where('user_id', $member->id)->where('title', 'Семья распущена')->exists());
    }

    public function test_transfer_ownership(): void
    {
        [$owner, $member] = $this->pair();

        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/family/members/'.$member->id.'/owner')
            ->assertOk()
            ->assertJsonPath('family.group.is_owner', false);

        $this->assertSame($member->id, FamilyGroup::query()->sole()->owner_id);
        $this->assertSame(1, FamilyMember::query()->where('role', FamilyMember::ROLE_OWNER)->count());
    }

    public function test_deleting_account_returns_family_sessions_and_hands_over_the_family(): void
    {
        [$owner, $member] = $this->pair();
        $memberSub = $this->subscription($member);
        $this->family()->setShares($member, $memberSub, [$owner->id]);
        $booking = app(BookingService::class)->book($owner, $this->classAt(), $memberSub);

        app(AccountDeletionService::class)->delete($owner);

        $this->assertSame(BookingStatus::CancelledByAdmin, $booking->fresh()->status);
        // Абонемент оставшегося участника живёт дальше — занятие вернулось.
        $this->assertSame(0, $memberSub->fresh()->sessions_used);
        $this->assertSame($member->id, FamilyGroup::query()->sole()->owner_id);
    }

    /* ---------------- Обзор и приватность ---------------- */

    public function test_overview_hides_contacts_and_lists_shares(): void
    {
        [$owner, $member] = $this->pair();
        $member->update(['health_note' => 'Грыжа']);
        $sub = $this->subscription($owner);
        $this->family()->setShares($owner, $sub, [$member->id]);

        Sanctum::actingAs($owner);
        $json = $this->getJson('/api/v1/family')
            ->assertOk()
            ->assertJsonPath('group.is_owner', true)
            ->assertJsonPath('my_subscriptions.0.shared_with', [$member->id])
            ->json();

        $encoded = json_encode($json, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($member->phone, $encoded);
        $this->assertStringNotContainsString('Грыжа', $encoded);
        $this->assertStringNotContainsString((string) $member->email, $encoded);

        Sanctum::actingAs($member);
        $this->getJson('/api/v1/family')
            ->assertJsonPath('shared_with_me.0.owner_name', 'Анна Иванов')
            ->assertJsonPath('group.invites', []);
    }

    /* ---------------- Сайт ---------------- */

    public function test_web_invite_page_sends_guest_to_login_and_back(): void
    {
        $owner = $this->client('Анна');
        $invite = $this->family()->createInvite($owner);

        $this->get('/family/join/'.$invite->code)
            ->assertOk()
            ->assertSee('Войти и вступить')
            ->assertSessionHas('url.intended', url('/family/join/'.$invite->code));
    }

    public function test_web_member_joins_with_button_and_sees_family_in_account(): void
    {
        $owner = $this->client('Анна');
        $member = $this->client('Пётр');
        $invite = $this->family()->createInvite($owner);

        $this->actingAs($member)
            ->get('/family/join/'.$invite->code)
            ->assertOk()
            ->assertSee('Вступить в семью');

        $this->actingAs($member)
            ->post('/family/join/'.$invite->code)
            ->assertRedirect(route('account'));

        $this->assertNotNull($this->family()->membershipOf($member));

        $sub = $this->subscription($owner);
        $this->actingAs($owner)
            ->put('/account/family/subscriptions/'.$sub->id.'/shares', ['user_ids' => [$member->id]])
            ->assertRedirect(route('account'));

        $this->actingAs($member)
            ->get('/account')
            ->assertOk()
            ->assertSee('Абонементы семьи, открытые вам')
            ->assertSee('Владелец: Анна Иванов');

        $this->actingAs($owner)
            ->get('/account')
            ->assertOk()
            ->assertSee('Открыт семье: Пётр Иванов')
            ->assertSee('Новое приглашение');
    }

    public function test_web_code_form_opens_invite(): void
    {
        $owner = $this->client('Анна');
        $invite = $this->family()->createInvite($owner);

        $this->actingAs($this->client('Пётр'))
            ->get('/family/join?code='.strtolower($invite->code))
            ->assertOk()
            ->assertSee('Вступить в семью');
    }

    public function test_web_booking_offers_family_subscription_instead_of_using_it(): void
    {
        [$owner, $member] = $this->pair();
        $sub = $this->subscription($owner);
        $this->family()->setShares($owner, $sub, [$member->id]);
        $session = $this->classAt();

        $this->actingAs($member)
            ->from('/schedule')
            ->post('/bookings', ['class_session_id' => $session->id])
            ->assertRedirect('/schedule')
            ->assertSessionHas('family_booking_offer.sources.0.id', $sub->id);

        $this->assertSame(0, Booking::query()->count());

        $this->actingAs($member)
            ->from('/schedule')
            ->post('/bookings', ['class_session_id' => $session->id, 'subscription_id' => $sub->id])
            ->assertSessionHas('status');

        $this->assertSame($sub->id, Booking::query()->sole()->subscription_id);
    }

    public function test_shared_subscription_required_is_an_invalid_argument(): void
    {
        // Старые места, которые ловят InvalidArgumentException, покажут текст.
        $this->assertTrue(is_subclass_of(SharedSubscriptionRequired::class, InvalidArgumentException::class));
    }
}
