<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\FamilyGroup;
use App\Models\FamilyInvite;
use App\Models\FamilyMember;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Семейные группы: приглашения, состав, общий доступ к абонементам.
 *
 * Главное правило — делится не семья, а владелец абонемента. Членство само по
 * себе ничего не даёт; записаться по чужому абонементу можно, только если
 * владелец открыл его этому человеку (`subscription_shares`).
 *
 * Правила студии (19.10.2026):
 *  - в семье до 5 человек вместе с главой, вступление без подтверждения
 *    администратором, глава может исключить любого;
 *  - человек вышел, его исключили или ему закрыли доступ — его будущие записи
 *    по чужим абонементам отменяются, занятия возвращаются владельцам; так же
 *    отменяются записи других по его абонементам;
 *  - глава ушёл (сам, удалив аккаунт) — главой становится самый давний
 *    участник, а если никого не осталось, семья удаляется.
 *
 * Сайт, API приложения и обе админки ходят только сюда.
 */
class FamilyService
{
    public const MAX_MEMBERS = 5;

    public const INVITE_TTL_DAYS = 7;

    /** Без 0/O, 1/I/L: код диктуют голосом и переписывают руками. */
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const CODE_LENGTH = 8;

    /** По тапу на уведомлении о семье приложение открывает раздел «Семья». */
    private const FAMILY_PAYLOAD = ['screen' => 'family'];

    public function __construct(
        private BookingService $bookings,
        private NotificationService $notifications,
    ) {}

    public function membershipOf(User $user): ?FamilyMember
    {
        return FamilyMember::query()->where('user_id', $user->id)->with('group')->first();
    }

    public function groupOf(User $user): ?FamilyGroup
    {
        return $this->membershipOf($user)?->group;
    }

    /* ------------------------------------------------------------------
     | Приглашения
     * ------------------------------------------------------------------ */

    /**
     * Создать приглашение. Семьи ещё нет — она заводится, приглашающий
     * становится главой.
     */
    public function createInvite(User $inviter): FamilyInvite
    {
        $this->assertClient($inviter, 'Приглашать в семью может только клиент студии.');

        return DB::transaction(function () use ($inviter) {
            $membership = $this->membershipOf($inviter) ?? $this->foundGroup($inviter);

            if (! $membership->isOwner()) {
                throw new InvalidArgumentException('Приглашать в семью может только глава семьи.');
            }

            $group = FamilyGroup::query()->lockForUpdate()->findOrFail($membership->family_group_id);
            $members = $group->members()->count();

            if ($members >= self::MAX_MEMBERS) {
                throw new InvalidArgumentException('В семье уже '.self::MAX_MEMBERS.' человек — больше пригласить нельзя.');
            }

            $pending = $group->invites()->pending()->count();

            if ($members + $pending >= self::MAX_MEMBERS) {
                throw new InvalidArgumentException(
                    'Неиспользованных приглашений столько же, сколько свободных мест в семье. '
                    .'Отзовите лишнее или дождитесь, пока по нему вступят.',
                );
            }

            return $group->invites()->create([
                'inviter_id' => $inviter->id,
                'code' => $this->uniqueCode(),
                'expires_at' => now()->addDays(self::INVITE_TTL_DAYS),
            ]);
        });
    }

    public function revokeInvite(User $actor, FamilyInvite $invite): void
    {
        $membership = $this->membershipOf($actor);

        if ($membership === null || ! $membership->isOwner() || $membership->family_group_id !== $invite->family_group_id) {
            throw new InvalidArgumentException('Отозвать приглашение может только глава семьи.');
        }

        if (! $invite->isPending()) {
            throw new InvalidArgumentException('Приглашение уже недействительно.');
        }

        $invite->update(['revoked_at' => now()]);
    }

    /** Ссылка, которую глава пересылает: открывается на сайте и в приложении. */
    public function inviteUrl(FamilyInvite $invite): string
    {
        return route('family.join', ['code' => $invite->code]);
    }

    /** Текст для «Поделиться»: в мессенджере ссылка без пояснения выглядит как спам. */
    public function inviteMessage(FamilyInvite $invite): string
    {
        $invite->loadMissing('inviter');

        return $invite->inviter->shortName().' приглашает вас в семью в студии йоги ЭКО YOGA. '
            .'Откройте ссылку: '.$this->inviteUrl($invite)
            .' или введите код '.$invite->formattedCode().' в приложении (Кабинет → Семья).';
    }

    /** Найти приглашение по коду в любом написании: «k7mq-2p9x», «K7MQ 2P9X». */
    public function findInvite(string $code): ?FamilyInvite
    {
        $normalized = $this->normalizeCode($code);

        if ($normalized === null) {
            return null;
        }

        return FamilyInvite::query()->where('code', $normalized)->with(['group', 'inviter'])->first();
    }

    /**
     * Почему этот человек не может принять приглашение (null — может).
     */
    public function joinBlocker(FamilyInvite $invite, User $user): ?string
    {
        if ($invite->accepted_at !== null) {
            return $invite->accepted_by_id === $user->id
                ? 'Вы уже вступили в эту семью.'
                : 'Это приглашение уже использовано. Попросите новое.';
        }

        if ($invite->revoked_at !== null) {
            return 'Приглашение отозвано. Попросите новое.';
        }

        if (! $invite->expires_at->isFuture()) {
            return 'Срок приглашения истёк. Попросите новое.';
        }

        if (! $user->isClient()) {
            return 'Вступить в семью может только клиент студии.';
        }

        $membership = $this->membershipOf($user);

        if ($membership !== null) {
            return $membership->family_group_id === $invite->family_group_id
                ? 'Вы уже в этой семье.'
                : 'Вы уже состоите в другой семье. Чтобы вступить в эту, сначала выйдите из своей.';
        }

        if ($invite->group->members()->count() >= self::MAX_MEMBERS) {
            return 'В семье уже '.self::MAX_MEMBERS.' человек — свободных мест нет.';
        }

        return null;
    }

    public function accept(FamilyInvite $invite, User $user): FamilyGroup
    {
        $group = DB::transaction(function () use ($invite, $user) {
            $invite = FamilyInvite::query()->lockForUpdate()->with('group')->findOrFail($invite->id);
            FamilyGroup::query()->lockForUpdate()->findOrFail($invite->family_group_id);

            if (($blocker = $this->joinBlocker($invite, $user)) !== null) {
                throw new InvalidArgumentException($blocker);
            }

            FamilyMember::query()->create([
                'family_group_id' => $invite->family_group_id,
                'user_id' => $user->id,
                'role' => FamilyMember::ROLE_MEMBER,
                'joined_at' => now(),
            ]);

            $invite->update(['accepted_by_id' => $user->id, 'accepted_at' => now()]);

            return $invite->group->fresh();
        });

        $owner = $group->owner;

        if ($owner !== null) {
            $this->notifications->notifyUser(
                $owner,
                'Новый участник семьи',
                [
                    'Здравствуйте, '.$owner->first_name.'!',
                    $user->shortName().' принял(а) приглашение и теперь в вашей семье.',
                    'Чтобы участник мог записываться по вашему абонементу, откройте доступ в кабинете: Семья → абонемент.',
                ],
                subject: 'Новый участник семьи',
                type: 'family',
                payload: self::FAMILY_PAYLOAD,
            );
        }

        return $group;
    }

    /* ------------------------------------------------------------------
     | Состав
     * ------------------------------------------------------------------ */

    /** Глава исключает участника. */
    public function removeMember(User $owner, User $member): void
    {
        $ownerMembership = $this->membershipOf($owner);

        if ($ownerMembership === null || ! $ownerMembership->isOwner()) {
            throw new InvalidArgumentException('Исключать из семьи может только глава.');
        }

        if ($owner->id === $member->id) {
            throw new InvalidArgumentException('Себя исключить нельзя — выйдите из семьи или распустите её.');
        }

        $membership = $this->membershipOf($member);

        if ($membership === null || $membership->family_group_id !== $ownerMembership->family_group_id) {
            throw new InvalidArgumentException('Этот человек не состоит в вашей семье.');
        }

        $this->detach($membership, 'removed');
    }

    /** Администратор убирает человека из его семьи (просьба по телефону). */
    public function removeByAdmin(User $member): void
    {
        $membership = $this->membershipOf($member);

        if ($membership === null) {
            throw new InvalidArgumentException('Клиент не состоит в семье.');
        }

        $this->detach($membership, 'removed');
    }

    public function leave(User $user): void
    {
        $membership = $this->membershipOf($user);

        if ($membership === null) {
            throw new InvalidArgumentException('Вы не состоите в семье.');
        }

        $this->detach($membership, 'left');
    }

    /** Глава распускает семью: все выходят, общие записи отменяются. */
    public function dissolve(User $owner): void
    {
        $membership = $this->membershipOf($owner);

        if ($membership === null || ! $membership->isOwner()) {
            throw new InvalidArgumentException('Распустить семью может только глава.');
        }

        $this->dissolveGroup($membership->group);
    }

    public function dissolveByAdmin(FamilyGroup $group): void
    {
        $this->dissolveGroup($group);
    }

    public function transferOwnership(User $owner, User $newOwner): void
    {
        $ownerMembership = $this->membershipOf($owner);

        if ($ownerMembership === null || ! $ownerMembership->isOwner()) {
            throw new InvalidArgumentException('Передать главенство может только глава семьи.');
        }

        $target = $this->membershipOf($newOwner);

        if ($target === null || $target->family_group_id !== $ownerMembership->family_group_id || $target->isOwner()) {
            throw new InvalidArgumentException('Выберите другого участника вашей семьи.');
        }

        DB::transaction(fn () => $this->makeOwner($target));

        $this->notifications->notifyUser(
            $newOwner,
            'Вы теперь глава семьи',
            [
                'Здравствуйте, '.$newOwner->first_name.'!',
                $owner->shortName().' передал(а) вам главенство в семье: теперь вы приглашаете и исключаете участников.',
            ],
            subject: 'Вы теперь глава семьи',
            type: 'family',
            payload: self::FAMILY_PAYLOAD,
        );
    }

    /**
     * Администратор добавляет клиента в семью другого клиента — для тех, кто
     * не пользуется ни сайтом, ни приложением. Семьи нет — заводится, `$host`
     * становится главой.
     */
    public function addByAdmin(User $host, User $member): FamilyGroup
    {
        $this->assertClient($host, 'Семья бывает только у клиентов студии.');
        $this->assertClient($member, 'Добавить в семью можно только клиента студии.');

        if ($host->id === $member->id) {
            throw new InvalidArgumentException('Выберите другого клиента.');
        }

        $group = DB::transaction(function () use ($host, $member) {
            $hostMembership = $this->membershipOf($host) ?? $this->foundGroup($host);
            $group = FamilyGroup::query()->lockForUpdate()->findOrFail($hostMembership->family_group_id);

            $existing = $this->membershipOf($member);

            if ($existing !== null) {
                throw new InvalidArgumentException($existing->family_group_id === $group->id
                    ? 'Клиент уже в этой семье.'
                    : 'Клиент уже состоит в другой семье — сначала уберите его оттуда.');
            }

            if ($group->members()->count() >= self::MAX_MEMBERS) {
                throw new InvalidArgumentException('В семье уже '.self::MAX_MEMBERS.' человек.');
            }

            FamilyMember::query()->create([
                'family_group_id' => $group->id,
                'user_id' => $member->id,
                'role' => FamilyMember::ROLE_MEMBER,
                'joined_at' => now(),
            ]);

            return $group;
        });

        $this->notifications->notifyUser(
            $member,
            'Вы в семье',
            [
                'Здравствуйте, '.$member->first_name.'!',
                'Студия добавила вас в семью: '.$this->ownerName($group).'.',
                'Если владелец откроет вам свой абонемент, по нему можно будет записываться на занятия.',
            ],
            subject: 'Вы в семье',
            type: 'family',
            payload: self::FAMILY_PAYLOAD,
        );

        return $group;
    }

    /** Аккаунт удаляется: человек уходит из семьи, общие записи отменяются с возвратом. */
    public function handleAccountDeletion(User $user): void
    {
        $membership = $this->membershipOf($user);

        if ($membership !== null) {
            $this->detach($membership, 'deleted');
        }
    }

    /* ------------------------------------------------------------------
     | Общий доступ к абонементам
     * ------------------------------------------------------------------ */

    /**
     * Владелец решает, кому из семьи открыт абонемент. Передаётся полный
     * список: кого нет — тому доступ закрывается, и его будущие записи по
     * этому абонементу отменяются с возвратом занятия.
     *
     * @param  list<int>  $userIds
     */
    public function setShares(User $owner, Subscription $subscription, array $userIds): void
    {
        if ($subscription->user_id !== $owner->id) {
            throw new InvalidArgumentException('Открывать доступ может только владелец абонемента.');
        }

        $this->applyShares($subscription, $userIds, byAdmin: false);
    }

    /** То же по просьбе клиента — из админки. @param list<int> $userIds */
    public function setSharesByAdmin(Subscription $subscription, array $userIds): void
    {
        $this->applyShares($subscription, $userIds, byAdmin: true);
    }

    /** Почему абонемент нельзя открыть семье (null — можно). */
    public function shareBlocker(Subscription $subscription): ?string
    {
        if (! $subscription->isShareable()) {
            return $subscription->isDoublePerDay()
                ? 'Абонемент «2 занятия в день» открыть семье нельзя: он оплачивает день одного человека.'
                : 'Этот вид абонемента семье не открывается.';
        }

        if ($subscription->ends_at->lt(now()->startOfDay()) || $subscription->sessionsRemaining() < 1) {
            return 'Абонемент закончился.';
        }

        return null;
    }

    /**
     * Абонементы владельца, которые имеет смысл показывать с переключателем
     * доступа: действующие и будущие.
     *
     * @return Collection<int, Subscription>
     */
    public function shareableSubscriptionsOf(User $owner): Collection
    {
        return $owner->subscriptions()
            ->whereDate('ends_at', '>=', now()->toDateString())
            ->whereColumn('sessions_used', '<', 'sessions_total')
            ->with('sharedWith')
            ->orderBy('ends_at')
            ->get()
            ->filter(fn (Subscription $s) => $s->isShareable())
            ->values();
    }

    /**
     * Чужие абонементы, открытые человеку, — для кабинета. Закончившиеся не
     * показываем: записаться по ним уже нельзя.
     *
     * @return Collection<int, Subscription>
     */
    public function subscriptionsSharedWith(User $user): Collection
    {
        return $user->sharedSubscriptions()
            ->with('user')
            ->whereDate('ends_at', '>=', now()->toDateString())
            ->orderBy('ends_at')
            ->get()
            ->filter(fn (Subscription $s) => $s->isShareable())
            ->values();
    }

    /* ------------------------------------------------------------------
     | Данные для экранов
     * ------------------------------------------------------------------ */

    /**
     * Всё про семью человека — одна сборка на сайт и приложение.
     *
     * Участники видят друг у друга только имя и фото: телефон, почта и
     * заметка о здоровье в семью не уходят.
     *
     * @return array<string, mixed>
     */
    public function overview(User $user): array
    {
        $membership = $this->membershipOf($user);
        $group = $membership?->group;

        $members = $group?->members()->with('user')->get() ?? collect();
        $isOwner = $membership?->isOwner() ?? false;

        $others = $members->filter(fn (FamilyMember $m) => $m->user_id !== $user->id)->values();

        return [
            'max_members' => self::MAX_MEMBERS,
            'invite_ttl_days' => self::INVITE_TTL_DAYS,
            'group' => $group === null ? null : [
                'id' => $group->id,
                'is_owner' => $isOwner,
                'owner_name' => $this->ownerName($group),
                'members_count' => $members->count(),
                'can_invite' => $isOwner && $members->count() < self::MAX_MEMBERS,
                'members' => $members->map(fn (FamilyMember $m) => [
                    'id' => $m->user_id,
                    'name' => $m->user->shortName(),
                    'initials' => $m->user->initials(),
                    'avatar_url' => $m->user->avatarUrl(),
                    'is_owner' => $m->isOwner(),
                    'is_me' => $m->user_id === $user->id,
                    'joined_at' => $m->joined_at->format('d.m.Y'),
                ])->values()->all(),
                'invites' => $isOwner
                    ? $group->invites()->pending()->orderBy('expires_at')->get()
                        ->map(fn (FamilyInvite $i) => $this->presentInvite($i))->values()->all()
                    : [],
            ],
            // Свои абонементы с переключателем «кому открыт». Без семьи или
            // без других участников открывать некому — список пустой.
            'my_subscriptions' => $others->isEmpty() ? [] : $this->shareableSubscriptionsOf($user)
                ->map(fn (Subscription $s) => [
                    'id' => $s->id,
                    'type' => $s->type->value,
                    'type_short' => $s->type->shortLabel(),
                    'sessions_remaining' => $s->sessionsRemaining(),
                    'sessions_total' => $s->sessions_total,
                    'ends_at' => $s->formattedEndsAt(),
                    'shared_with' => $s->sharedWith->pluck('id')->map(fn ($id) => (int) $id)
                        ->filter(fn (int $id) => $others->contains('user_id', $id))->values()->all(),
                ])->values()->all(),
            'shared_with_me' => $this->subscriptionsSharedWith($user)
                ->map(fn (Subscription $s) => $this->presentSharedSubscription($s))
                ->values()->all(),
        ];
    }

    /**
     * Посещения человека по абонементам семьи — для его истории в кабинете.
     * Своя история собирается из списаний своих абонементов, а эти списания
     * лежат на чужих, поэтому идём от записей.
     *
     * @return list<array{used_at: Carbon, title: string, owner_name: string, type_short: string}>
     */
    public function sharedUsageHistory(User $user): array
    {
        return Booking::query()
            ->where('user_id', $user->id)
            ->whereNotNull('subscription_usage_id')
            ->whereHas('subscription', fn ($q) => $q->where('user_id', '!=', $user->id))
            ->with(['subscriptionUsage', 'subscription.user', 'classSession'])
            ->get()
            ->filter(fn (Booking $b) => $b->subscriptionUsage !== null && $b->classSession !== null)
            ->map(fn (Booking $b) => [
                'used_at' => $b->subscriptionUsage->used_at,
                'title' => $b->classSession->title.' · '.$b->classSession->starts_at->format('d.m.Y H:i'),
                'owner_name' => $b->subscription->user?->shortName() ?? '',
                'type_short' => $b->subscription->type->shortLabel(),
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function presentInvite(FamilyInvite $invite): array
    {
        return [
            'id' => $invite->id,
            'code' => $invite->formattedCode(),
            'url' => $this->inviteUrl($invite),
            'message' => $this->inviteMessage($invite),
            'expires_at' => $invite->expires_at->format('d.m.Y'),
        ];
    }

    /** @return array<string, mixed> */
    public function presentSharedSubscription(Subscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'owner_name' => $subscription->user->shortName(),
            'type' => $subscription->type->value,
            'type_short' => $subscription->type->shortLabel(),
            'sessions_remaining' => $subscription->sessionsRemaining(),
            'sessions_total' => $subscription->sessions_total,
            'starts_at' => $subscription->formattedStartsAt(),
            'ends_at' => $subscription->formattedEndsAt(),
            'is_active' => $subscription->isActive(),
        ];
    }

    /**
     * Экран приглашения: кто зовёт и можно ли вступить.
     *
     * @return array<string, mixed>
     */
    public function presentInvitePreview(FamilyInvite $invite, ?User $viewer): array
    {
        $members = $invite->group->members()->with('user')->get();

        return [
            'code' => $invite->formattedCode(),
            'inviter_name' => $invite->inviter->shortName(),
            'inviter_initials' => $invite->inviter->initials(),
            'inviter_avatar_url' => $invite->inviter->avatarUrl(),
            'members' => $members->map(fn (FamilyMember $m) => $m->user->shortName())->values()->all(),
            'expires_at' => $invite->expires_at->format('d.m.Y'),
            'blocker' => $viewer !== null ? $this->joinBlocker($invite, $viewer) : null,
        ];
    }

    /* ------------------------------------------------------------------
     | Внутреннее
     * ------------------------------------------------------------------ */

    private function foundGroup(User $owner): FamilyMember
    {
        $group = FamilyGroup::query()->create(['owner_id' => $owner->id]);

        return FamilyMember::query()->create([
            'family_group_id' => $group->id,
            'user_id' => $owner->id,
            'role' => FamilyMember::ROLE_OWNER,
            'joined_at' => now(),
        ])->setRelation('group', $group);
    }

    private function makeOwner(FamilyMember $target): void
    {
        FamilyMember::query()
            ->where('family_group_id', $target->family_group_id)
            ->where('role', FamilyMember::ROLE_OWNER)
            ->update(['role' => FamilyMember::ROLE_MEMBER]);

        $target->update(['role' => FamilyMember::ROLE_OWNER]);

        FamilyGroup::query()->whereKey($target->family_group_id)->update(['owner_id' => $target->user_id]);
    }

    private function dissolveGroup(FamilyGroup $group): void
    {
        $owner = $group->owner;

        foreach ($group->members()->get() as $membership) {
            if ($membership->user_id !== $group->owner_id) {
                $this->detach($membership, 'dissolved');
            }
        }

        $ownerMembership = FamilyMember::query()->where('user_id', $owner?->id)->first();

        if ($ownerMembership !== null) {
            $this->detach($ownerMembership, 'dissolved');
        }
    }

    /**
     * Человек покидает семью — по любой причине.
     *
     * Одна точка для выхода, исключения, роспуска и удаления аккаунта, чтобы
     * правило «записи по чужим абонементам отменяются, занятия возвращаются
     * владельцам» нельзя было обойти ни одним путём.
     *
     * @param  'left'|'removed'|'dissolved'|'deleted'  $reason
     */
    private function detach(FamilyMember $membership, string $reason): void
    {
        $user = $membership->user;
        $group = $membership->group;
        $wasOwner = $membership->isOwner();

        /** @var FamilyMember|null $newOwner */
        $newOwner = null;

        $cancelled = DB::transaction(function () use ($membership, $user, $group, $wasOwner, &$newOwner) {
            $memberIds = $group->members()->pluck('user_id')->all();

            // Записи этого человека по абонементам семьи и записи остальных
            // по его абонементам — обе стороны общего доступа.
            $bookings = Booking::query()
                ->where('status', BookingStatus::Confirmed)
                ->whereHas('classSession', fn ($q) => $q->where('starts_at', '>', now()))
                ->where(fn ($q) => $q
                    ->where(fn ($q) => $q->where('user_id', $user->id)
                        ->whereHas('subscription', fn ($s) => $s->where('user_id', '!=', $user->id)->whereIn('user_id', $memberIds)))
                    ->orWhere(fn ($q) => $q->where('user_id', '!=', $user->id)->whereIn('user_id', $memberIds)
                        ->whereHas('subscription', fn ($s) => $s->where('user_id', $user->id))))
                ->with(['classSession', 'subscription.user', 'user'])
                ->get();

            $cancelled = $this->cancelSharedBookings($bookings, 'Общий доступ к абонементу семьи закрыт');

            DB::table('subscription_shares')->where('user_id', $user->id)->delete();
            DB::table('subscription_shares')
                ->whereIn('subscription_id', Subscription::query()->where('user_id', $user->id)->select('id'))
                ->delete();

            // Неиспользованные приглашения ушедшего главы больше никто не
            // контролирует — гасим, новый глава пригласит сам.
            if ($wasOwner) {
                $group->invites()->pending()->update(['revoked_at' => now()]);
            }

            $membership->delete();

            if ($wasOwner) {
                $next = $group->members()->first();

                if ($next === null) {
                    $group->delete();
                } else {
                    $this->makeOwner($next);
                    $newOwner = $next;
                }
            }

            return $cancelled;
        });

        // Тому, кто удаляет аккаунт, писать об отменах незачем.
        $this->notifyCancelled($reason === 'deleted'
            ? array_values(array_filter($cancelled, fn (Booking $b) => $b->user_id !== $user->id))
            : $cancelled);
        $this->notifyDetached($user, $group, $reason, $newOwner);
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     * @return list<Booking>
     */
    private function cancelSharedBookings(Collection $bookings, string $reason): array
    {
        $cancelled = [];

        foreach ($bookings as $booking) {
            $this->bookings->cancelByAdmin($booking, $reason);
            $cancelled[] = $booking;
        }

        return $cancelled;
    }

    /** @param list<int> $userIds */
    private function applyShares(Subscription $subscription, array $userIds, bool $byAdmin): void
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $subscription = $subscription->fresh();
        $owner = $subscription->user;
        $membership = $this->membershipOf($owner);

        if ($userIds !== []) {
            if (($blocker = $this->shareBlocker($subscription)) !== null) {
                throw new InvalidArgumentException($blocker);
            }

            if ($membership === null) {
                throw new InvalidArgumentException('Открыть абонемент можно только участникам своей семьи.');
            }

            $allowed = FamilyMember::query()
                ->where('family_group_id', $membership->family_group_id)
                ->where('user_id', '!=', $owner->id)
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if (array_diff($userIds, $allowed) !== []) {
                throw new InvalidArgumentException('Открыть абонемент можно только участникам своей семьи.');
            }
        }

        $current = $subscription->sharedWith()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        $added = array_values(array_diff($userIds, $current));
        $removed = array_values(array_diff($current, $userIds));

        if ($added === [] && $removed === []) {
            return;
        }

        $cancelled = DB::transaction(function () use ($subscription, $userIds, $removed) {
            $subscription->sharedWith()->sync($userIds);

            if ($removed === []) {
                return [];
            }

            $bookings = Booking::query()
                ->where('subscription_id', $subscription->id)
                ->whereIn('user_id', $removed)
                ->where('status', BookingStatus::Confirmed)
                ->whereHas('classSession', fn ($q) => $q->where('starts_at', '>', now()))
                ->with(['classSession', 'subscription.user', 'user'])
                ->get();

            return $this->cancelSharedBookings($bookings, 'Владелец закрыл доступ к абонементу');
        });

        $this->notifyCancelled($cancelled);

        $typeLabel = mb_strtolower($subscription->type->shortLabel());
        $by = $byAdmin ? 'Студия по просьбе владельца' : $owner->shortName();

        foreach (User::query()->whereIn('id', $added)->get() as $user) {
            $this->notifications->notifyUser(
                $user,
                'Вам открыт абонемент семьи',
                [
                    'Здравствуйте, '.$user->first_name.'!',
                    $by.' — открыт доступ к абонементу ('.$typeLabel.', владелец '.$owner->shortName()
                        .'), осталось занятий: '.$subscription->sessionsRemaining().', действует до '.$subscription->formattedEndsAt().'.',
                    'При записи выберите этот абонемент — занятие спишется с него.',
                ],
                subject: 'Вам открыт абонемент семьи',
                type: 'family',
                payload: self::FAMILY_PAYLOAD,
            );
        }

        foreach (User::query()->whereIn('id', $removed)->get() as $user) {
            $this->notifications->notifyUser(
                $user,
                'Доступ к абонементу закрыт',
                [
                    'Здравствуйте, '.$user->first_name.'!',
                    'Владелец ('.$owner->shortName().') закрыл вам доступ к абонементу ('.$typeLabel.').',
                    'Будущие записи по нему отменены, занятия вернулись владельцу.',
                ],
                subject: 'Доступ к абонементу закрыт',
                type: 'family',
                payload: self::FAMILY_PAYLOAD,
            );
        }
    }

    /**
     * Каждому, у кого отменили записи, — одно сообщение со списком.
     *
     * @param  list<Booking>  $cancelled
     */
    private function notifyCancelled(array $cancelled): void
    {
        foreach (collect($cancelled)->groupBy('user_id') as $bookings) {
            $user = $bookings->first()->user;

            $lines = ['Здравствуйте, '.$user->first_name.'!', 'Общий доступ к абонементу закрыт, поэтому отменены записи:'];

            foreach ($bookings as $booking) {
                $lines[] = '— '.$booking->classSession->title.', '.$booking->classSession->formattedDateTime();
            }

            $lines[] = 'Занятия вернулись на абонемент владельца. Записаться снова можно по своему абонементу.';

            $this->notifications->notifyUser(
                $user,
                'Запись по абонементу семьи отменена',
                $lines,
                subject: 'Запись по абонементу семьи отменена',
                type: 'session_cancelled',
                payload: ['session_id' => $bookings->first()->class_session_id],
            );
        }
    }

    private function notifyDetached(User $user, FamilyGroup $group, string $reason, ?FamilyMember $newOwner): void
    {
        $group = FamilyGroup::query()->with('owner')->find($group->id);
        $owner = $group?->owner;

        if ($reason === 'left' && $owner !== null && $owner->id !== $user->id) {
            $this->notifications->notifyUser(
                $owner,
                'Участник вышел из семьи',
                ['Здравствуйте, '.$owner->first_name.'!', $user->shortName().' вышел(ла) из вашей семьи.'],
                subject: 'Участник вышел из семьи',
                type: 'family',
                payload: self::FAMILY_PAYLOAD,
            );
        }

        if ($reason === 'removed') {
            $this->notifications->notifyUser(
                $user,
                'Вы больше не в семье',
                [
                    'Здравствуйте, '.$user->first_name.'!',
                    'Вас исключили из семьи. Записи по абонементам семьи, если были, отменены — занятия вернулись владельцам.',
                ],
                subject: 'Вы больше не в семье',
                type: 'family',
                payload: self::FAMILY_PAYLOAD,
            );
        }

        // Роспуск: участников снимают раньше главы, поэтому глава ещё на месте
        // и его имя можно назвать. Самому главе не пишем — он и распустил.
        if ($reason === 'dissolved' && $owner !== null && $owner->id !== $user->id) {
            $this->notifications->notifyUser(
                $user,
                'Семья распущена',
                [
                    'Здравствуйте, '.$user->first_name.'!',
                    $owner->shortName().' распустил(а) семью. Записи по абонементам семьи, если были, отменены — занятия вернулись владельцам.',
                ],
                subject: 'Семья распущена',
                type: 'family',
                payload: self::FAMILY_PAYLOAD,
            );
        }

        if ($newOwner !== null) {
            $next = $newOwner->user;

            $this->notifications->notifyUser(
                $next,
                'Вы теперь глава семьи',
                [
                    'Здравствуйте, '.$next->first_name.'!',
                    $user->shortName().' больше не в семье, и главой стали вы: теперь вы приглашаете и исключаете участников.',
                ],
                subject: 'Вы теперь глава семьи',
                type: 'family',
                payload: self::FAMILY_PAYLOAD,
            );
        }
    }

    private function ownerName(FamilyGroup $group): string
    {
        return $group->owner?->shortName() ?? '';
    }

    private function assertClient(User $user, string $message): void
    {
        if (! $user->isClient() || $user->isAnonymized()) {
            throw new InvalidArgumentException($message);
        }
    }

    private function normalizeCode(string $code): ?string
    {
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');

        return strlen($normalized) === self::CODE_LENGTH ? $normalized : null;
    }

    private function uniqueCode(): string
    {
        do {
            $code = '';

            for ($i = 0; $i < self::CODE_LENGTH; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while (FamilyInvite::query()->where('code', $code)->exists());

        return $code;
    }
}
