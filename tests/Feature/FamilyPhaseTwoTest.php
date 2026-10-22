<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\ClassSessionStatus;
use App\Enums\SubscriptionType;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\ClientNotification;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\BookingService;
use App\Services\ClientAccessService;
use App\Services\FamilyService;
use App\Services\NotificationService;
use App\Services\PaymentService;
use App\Services\YooKassaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;
use YooKassa\Model\Metadata;
use YooKassa\Model\MonetaryAmount;
use YooKassa\Model\Payment\Confirmation\ConfirmationRedirect;
use YooKassa\Model\Payment\PaymentInterface;
use YooKassa\Model\Payment\PaymentStatus as YooPaymentStatus;
use YooKassa\Request\Payments\CreatePaymentResponse;

/**
 * Семейные группы, фаза 2 (docs/FAMILY_GROUPS.md, раздел 16): лимит участнику,
 * запись члена семьи, подарок, дети без аккаунта.
 */
class FamilyPhaseTwoTest extends TestCase
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

    private function bookings(): BookingService
    {
        return app(BookingService::class);
    }

    private function client(string $firstName): User
    {
        return User::create([
            'first_name' => $firstName,
            'last_name' => 'Иванов',
            'patronymic' => null,
            'phone' => '+7999200'.str_pad((string) ++$this->phone, 4, '0', STR_PAD_LEFT),
            'email' => 'p'.$this->phone.'@example.com',
            'role' => UserRole::Client,
            'password' => 'secret123',
            'offer_accepted_at' => now(),
        ]);
    }

    private function subscription(User $user, int $total = 8): Subscription
    {
        return Subscription::create([
            'user_id' => $user->id,
            'type' => SubscriptionType::Group,
            'sessions_total' => $total,
            'sessions_used' => 0,
            'sessions_per_day' => 1,
            'purchased_at' => now()->subDays(3)->toDateString(),
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

    /** @return array{0: User, 1: User} */
    private function pair(): array
    {
        $owner = $this->client('Анна');
        $member = $this->client('Пётр');

        $this->family()->accept($this->family()->createInvite($owner), $member);

        return [$owner, $member];
    }

    /* ---------------- А. Лимит занятий участнику ---------------- */

    public function test_limit_reached_blocks_booking_and_hides_subscription(): void
    {
        [$owner, $member] = $this->pair();
        $sub = $this->subscription($owner);
        $this->family()->setShares($owner, $sub, [$member->id => 1]);

        $this->bookings()->book($member, $this->classAt(daysAhead: 1), $sub);

        $next = $this->classAt(daysAhead: 2);
        $this->assertSame([], $this->bookings()->bookingSources($member, $next));

        try {
            $this->bookings()->book($member, $next, $sub);
            $this->fail('Лимит должен был остановить запись.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Владелец открыл вам этот абонемент на 1 занятие — они уже использованы.', $e->getMessage());
        }

        // Сам владелец лимитом не ограничен.
        $this->bookings()->book($owner, $next, $sub);
        $this->assertSame(2, $sub->fresh()->sessions_used);
    }

    public function test_cancel_returns_limit_and_no_show_does_not_count(): void
    {
        [$owner, $member] = $this->pair();
        $sub = $this->subscription($owner);
        $this->family()->setShares($owner, $sub, [$member->id => 2]);

        $first = $this->bookings()->book($member, $this->classAt(daysAhead: 1), $sub);
        $this->assertSame(1, $sub->fresh()->shareLimitLeftFor($member));

        $this->actingAs($member);
        $this->bookings()->cancelByClient($first);
        $this->assertSame(2, $sub->fresh()->shareLimitLeftFor($member));

        $second = $this->bookings()->book($member, $this->classAt(daysAhead: 3), $sub);
        $this->bookings()->markNoShow($second);
        $this->assertSame(2, $sub->fresh()->shareLimitLeftFor($member));

        $sources = $this->bookings()->bookingSources($member, $this->classAt(daysAhead: 4));
        $this->assertSame(2, $sources[0]['limit_left']);
    }

    public function test_lowering_limit_keeps_existing_bookings(): void
    {
        [$owner, $member] = $this->pair();
        $sub = $this->subscription($owner);
        $this->family()->setShares($owner, $sub, [$member->id]);

        $a = $this->bookings()->book($member, $this->classAt(daysAhead: 1), $sub);
        $b = $this->bookings()->book($member, $this->classAt(daysAhead: 2), $sub);

        $this->family()->setShares($owner, $sub, [$member->id => 1]);

        $this->assertTrue($a->fresh()->isConfirmed());
        $this->assertTrue($b->fresh()->isConfirmed());
        $this->assertSame(0, $sub->fresh()->shareLimitLeftFor($member));
        $this->assertTrue(ClientNotification::query()
            ->where('user_id', $member->id)->where('title', 'Изменён доступ к абонементу семьи')->exists());

        $this->expectException(InvalidArgumentException::class);
        $this->bookings()->book($member, $this->classAt(daysAhead: 3), $sub);
    }

    public function test_api_accepts_old_and_new_share_formats(): void
    {
        [$owner, $member] = $this->pair();
        $sub = $this->subscription($owner);

        Sanctum::actingAs($owner);

        $this->putJson('/api/v1/family/subscriptions/'.$sub->id.'/shares', ['user_ids' => [$member->id]])
            ->assertOk();
        $this->assertNull($sub->sharedWith()->sole()->pivot->sessions_limit);

        $this->putJson('/api/v1/family/subscriptions/'.$sub->id.'/shares', [
            'shares' => [['user_id' => $member->id, 'limit' => 3]],
        ])->assertOk();
        $this->assertSame(3, (int) $sub->sharedWith()->sole()->pivot->sessions_limit);

        $this->getJson('/api/v1/family')
            ->assertJsonPath('my_subscriptions.0.shared_with', [$member->id])
            ->assertJsonPath('my_subscriptions.0.limits.'.$member->id, 3);

        // Пустой список закрывает доступ, отсутствие списка — ошибка.
        $this->putJson('/api/v1/family/subscriptions/'.$sub->id.'/shares', [])->assertStatus(422);
        $this->putJson('/api/v1/family/subscriptions/'.$sub->id.'/shares', ['user_ids' => []])->assertOk();
        $this->assertSame(0, $sub->sharedWith()->count());

        Sanctum::actingAs($member);
        $this->family()->setShares($owner, $sub, [$member->id => 4]);
        $this->getJson('/api/v1/family')
            ->assertJsonPath('shared_with_me.0.limit', 4)
            ->assertJsonPath('shared_with_me.0.limit_left', 4);
    }

    public function test_web_form_saves_limits_and_skips_unchecked_rows(): void
    {
        [$owner, $member] = $this->pair();
        $third = $this->client('Маша');
        $this->family()->accept($this->family()->createInvite($owner), $third);
        $sub = $this->subscription($owner);

        $this->actingAs($owner)
            ->put('/account/family/subscriptions/'.$sub->id.'/shares', [
                'shares' => [
                    1 => ['user_id' => $member->id, 'limit' => '2'],
                    2 => ['limit' => '5'],
                ],
            ])
            ->assertRedirect();

        $shared = $sub->sharedWith()->get();
        $this->assertSame([$member->id], $shared->pluck('id')->all());
        $this->assertSame(2, (int) $shared->first()->pivot->sessions_limit);
    }

    /* ---------------- В. Абонемент в подарок ---------------- */

    /**
     * ЮKassa без сети: запоминаем отправленный запрос и отдаём ссылку на оплату.
     *
     * @return \ArrayObject<string, mixed> сюда ляжет payload createPayment
     */
    private function fakeYooKassa(): \ArrayObject
    {
        $sent = new \ArrayObject;

        $this->mock(YooKassaService::class, function ($mock) use ($sent) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createPayment')->andReturnUsing(function (array $payload) use ($sent) {
                $sent['payload'] = $payload;

                $confirmation = Mockery::mock(ConfirmationRedirect::class);
                $confirmation->shouldReceive('getConfirmationUrl')->andReturn('https://yookassa.test/pay');

                $response = Mockery::mock(CreatePaymentResponse::class);
                $response->shouldReceive('getId')->andReturn('yk-'.uniqid());
                $response->shouldReceive('getStatus')->andReturn(YooPaymentStatus::PENDING);
                $response->shouldReceive('getConfirmation')->andReturn($confirmation);

                return $response;
            });
        });

        return $sent;
    }

    private function succeed(Payment $payment): Subscription
    {
        $metadata = new Metadata;
        $metadata->offsetSet('payment_id', (string) $payment->id);

        $remote = Mockery::mock(PaymentInterface::class);
        $remote->shouldReceive('getStatus')->andReturn(YooPaymentStatus::SUCCEEDED);
        $remote->shouldReceive('getMetadata')->andReturn($metadata);
        $remote->shouldReceive('getAmount')->andReturn(
            new MonetaryAmount(number_format($payment->amount, 2, '.', ''), $payment->currency),
        );

        return app(PaymentService::class)->fulfill($payment, $remote);
    }

    public function test_gift_goes_to_recipient_and_receipt_to_payer(): void
    {
        $sent = $this->fakeYooKassa();
        [$anna, $petr] = $this->pair();

        Sanctum::actingAs($anna);

        $this->postJson('/api/v1/purchase', [
            'product_key' => 'group_4',
            'starts_at' => now()->toDateString(),
            'recipient_id' => $petr->id,
        ])->assertOk()->assertJsonPath('confirmation_url', 'https://yookassa.test/pay');

        $payment = Payment::query()->sole();
        $this->assertSame($anna->id, $payment->user_id);
        $this->assertSame($petr->id, $payment->recipient_id);
        $this->assertSame((string) $petr->id, $sent['payload']['metadata']['recipient_id']);
        $this->assertStringContainsString('в подарок: Иванов Пётр', $sent['payload']['description']);
        // Чек — покупателю: контакт Анны, не Петра.
        $this->assertSame($anna->fullName(), $sent['payload']['receipt']['customer']['full_name']);

        $subscription = $this->succeed($payment);
        $again = $this->succeed($payment->fresh());

        $this->assertSame($subscription->id, $again->id);
        $this->assertSame($petr->id, $subscription->user_id);
        $this->assertSame(0, $anna->subscriptions()->count());
        $this->assertStringStartsWith('Подарок от '.$anna->fullName(), $subscription->admin_note);
        $this->assertSame($anna->id, $subscription->giftFrom()?->id);
        $this->assertSame(1, ClientNotification::query()
            ->where('user_id', $petr->id)->where('title', 'Вам подарили абонемент')->count());

        Sanctum::actingAs($petr);
        $this->getJson('/api/v1/account')->assertJsonPath('subscriptions.0.gift_from', 'Анна Иванов');
    }

    public function test_trial_cannot_be_gifted_and_strangers_cannot_receive(): void
    {
        $this->fakeYooKassa();
        [$anna, $petr] = $this->pair();
        $stranger = $this->client('Олег');
        $trial = (array) config('purchases.once_per_client');

        Sanctum::actingAs($anna);

        if ($trial !== []) {
            $this->postJson('/api/v1/purchase', [
                'product_key' => $trial[0],
                'starts_at' => now()->toDateString(),
                'recipient_id' => $petr->id,
            ])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'в подарок не продаётся'));
        }

        $this->postJson('/api/v1/purchase', [
            'product_key' => 'group_4',
            'starts_at' => now()->toDateString(),
            'recipient_id' => $stranger->id,
        ])->assertStatus(422)->assertJsonPath('message', 'Подарить абонемент можно только участнику своей семьи.');

        $this->assertSame(0, Payment::query()->count());
    }

    public function test_web_purchase_offers_recipients(): void
    {
        $this->fakeYooKassa();
        [$anna, $petr] = $this->pair();

        $this->actingAs($anna)->get('/purchase')->assertOk()->assertSee('В подарок: Пётр Иванов');

        $this->actingAs($anna)
            ->post('/purchase', ['product_key' => 'group_4', 'starts_at' => now()->toDateString(), 'recipient_id' => $petr->id])
            ->assertRedirect('https://yookassa.test/pay');

        $this->assertSame($petr->id, Payment::query()->sole()->recipient_id);
    }

    /* ---------------- Г. Дети без своего аккаунта ---------------- */

    private function child(User $guardian, string $name = 'Маша'): User
    {
        return $this->family()->addChild($guardian, [
            'first_name' => $name,
            'last_name' => 'Иванова',
            'birth_date' => now()->subYears(9)->toDateString(),
            'health_note' => 'Астма',
        ]);
    }

    public function test_adult_adds_child_who_counts_in_family_and_cannot_log_in(): void
    {
        $anna = $this->client('Анна');

        Sanctum::actingAs($anna);

        $this->postJson('/api/v1/family/children', [
            'first_name' => 'Маша', 'last_name' => 'Иванова', 'birth_date' => now()->subYears(9)->toDateString(),
        ])->assertStatus(422);

        $this->postJson('/api/v1/family/children', [
            'first_name' => 'Маша', 'last_name' => 'Иванова',
            'birth_date' => now()->subYears(9)->toDateString(), 'guardian_consent' => true,
        ])->assertOk()
            ->assertJsonPath('family.group.members_count', 2)
            ->assertJsonPath('family.group.members.1.is_child', true)
            ->assertJsonPath('family.group.members.1.guardian_name', 'Анна Иванов')
            ->assertJsonPath('family.group.members.1.child.first_name', 'Маша');

        $masha = User::query()->where('first_name', 'Маша')->sole();
        $this->assertSame($anna->id, $masha->guardian_id);
        $this->assertNull($masha->phone);
        $this->assertNull($masha->email);
        $this->assertNotNull($masha->offer_accepted_at);
        $this->assertTrue($this->family()->membershipOf($anna)->isOwner());
        $this->assertFalse(User::query()->reachableClients()->whereKey($masha->id)->exists());

        // Ребёнок — в лимите пяти.
        foreach (['Б', 'В', 'Г'] as $n) {
            $this->child($anna, $n);
        }
        $this->expectExceptionMessage('ребёнка добавить нельзя');
        $this->child($anna, 'Д');
    }

    public function test_child_notifications_go_to_guardian_with_childs_name(): void
    {
        $anna = $this->client('Анна');
        $masha = $this->child($anna);

        app(NotificationService::class)->notifyUser($masha, 'Напоминание о занятии', [
            'Здравствуйте, Маша!', 'Завтра в 10:00 — Хатха.',
        ], subject: 'Напоминание');

        $this->assertFalse(ClientNotification::query()->where('user_id', $masha->id)->exists());
        $note = ClientNotification::query()->where('user_id', $anna->id)->sole();
        $this->assertSame('Маша: Напоминание о занятии', $note->title);
        $this->assertStringContainsString('Здравствуйте, Анна!', $note->body);
        $this->assertStringContainsString('Маша Иванова', $note->body);
    }

    public function test_child_gets_no_access_and_guardian_books_and_cancels(): void
    {
        $anna = $this->client('Анна');
        $masha = $this->child($anna);
        $sub = $this->subscription($masha);

        try {
            app(ClientAccessService::class)->sendTemporaryPassword($masha);
            $this->fail('Ребёнку без входа доступ не выдаётся.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('профиль ребёнка', $e->getMessage());
        }

        Sanctum::actingAs($anna);
        $session = $this->classAt();

        $this->postJson('/api/v1/bookings', ['class_session_id' => $session->id, 'for_user_id' => $masha->id])->assertOk();

        $booking = Booking::query()->sole();
        $this->assertSame($masha->id, $booking->user_id);
        $this->assertSame(1, $sub->fresh()->sessions_used);
        // Представитель сам записал — «Вас записали» себе он не получает.
        $this->assertFalse(ClientNotification::query()->where('title', 'like', '%Вас записали%')->exists());

        $this->getJson('/api/v1/account')->assertJsonPath('family_bookings.0.for_name', 'Маша Иванова');
        $this->postJson('/api/v1/bookings/'.$booking->id.'/cancel')->assertOk();
        $this->assertSame(0, $sub->fresh()->sessions_used);

        // Ростер тренера и журнал видят пометку «ребёнок».
        $this->assertTrue($masha->fresh()->isManagedProfile());
    }

    public function test_guardian_edits_and_others_cannot(): void
    {
        [$anna, $petr] = $this->pair();
        $masha = $this->child($anna);

        Sanctum::actingAs($petr);
        $this->putJson('/api/v1/family/children/'.$masha->id, [
            'first_name' => 'Мария', 'last_name' => 'Иванова', 'birth_date' => now()->subYears(9)->toDateString(),
        ])->assertStatus(422);

        Sanctum::actingAs($anna);
        $this->putJson('/api/v1/family/children/'.$masha->id, [
            'first_name' => 'Мария', 'last_name' => 'Иванова', 'birth_date' => now()->subYears(10)->toDateString(),
            'health_note' => '', 'health_note_visible_to_trainer' => false,
        ])->assertOk();

        $masha->refresh();
        $this->assertSame('Мария', $masha->first_name);
        $this->assertNull($masha->health_note);
        $this->assertFalse((bool) $masha->health_note_visible_to_trainer);
    }

    public function test_child_leaves_with_guardian_and_never_becomes_owner(): void
    {
        [$anna, $petr] = $this->pair();
        $masha = $this->child($petr);

        $this->family()->leave($petr);
        $this->assertNull($this->family()->membershipOf($masha));
        $this->assertSame($petr->id, $masha->fresh()->guardian_id);

        // Глава уходит, остаётся только ребёнок другого взрослого — нет: ребёнок уходит с ним.
        $kid = $this->child($anna, 'Коля');
        $this->family()->leave($anna);
        $this->assertNull($this->family()->membershipOf($kid));
        $this->assertSame(0, \App\Models\FamilyGroup::query()->count());
    }

    public function test_deleting_guardian_hands_child_to_oldest_adult_or_anonymizes(): void
    {
        [$anna, $petr] = $this->pair();
        $masha = $this->child($petr);

        app(AccountDeletionService::class)->delete($petr);

        $masha->refresh();
        $this->assertSame($anna->id, $masha->guardian_id);
        $this->assertNotNull($this->family()->membershipOf($masha));
        $this->assertTrue(ClientNotification::query()
            ->where('user_id', $anna->id)->where('title', 'Вы теперь представитель ребёнка')->exists());

        $solo = $this->client('Олег');
        $kid = $this->child($solo, 'Коля');

        app(AccountDeletionService::class)->delete($solo);

        $kid->refresh();
        $this->assertTrue($kid->isAnonymized());
        $this->assertNull($kid->guardian_id);
    }

    public function test_admin_separates_grown_child(): void
    {
        $anna = $this->client('Анна');
        $masha = $this->child($anna);
        $admin = User::create([
            'first_name' => 'Ирина', 'last_name' => 'Админ', 'phone' => '+79990000001',
            'email' => 'admin@example.com', 'role' => UserRole::Admin, 'password' => 'secret123',
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/clients/'.$masha->id)
            ->assertJsonPath('client.is_child', true)
            ->assertJsonPath('client.guardian.id', $anna->id);

        $this->postJson('/api/v1/admin/clients/'.$masha->id.'/separate', ['phone' => '+7 999 555-44-33'])
            ->assertOk()
            ->assertJsonPath('client.is_child', false);

        $masha->refresh();
        $this->assertSame('79995554433', $masha->phone);
        $this->assertNull($masha->guardian_id);
        $this->assertNotNull($this->family()->membershipOf($masha));
    }

    public function test_web_account_adds_child(): void
    {
        $anna = $this->client('Анна');

        $this->actingAs($anna)->get('/account')->assertSee('Добавить ребёнка');

        $this->actingAs($anna)->post('/account/family/children', [
            'first_name' => 'Маша', 'last_name' => 'Иванова', 'birth_date' => now()->subYears(9)->toDateString(),
        ])->assertSessionHasErrors('family');

        $this->actingAs($anna)->post('/account/family/children', [
            'first_name' => 'Маша', 'last_name' => 'Иванова',
            'birth_date' => now()->subYears(9)->toDateString(), 'guardian_consent' => '1',
        ])->assertSessionHas('family_status');

        $this->actingAs($anna)->get('/account')
            ->assertSee('Ребёнок · представитель: Анна Иванов')
            ->assertSee('Изменить профиль');
    }

    /* ---------------- Б. Записать члена семьи ---------------- */

    public function test_member_books_relative_on_relatives_own_subscription(): void
    {
        [$anna, $petr] = $this->pair();
        $petrSub = $this->subscription($petr);
        $session = $this->classAt();

        Sanctum::actingAs($anna);

        $this->getJson('/api/v1/bookings/sources?class_session_id='.$session->id.'&for_user_id='.$petr->id)
            ->assertOk()
            ->assertJsonPath('data.0.id', $petrSub->id)
            ->assertJsonPath('data.0.label', 'Свой абонемент · Пётр Иванов');

        $this->postJson('/api/v1/bookings', ['class_session_id' => $session->id, 'for_user_id' => $petr->id])
            ->assertOk()
            ->assertJsonPath('message', 'Пётр Иванов записан(а) на занятие «Хатха».');

        $booking = Booking::query()->sole();
        $this->assertSame($petr->id, $booking->user_id);
        $this->assertSame($anna->id, $booking->booked_by_id);
        $this->assertSame(1, $petrSub->fresh()->sessions_used);
        $this->assertTrue(ClientNotification::query()
            ->where('user_id', $petr->id)->where('title', 'Вас записали на занятие')->exists());

        // Анна видит запись в «Записях семьи», Пётр — у себя с пометкой.
        $this->getJson('/api/v1/account')
            ->assertJsonPath('bookings', [])
            ->assertJsonPath('family_bookings.0.for_name', 'Пётр Иванов')
            ->assertJsonPath('family.relatives.0.id', $petr->id);

        Sanctum::actingAs($petr);
        $this->getJson('/api/v1/account')->assertJsonPath('bookings.0.booked_by_name', 'Анна Иванов');
    }

    public function test_bookers_own_subscription_not_shared_with_attendee_is_refused(): void
    {
        [$anna, $petr] = $this->pair();
        $annaSub = $this->subscription($anna);
        $session = $this->classAt();

        $this->expectExceptionMessage('Выбранный абонемент принадлежит другому клиенту.');
        $this->bookings()->bookFor($anna, $petr, $session, $annaSub);
    }

    public function test_booker_pays_with_own_subscription_once_shared(): void
    {
        [$anna, $petr] = $this->pair();
        $annaSub = $this->subscription($anna);
        $this->family()->setShares($anna, $annaSub, [$petr->id]);
        $session = $this->classAt();

        Sanctum::actingAs($anna);

        $this->getJson('/api/v1/bookings/sources?class_session_id='.$session->id.'&for_user_id='.$petr->id)
            ->assertJsonPath('data.0.label', 'Мой групповой');

        $this->postJson('/api/v1/bookings', [
            'class_session_id' => $session->id,
            'for_user_id' => $petr->id,
            'subscription_id' => $annaSub->id,
        ])->assertOk();

        $this->assertSame(1, $annaSub->fresh()->sessions_used);
        // Владелец сам и записал — уведомления «по вашему абонементу» нет.
        $this->assertFalse(ClientNotification::query()
            ->where('user_id', $anna->id)->where('title', 'Запись по вашему абонементу')->exists());
    }

    public function test_relatives_day_limit_and_reserved_slot_apply(): void
    {
        [$anna, $petr] = $this->pair();
        $this->subscription($petr);

        $this->bookings()->book($petr, $this->classAt(daysAhead: 2, hour: 8));
        $this->bookings()->book($petr, $this->classAt(daysAhead: 2, hour: 10));

        try {
            $this->bookings()->bookFor($anna, $petr, $this->classAt(daysAhead: 2, hour: 12));
            $this->fail('Лимит дня идущего должен был остановить запись.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('максимум', $e->getMessage());
        }

        $reserved = $this->classAt(daysAhead: 3);
        $reserved->reservedClients()->attach($anna->id);

        $this->expectExceptionMessage('Это время уже занято.');
        $this->bookings()->bookFor($anna, $petr, $reserved->fresh());
    }

    public function test_stranger_cannot_be_booked_or_inspected(): void
    {
        [$anna] = $this->pair();
        $stranger = $this->client('Олег');
        $this->subscription($stranger);
        $session = $this->classAt();

        Sanctum::actingAs($anna);

        $this->getJson('/api/v1/bookings/sources?class_session_id='.$session->id.'&for_user_id='.$stranger->id)
            ->assertStatus(422);
        $this->postJson('/api/v1/bookings', ['class_session_id' => $session->id, 'for_user_id' => $stranger->id])
            ->assertStatus(422);

        $this->expectExceptionMessage('Записать можно только участника своей семьи.');
        $this->bookings()->bookFor($anna, $stranger, $session);
    }

    public function test_booker_and_attendee_can_cancel_and_the_other_is_told(): void
    {
        [$anna, $petr] = $this->pair();
        $outsider = $this->client('Олег');
        $sub = $this->subscription($petr);

        $first = $this->bookings()->bookFor($anna, $petr, $this->classAt(daysAhead: 3));

        Sanctum::actingAs($outsider);
        $this->postJson('/api/v1/bookings/'.$first->id.'/cancel')->assertStatus(403);

        Sanctum::actingAs($anna);
        $this->postJson('/api/v1/bookings/'.$first->id.'/cancel')->assertOk();
        $this->assertSame(BookingStatus::CancelledByClient, $first->fresh()->status);
        $this->assertSame(0, $sub->fresh()->sessions_used);
        $this->assertTrue(ClientNotification::query()
            ->where('user_id', $petr->id)->where('title', 'Запись отменена')->exists());

        $second = $this->bookings()->bookFor($anna, $petr, $this->classAt(daysAhead: 4));

        Sanctum::actingAs($petr);
        $this->postJson('/api/v1/bookings/'.$second->id.'/cancel')->assertOk();
        $this->assertTrue(ClientNotification::query()
            ->where('user_id', $anna->id)->where('title', 'Запись отменена')->exists());
    }

    public function test_web_books_relative_from_schedule(): void
    {
        [$anna, $petr] = $this->pair();
        $this->subscription($petr);
        $session = $this->classAt();

        $this->actingAs($anna)->get('/schedule')->assertOk()->assertSee('"family":[{"id":'.$petr->id, false);

        $this->actingAs($anna)
            ->from('/schedule')
            ->post('/bookings', ['class_session_id' => $session->id, 'for_user_id' => $petr->id])
            ->assertSessionHas('status', 'Пётр Иванов записан(а) на занятие «Хатха».');

        $this->assertSame($anna->id, Booking::query()->sole()->booked_by_id);

        $this->actingAs($anna)->get('/account')->assertSee('Записи семьи')->assertSee('Идёт: Пётр Иванов');
    }

    /**
     * 21.10.2026: мама купила абонемент себе, записывала сына и прочла
     * «У вас нет абонемента» — о себе, хотя речь о нём. Отказ называет того,
     * кого записывают, и подсказывает, как дать ему абонемент.
     */
    public function test_refusal_for_relative_names_the_relative_not_the_booker(): void
    {
        [$anna, $petr] = $this->pair();
        $this->subscription($anna);
        $session = $this->classAt();

        $this->actingAs($anna)
            ->from('/schedule')
            ->post('/bookings', ['class_session_id' => $session->id, 'for_user_id' => $petr->id])
            ->assertSessionHasErrors(['booking' => 'Пётр Иванов: нет своего абонемента для этого типа занятий.'
                .' Купите абонемент ему (ей) в подарок — на странице покупки в поле «Кому» выберите Пётр Иванов,'
                .' — или откройте свой абонемент в личном кабинете, в разделе семьи.']);

        $this->assertSame(0, Booking::query()->count());

        // Себя без абонемента — прежний текст.
        $this->actingAs($petr)
            ->from('/schedule')
            ->post('/bookings', ['class_session_id' => $session->id])
            ->assertSessionHasErrors(['booking' => 'У вас нет абонемента для этого типа занятий. Купите подходящий тариф в личном кабинете.']);
    }
}
