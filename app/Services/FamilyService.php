<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\FamilyGroup;
use App\Models\FamilyInvite;
use App\Models\FamilyMember;
use App\Models\Subscription;
use App\Models\User;
use App\Support\PhoneNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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

    /** Больше занятий, чем бывает в абонементе, лимитом не назовёшь. */
    public const MAX_SHARE_LIMIT = 100;

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

        if ($newOwner->isManagedProfile()) {
            throw new InvalidArgumentException('Главой семьи может быть только взрослый со своим входом.');
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

        if ($member->isManagedProfile() || $host->isManagedProfile()) {
            throw new InvalidArgumentException('Профиль ребёнка состоит в семье своего представителя.');
        }

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

    /* ------------------------------------------------------------------
     | Дети без своего аккаунта
     * ------------------------------------------------------------------ */

    /**
     * Взрослый участник заводит профиль ребёнка: без телефона и входа, со
     * своей датой рождения и заметкой о здоровье. Ребёнок — участник семьи
     * взрослого и считается в лимите (Ирина, 19.10.2026); взрослый становится
     * представителем. Семьи нет — заводится, как при приглашении.
     *
     * Оферту и согласие на обработку данных ребёнка представитель принимает
     * за него галочкой в форме — отсюда `offer_accepted_at`.
     *
     * @param  array{first_name: string, last_name: string, birth_date: string, health_note?: string|null, health_note_visible_to_trainer?: bool}  $data
     */
    public function addChild(User $adult, array $data): User
    {
        $this->assertClient($adult, 'Добавить ребёнка может только клиент студии.');

        if ($adult->isManagedProfile()) {
            throw new InvalidArgumentException('Добавить ребёнка может только взрослый участник семьи.');
        }

        $child = DB::transaction(function () use ($adult, $data) {
            $membership = $this->membershipOf($adult) ?? $this->foundGroup($adult);
            $group = FamilyGroup::query()->lockForUpdate()->findOrFail($membership->family_group_id);

            if ($group->members()->count() >= self::MAX_MEMBERS) {
                throw new InvalidArgumentException('В семье уже '.self::MAX_MEMBERS.' человек — ребёнка добавить нельзя.');
            }

            $birth = Carbon::parse($data['birth_date']);

            $child = User::query()->create([
                'first_name' => trim($data['first_name']),
                'last_name' => trim($data['last_name']),
                'phone' => null,
                'email' => null,
                'role' => UserRole::Client,
                'guardian_id' => $adult->id,
                // Войти по нему нельзя: ни телефона, ни почты нет.
                'password' => Str::random(40),
                'birth_day' => $birth->day,
                'birth_month' => $birth->month,
                'birth_year' => $birth->year,
                'health_note' => filled($data['health_note'] ?? null) ? trim((string) $data['health_note']) : null,
                'health_note_visible_to_trainer' => (bool) ($data['health_note_visible_to_trainer'] ?? true),
                'offer_accepted_at' => now(),
            ]);

            FamilyMember::query()->create([
                'family_group_id' => $group->id,
                'user_id' => $child->id,
                'role' => FamilyMember::ROLE_MEMBER,
                'joined_at' => now(),
            ]);

            return $child;
        });

        $owner = $this->groupOf($adult)?->owner;

        if ($owner !== null && $owner->id !== $adult->id) {
            $this->notifications->notifyUser(
                $owner,
                'Новый участник семьи',
                [
                    'Здравствуйте, '.$owner->first_name.'!',
                    $adult->shortName().' добавил(а) в семью профиль ребёнка: '.$child->shortName().'.',
                ],
                subject: 'Новый участник семьи',
                type: 'family',
                payload: self::FAMILY_PAYLOAD,
            );
        }

        return $child;
    }

    /**
     * Правила формы ребёнка — одни на сайт и приложение. Согласие
     * представителя обязательно только при создании: правка его не требует.
     *
     * @return array<string, mixed>
     */
    public static function childRules(bool $creating): array
    {
        return [
            'first_name' => ['required', 'string', 'max:60'],
            'last_name' => ['required', 'string', 'max:60'],
            'birth_date' => ['required', 'date', 'before:today', 'after:'.now()->subYears(25)->toDateString()],
            'health_note' => ['nullable', 'string', 'max:1000'],
            'health_note_visible_to_trainer' => ['sometimes', 'boolean'],
            ...($creating ? ['guardian_consent' => ['accepted']] : []),
        ];
    }

    /**
     * Представитель правит профиль ребёнка: имя, дата рождения, заметка о
     * здоровье (её видит тренер, если отмечено).
     *
     * @param  array{first_name: string, last_name: string, birth_date: string, health_note?: string|null, health_note_visible_to_trainer?: bool}  $data
     */
    public function updateChild(User $guardian, User $child, array $data): User
    {
        if ($child->guardian_id !== $guardian->id) {
            throw new InvalidArgumentException('Изменить профиль ребёнка может только его представитель.');
        }

        $birth = Carbon::parse($data['birth_date']);

        $child->update([
            'first_name' => trim($data['first_name']),
            'last_name' => trim($data['last_name']),
            'birth_day' => $birth->day,
            'birth_month' => $birth->month,
            'birth_year' => $birth->year,
            'health_note' => filled($data['health_note'] ?? null) ? trim((string) $data['health_note']) : null,
            'health_note_visible_to_trainer' => (bool) ($data['health_note_visible_to_trainer'] ?? true),
        ]);

        return $child->refresh();
    }

    /**
     * Вырос — свой аккаунт. Администратор вписывает телефон (и почту, если
     * есть), снимает представителя; история посещений остаётся на том же
     * пользователе, в семье он остаётся обычным участником. Доступ высылается
     * отдельно — кнопкой «Отправить доступ».
     */
    public function separateChild(User $child, string $phone, ?string $email = null): User
    {
        if (! $child->isManagedProfile()) {
            throw new InvalidArgumentException('Это не профиль ребёнка.');
        }

        $normalized = PhoneNormalizer::normalize($phone);

        if ($normalized === null) {
            throw new InvalidArgumentException('Проверьте номер телефона.');
        }

        if (User::query()->where('phone', $normalized)->whereKeyNot($child->id)->exists()) {
            throw new InvalidArgumentException('Этот телефон уже есть у другого клиента.');
        }

        if (filled($email) && User::query()->where('email', mb_strtolower(trim($email)))->whereKeyNot($child->id)->exists()) {
            throw new InvalidArgumentException('Эта почта уже есть у другого клиента.');
        }

        $guardian = $child->guardian;

        $child->update([
            'phone' => $normalized,
            'email' => filled($email) ? $email : null,
            'guardian_id' => null,
        ]);

        if ($guardian !== null) {
            $this->notifications->notifyUser(
                $guardian,
                'Профиль ребёнка отделён',
                [
                    'Здравствуйте, '.$guardian->first_name.'!',
                    'У '.$child->shortName().' теперь свой вход в студию. Записи и абонементы остались при нём(ней), в семье он(а) — обычный участник.',
                ],
                subject: 'Профиль ребёнка отделён',
                type: 'family',
                payload: self::FAMILY_PAYLOAD,
            );
        }

        return $child->refresh();
    }

    /**
     * Представитель удаляет аккаунт: дети переходят к самому давнему
     * взрослому их семьи (Ирина, 19.10.2026). Возвращает тех, кому
     * передать некого, — их обезличивает `AccountDeletionService` вместе с
     * представителем.
     *
     * @return list<User>
     */
    public function handOverWards(User $guardian): array
    {
        $orphans = [];

        foreach ($guardian->wards()->get() as $child) {
            $next = $this->oldestAdultOf($child, except: $guardian);

            if ($next === null) {
                $orphans[] = $child;

                continue;
            }

            $child->update(['guardian_id' => $next->id]);

            $this->notifications->notifyUser(
                $next,
                'Вы теперь представитель ребёнка',
                [
                    'Здравствуйте, '.$next->first_name.'!',
                    $guardian->shortName().' удалил(а) свой аккаунт, и профиль ребёнка '.$child->shortName()
                        .' перешёл к вам: теперь вы записываете его(её) на занятия и получаете уведомления.',
                ],
                subject: 'Вы теперь представитель ребёнка',
                type: 'family',
                payload: self::FAMILY_PAYLOAD,
            );
        }

        return $orphans;
    }

    /** Самый давний взрослый в семье человека, кроме `$except`. */
    private function oldestAdultOf(User $user, ?User $except = null): ?User
    {
        $membership = $this->membershipOf($user);

        if ($membership === null) {
            return null;
        }

        return FamilyMember::query()
            ->where('family_group_id', $membership->family_group_id)
            ->whereHas('user', fn ($q) => $q->whereNull('guardian_id')->whereNull('anonymized_at'))
            ->when($except, fn ($q) => $q->where('user_id', '!=', $except->id))
            ->where('user_id', '!=', $user->id)
            ->orderBy('joined_at')
            ->orderBy('id')
            ->with('user')
            ->first()
            ?->user;
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
     * Владелец решает, кому из семьи открыт абонемент и на сколько занятий.
     * Передаётся полный список: кого нет — тому доступ закрывается, и его
     * будущие записи по этому абонементу отменяются с возвратом занятия.
     *
     * @param  array<int, int|null>|list<int>  $shares  участник => лимит занятий (null — без
     *                                                  ограничений) или просто список участников
     */
    public function setShares(User $owner, Subscription $subscription, array $shares): void
    {
        if ($subscription->user_id !== $owner->id) {
            throw new InvalidArgumentException('Открывать доступ может только владелец абонемента.');
        }

        $this->applyShares($subscription, $shares, byAdmin: false);
    }

    /** То же по просьбе клиента — из админки. @param array<int, int|null>|list<int> $shares */
    public function setSharesByAdmin(Subscription $subscription, array $shares): void
    {
        $this->applyShares($subscription, $shares, byAdmin: true);
    }

    /**
     * Запрос «кому открыт абонемент» — в «участник => лимит». Принимаются оба
     * формата: старый `{user_ids: [...]}` (приложение до фазы 2) и новый
     * `{shares: [{user_id, limit}]}`. Пустой список — закрыть всем.
     *
     * На сайте форма без отмеченных галочек не шлёт ничего — это тоже «всем
     * закрыть» (`$requirePresent = false`); API требует явного списка, чтобы
     * кривой запрос не закрыл доступ молча.
     *
     * @return array<int, int|null>
     */
    public static function sharesFromRequest(Request $request, bool $requirePresent = true): array
    {
        $data = $request->validate([
            'user_ids' => ['sometimes', 'nullable', 'array', 'max:'.self::MAX_MEMBERS],
            'user_ids.*' => ['integer'],
            'shares' => ['sometimes', 'nullable', 'array', 'max:'.self::MAX_MEMBERS],
            // На сайте строка без галочки приходит без `user_id` — её пропускаем.
            'shares.*.user_id' => ['nullable', 'integer'],
            'shares.*.limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_SHARE_LIMIT],
        ]);

        if ($requirePresent && ! $request->has('user_ids') && ! $request->has('shares')) {
            throw ValidationException::withMessages(['shares' => 'Передайте список участников.']);
        }

        return self::sharesFromInput($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, int|null>
     */
    public static function sharesFromInput(array $data): array
    {
        if (isset($data['shares']) && is_array($data['shares'])) {
            $shares = [];

            foreach ($data['shares'] as $row) {
                if (($row['user_id'] ?? null) === null) {
                    continue;
                }

                $limit = $row['limit'] ?? null;
                $shares[(int) $row['user_id']] = $limit === null || $limit === '' ? null : (int) $limit;
            }

            return $shares;
        }

        return array_fill_keys(array_map('intval', $data['user_ids'] ?? []), null);
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
            // Без семьи ребёнка тоже можно завести — семья появится сама.
            'can_add_child' => $group === null && $user->isClient() && ! $user->isManagedProfile(),
            'group' => $group === null ? null : [
                'id' => $group->id,
                'is_owner' => $isOwner,
                'owner_name' => $this->ownerName($group),
                'members_count' => $members->count(),
                'can_invite' => $isOwner && $members->count() < self::MAX_MEMBERS,
                'can_add_child' => ! $user->isManagedProfile() && $members->count() < self::MAX_MEMBERS,
                'members' => $members->map(fn (FamilyMember $m) => [
                    'id' => $m->user_id,
                    'name' => $m->user->shortName(),
                    'initials' => $m->user->initials(),
                    'avatar_url' => $m->user->avatarUrl(),
                    'is_owner' => $m->isOwner(),
                    'is_me' => $m->user_id === $user->id,
                    'joined_at' => $m->joined_at->format('d.m.Y'),
                    // Ребёнок без входа: кто представитель, и профиль — только ему.
                    'is_child' => $m->user->isManagedProfile(),
                    'guardian_name' => $m->user->isManagedProfile() ? $m->user->guardian?->shortName() : null,
                    'child' => $m->user->guardian_id !== null && $m->user->guardian_id === $user->id
                        ? $this->presentChild($m->user)
                        : null,
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
                    // Лимит по участникам: {user_id: N}; кого нет — без ограничений.
                    'limits' => (object) $s->sharedWith
                        ->filter(fn (User $u) => $u->pivot->sessions_limit !== null && $others->contains('user_id', $u->id))
                        ->mapWithKeys(fn (User $u) => [(string) $u->id => (int) $u->pivot->sessions_limit])
                        ->all(),
                ])->values()->all(),
            'shared_with_me' => $this->subscriptionsSharedWith($user)
                ->map(fn (Subscription $s) => $this->presentSharedSubscription($s, $user))
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

    /**
     * Кого из семьи этот человек может записать на занятие — все участники,
     * кроме него самого (Ирина: любой участник любого, 19.10.2026).
     *
     * @return list<array{id: int, name: string, is_child: bool}>
     */
    public function bookableRelatives(User $user): array
    {
        $membership = $this->membershipOf($user);

        if ($membership === null) {
            return [];
        }

        return FamilyMember::query()
            ->where('family_group_id', $membership->family_group_id)
            ->where('user_id', '!=', $user->id)
            ->with('user')
            ->orderBy('joined_at')
            ->get()
            ->filter(fn (FamilyMember $m) => $m->user !== null)
            ->map(fn (FamilyMember $m) => [
                'id' => $m->user_id,
                'name' => $m->user->shortName(),
                'is_child' => $m->user->guardian_id !== null,
            ])
            ->values()
            ->all();
    }

    /**
     * Ближайшие записи других людей, которыми этот человек управляет: сделанные
     * им за членов семьи и записи его детей без своего входа (кто бы их ни
     * сделал — администратор тоже). Свои записи сюда не входят.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Booking>
     */
    public function managedBookings(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return Booking::query()
            ->upcoming()
            ->where('user_id', '!=', $user->id)
            ->where(fn ($q) => $q
                ->where('booked_by_id', $user->id)
                ->orWhereHas('user', fn ($u) => $u->where('guardian_id', $user->id)))
            ->with(['user', 'classSession.trainer', 'classSession.direction', 'subscription.user'])
            ->get()
            ->sortBy(fn (Booking $b) => $b->classSession->starts_at)
            ->values();
    }

    /**
     * Профиль ребёнка для формы правки — видит только представитель.
     *
     * @return array<string, mixed>
     */
    public function presentChild(User $child): array
    {
        $birth = $child->birth_year && $child->birth_month && $child->birth_day
            ? Carbon::create($child->birth_year, $child->birth_month, $child->birth_day)
            : null;

        return [
            'id' => $child->id,
            'first_name' => $child->first_name,
            'last_name' => $child->last_name,
            'birth_date' => $birth?->toDateString(),
            'birth_date_label' => $birth?->format('d.m.Y'),
            'health_note' => $child->health_note,
            'health_note_visible_to_trainer' => (bool) $child->health_note_visible_to_trainer,
        ];
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
    public function presentSharedSubscription(Subscription $subscription, ?User $viewer = null): array
    {
        $limit = $subscription->pivot?->sessions_limit;

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
            // «Осталось вам: 2 из 4» — лимит владельца; null — без ограничений.
            'limit' => $limit === null ? null : (int) $limit,
            'limit_left' => $limit === null || $viewer === null ? null : $subscription->shareLimitLeftFor($viewer),
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
            // Дети уходят вместе с представителем — их строки к этому моменту
            // уже могли исчезнуть.
            if ($membership->user_id !== $group->owner_id && FamilyMember::query()->whereKey($membership->id)->exists()) {
                $this->detach($membership->load('group'), 'dissolved');
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
                // Главой становится самый давний взрослый: ребёнок без входа
                // приглашать и исключать не может.
                $next = $group->members()->whereHas('user', fn ($q) => $q->whereNull('guardian_id'))->first();

                if ($next !== null) {
                    $this->makeOwner($next);
                    $newOwner = $next;
                } elseif (! $group->members()->exists()) {
                    $group->delete();
                }
            }

            return $cancelled;
        });

        // Тому, кто удаляет аккаунт, писать об отменах незачем.
        $this->notifyCancelled($reason === 'deleted'
            ? array_values(array_filter($cancelled, fn (Booking $b) => $b->user_id !== $user->id))
            : $cancelled);
        $this->notifyDetached($user, $group, $reason, $newOwner);

        // Ребёнок — участник семьи своего представителя: ушёл взрослый — уходят
        // и его дети. При удалении аккаунта их заранее передали другому
        // взрослому (`handOverWards()`), и сюда они уже не попадут.
        foreach ($user->wards()->get() as $child) {
            $childMembership = FamilyMember::query()
                ->where('user_id', $child->id)
                ->where('family_group_id', $group->id)
                ->with('group')
                ->first();

            if ($childMembership !== null) {
                $this->detach($childMembership, $reason === 'removed' ? 'left' : $reason);
            }
        }

        // Остались одни дети без взрослых — семьи больше нет.
        $rest = FamilyGroup::query()->find($group->id);

        if ($rest !== null && ! $rest->members()->whereHas('user', fn ($q) => $q->whereNull('guardian_id'))->exists()) {
            foreach ($rest->members()->with('group')->get() as $orphan) {
                $this->detach($orphan, 'dissolved');
            }

            FamilyGroup::query()->whereKey($group->id)->delete();
        }
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

    /**
     * @param  array<int, int|null>|list<int>  $shares  «участник => лимит» или просто список
     *                                                  участников без ограничений (как в фазе 1)
     */
    private function applyShares(Subscription $subscription, array $shares, bool $byAdmin): void
    {
        // Список id и «id => лимит» не спутать: id начинаются с 1, поэтому
        // непустая карта списком (ключи 0, 1, …) не бывает.
        if (array_is_list($shares)) {
            $shares = array_fill_keys(array_map('intval', $shares), null);
        }

        $shares = collect($shares)
            ->mapWithKeys(fn ($limit, $id) => [(int) $id => $limit === null ? null : max(1, (int) $limit)])
            ->all();
        $userIds = array_keys($shares);
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

        /** @var array<int, int|null> $current */
        $current = $subscription->sharedWith()->get()
            ->mapWithKeys(fn (User $u) => [(int) $u->id => $u->pivot->sessions_limit === null ? null : (int) $u->pivot->sessions_limit])
            ->all();
        $added = array_values(array_diff($userIds, array_keys($current)));
        $removed = array_values(array_diff(array_keys($current), $userIds));
        // Лимит поменяли тем, у кого доступ уже был. Снизили ниже набранного —
        // существующие записи не трогаем, новые не пустит `assertShareLimit()`.
        $relimited = array_values(array_filter(
            array_intersect($userIds, array_keys($current)),
            fn (int $id) => $current[$id] !== $shares[$id],
        ));

        if ($added === [] && $removed === [] && $relimited === []) {
            return;
        }

        $cancelled = DB::transaction(function () use ($subscription, $shares, $removed) {
            $subscription->sharedWith()->sync(
                collect($shares)->map(fn (?int $limit) => ['sessions_limit' => $limit])->all(),
            );

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
                    ...($shares[$user->id] !== null ? [$this->limitLine($shares[$user->id])] : []),
                    'При записи выберите этот абонемент — занятие спишется с него.',
                ],
                subject: 'Вам открыт абонемент семьи',
                type: 'family',
                payload: self::FAMILY_PAYLOAD,
            );
        }

        foreach (User::query()->whereIn('id', $relimited)->get() as $user) {
            $this->notifications->notifyUser(
                $user,
                'Изменён доступ к абонементу семьи',
                [
                    'Здравствуйте, '.$user->first_name.'!',
                    $by.' — изменены условия доступа к абонементу ('.$typeLabel.', владелец '.$owner->shortName().').',
                    $shares[$user->id] === null ? 'Теперь занятий по нему — без ограничений.' : $this->limitLine($shares[$user->id]),
                ],
                subject: 'Изменён доступ к абонементу семьи',
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

    /** «не больше 1 занятия / 4 занятий» — родительный после «больше». */
    private function limitLine(int $limit): string
    {
        $word = $limit % 10 === 1 && $limit % 100 !== 11 ? 'занятия' : 'занятий';

        return 'Вам можно взять по нему не больше '.$limit.' '.$word.' за весь срок абонемента.';
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
        // Ребёнок ушёл вместе с распущенной семьёй — представитель узнал об
        // этом своим сообщением, второе с тем же смыслом ни к чему.
        if ($user->isManagedProfile() && $reason === 'dissolved') {
            return;
        }

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
