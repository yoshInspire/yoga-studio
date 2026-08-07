<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Удаление учётной записи по инициативе самого клиента.
 *
 * Требование магазинов приложений: если в приложении можно зарегистрироваться,
 * из него же должно быть можно удалиться (App Store 5.1.1(v), Google Play
 * Data Safety). Работает и на сайте, и в приложении — точка входа одна.
 *
 * Строка `users` не удаляется, а обезличивается. Причины две: на пользователя
 * ссылаются платежи и кассовые чеки со сроком хранения пять лет, а все внешние
 * ключи объявлены как `cascadeOnDelete` — физическое удаление унесло бы с собой
 * бухгалтерию. Поэтому личные данные стираются, а история платежей и посещений
 * остаётся привязанной к обезличенной записи.
 */
class AccountDeletionService
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly AdminActivityNotifier $adminActivity,
    ) {}

    /**
     * Что именно происходит:
     *  1) будущие записи отменяются — места возвращаются в расписание;
     *  2) действующие абонементы закрываются сегодняшним днём;
     *  3) переписка, вложения, уведомления, реакции и токены устройств удаляются;
     *  4) профиль обезличивается, все входы аннулируются.
     */
    public function delete(User $user): void
    {
        $cancelled = $this->cancelFutureBookings($user);

        // Письмо администратору собирается до обезличивания: после него имени
        // и телефона в базе уже не останется.
        $this->adminActivity->clientDeletedAccount($user, $cancelled);

        DB::transaction(function () use ($user) {
            $this->closeSubscriptions($user);
            $this->deleteChat($user);
            $this->deletePersonalRecords($user);
            $this->deleteAvatar($user);
            $this->anonymize($user);
        });

        // Токены и сессии — вне транзакции: их хранилища могут быть внешними
        // (кэш, Redis), и откатить их вместе с базой всё равно не получится.
        $user->tokens()->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();
    }

    /**
     * Отменяем через BookingService, чтобы освободить место и снять счётчики.
     *
     * @return int сколько записей отменено
     */
    private function cancelFutureBookings(User $user): int
    {
        $future = Booking::query()
            ->where('user_id', $user->id)
            ->where('status', BookingStatus::Confirmed)
            ->whereHas('classSession', fn ($query) => $query->where('starts_at', '>=', now()))
            ->get();

        foreach ($future as $booking) {
            // Возврат занятия на абонемент бессмысленен — абонемент закрывается
            // следующим шагом, но освободить место в группе нужно обязательно.
            $this->bookings->cancelByAdmin($booking, 'Клиент удалил аккаунт', refund: false);
        }

        return $future->count();
    }

    private function closeSubscriptions(User $user): void
    {
        $user->subscriptions()
            ->whereDate('ends_at', '>=', Carbon::today())
            ->update([
                'ends_at' => Carbon::today(),
                'admin_note' => 'Аккаунт удалён клиентом',
            ]);
    }

    private function deleteChat(User $user): void
    {
        $conversation = Conversation::query()->where('user_id', $user->id)->first();

        if ($conversation === null) {
            return;
        }

        $attachments = Message::query()
            ->where('conversation_id', $conversation->id)
            ->whereNotNull('attachment_path')
            ->pluck('attachment_path');

        foreach ($attachments as $path) {
            Message::disk()->delete($path);
        }

        // Сообщения уйдут каскадом за перепиской.
        $conversation->delete();
    }

    private function deletePersonalRecords(User $user): void
    {
        $user->pushTokens()->delete();
        $user->clientNotifications()->delete();

        DB::table('news_reactions')->where('user_id', $user->id)->delete();
        DB::table('client_mailing_logs')->where('user_id', $user->id)->delete();

        if ($user->email !== null) {
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
        }
    }

    private function deleteAvatar(User $user): void
    {
        if ($user->avatar_path !== null) {
            Storage::disk('public')->delete($user->avatar_path);
        }
    }

    private function anonymize(User $user): void
    {
        $user->forceFill([
            'first_name' => 'Удалённый',
            'last_name' => 'аккаунт',
            'patronymic' => null,
            'phone' => null,
            'email' => null,
            'email_verified_at' => null,
            'birth_day' => null,
            'birth_month' => null,
            'birth_year' => null,
            'health_note' => null,
            'health_note_visible_to_trainer' => false,
            'avatar_path' => null,
            'telegram_id' => null,
            'telegram_username' => null,
            'telegram_linked_at' => null,
            // Пароль не оставляем прежним: строка остаётся в базе, и по ней
            // не должно быть возможности войти, даже если phone кто-то вернёт.
            'password' => Str::password(40),
            'remember_token' => null,
            'offer_accepted_at' => null,
            'anonymized_at' => now(),
        ])->save();
    }
}
