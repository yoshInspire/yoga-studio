<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ClientAccessService;
use App\Services\ContentImageService;
use App\Support\PhoneNormalizer;
use App\Support\PhotoValidation;
use App\Support\RussianDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Сотрудники студии: тренеры и администраторы (ADMIN_PLAN_2.md, фаза H).
 *
 * В вебе это тот же `UserResource`, что и для клиентов, с полями, которые
 * появляются по выбранной роли. В приложении карточка клиента живёт отдельно
 * (`ClientController`) и на сотруднике отвечает 422 — у тренера нет ни
 * абонементов, ни записей, зато есть витрина на сайте, которой нет у клиента.
 *
 * Что здесь важно не перепутать:
 *
 * - **Два разных снимка.** `avatar_path` — фотография человека в приложении,
 *   её ставит он сам в своём профиле. `trainer_photo_path` — снимок для
 *   витрины сайта, его выбирает студия. Это разные поля и разные кнопки.
 * - **Пароль в приложении не вводится** (§7.2). Тренеру доступ уходит письмом
 *   тем же `ClientAccessService`, что и в вебе. Администратору письма нет —
 *   ему пароль выдаётся на экран, чтобы передать лично: заводить почтовый
 *   путь к панели администратора ради удобства не стоит.
 * - **Удаления сотрудника нет.** У клиента это обезличивание
 *   (`AccountDeletionService`), у сотрудника сценарий не продуман: на тренере
 *   висят проведённые занятия. Снять с витрины — переключателем.
 */
class StaffController extends Controller
{
    public function __construct(private ContentImageService $images) {}

    /** Список сотрудников с поиском и фильтром по роли. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'role' => ['nullable', Rule::in([UserRole::Trainer->value, UserRole::Admin->value])],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $q = trim((string) ($data['q'] ?? ''));

        $staff = User::query()
            ->whereIn('role', [UserRole::Trainer, UserRole::Admin])
            ->when(isset($data['role']), fn ($query) => $query->where('role', $data['role']))
            ->when($q !== '', function ($query) use ($q) {
                $phone = PhoneNormalizer::normalize($q);

                $query->where(function ($sub) use ($q, $phone) {
                    $sub->where('first_name', 'like', "%{$q}%")
                        ->orWhere('last_name', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%");

                    if ($phone) {
                        $sub->orWhere('phone', 'like', "%{$phone}%");
                    }
                });
            })
            // Тренеры выше администраторов: с ними работают чаще.
            ->orderByRaw("case when role = ? then 0 else 1 end", [UserRole::Trainer->value])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->fullName(),
                'initials' => $u->initials(),
                'avatar' => $u->avatarUrl(),
                'role' => $u->role->value,
                'role_label' => $u->role->label(),
                'phone' => $u->formattedPhone() ?? $u->phone,
                'email' => $u->email,
                // Видно ли человека на сайте — у тренера это главный признак.
                'on_site' => $u->role === UserRole::Trainer ? (bool) $u->show_on_site : null,
            ]);

        return response()->json(['data' => $staff]);
    }

    /**
     * Завести сотрудника.
     *
     * Пароль сразу случайный и никому не известен: тренеру он уйдёт письмом
     * кнопкой «Отправить доступ», администратору — покажется на экране.
     */
    public function store(Request $request, ClientAccessService $access): JsonResponse
    {
        $data = $this->validated($request, null, creating: true);

        $user = User::query()->create([
            ...$data,
            'email' => $data['email'] ?? null,
            'password' => Str::password(12),
            // Нового тренера показываем на сайте сразу: витрина — то, ради
            // чего его чаще всего и заводят. Снять — переключателем.
            'show_on_site' => $data['role'] === UserRole::Trainer->value,
        ]);

        $sent = false;

        if ($user->role === UserRole::Trainer && filled($user->email)) {
            try {
                $result = $access->sendTemporaryPassword($user);
                $sent = (bool) ($result['email'] ?? false) || (bool) ($result['telegram'] ?? false);
            } catch (InvalidArgumentException) {
                $sent = false;
            }
        }

        return response()->json([
            'id' => $user->id,
            'data' => $this->card($user),
            'message' => $sent
                ? 'Сотрудник заведён, доступ отправлен.'
                : 'Сотрудник заведён. Доступ выдайте кнопкой в карточке.',
        ], 201);
    }

    /** Карточка сотрудника: данные, витрина, занятия. */
    public function show(User $staff): JsonResponse
    {
        $this->assertStaff($staff);

        return response()->json([
            'staff' => $this->card($staff),
            'stats' => $this->stats($staff),
        ]);
    }

    /** Правка данных и блока «Профиль на сайте». Роль здесь не меняется. */
    public function update(Request $request, User $staff): JsonResponse
    {
        $this->assertStaff($staff);

        $data = $this->validated($request, $staff, creating: false);
        unset($data['role']);

        $staff->update([
            ...$data,
            'email' => $data['email'] ?? null,
            'trainer_title' => filled($data['trainer_title'] ?? null) ? $data['trainer_title'] : null,
            'trainer_bio' => filled($data['trainer_bio'] ?? null) ? $data['trainer_bio'] : null,
        ]);

        return response()->json([
            'data' => $this->card($staff->refresh()),
            'message' => 'Сохранено.',
        ]);
    }

    /**
     * Сменить роль.
     *
     * Две вещи, которые в вебе не проверяются, а с телефона обязаны: своей
     * ролью распорядиться нельзя (человек закрыл бы себе вход) и последнего
     * администратора понизить нельзя (в студию было бы не войти вовсе).
     */
    public function role(Request $request, User $staff): JsonResponse
    {
        $this->assertStaff($staff);

        $role = UserRole::from($request->validate([
            'role' => ['required', Rule::enum(UserRole::class)],
        ])['role']);

        if ($staff->id === $request->user()->id) {
            return response()->json(['message' => 'Свою роль сменить нельзя — вход в админку закрылся бы.'], 422);
        }

        if ($role === $staff->role) {
            return response()->json(['message' => 'Роль и так эта.'], 422);
        }

        $lastAdmin = $staff->role === UserRole::Admin
            && User::query()->where('role', UserRole::Admin)->count() <= 1;

        if ($lastAdmin) {
            return response()->json(['message' => 'Это единственный администратор — сначала назначьте другого.'], 422);
        }

        $staff->update(['role' => $role]);

        return response()->json([
            'data' => $role === UserRole::Client ? null : $this->card($staff->refresh()),
            'role' => $role->value,
            'message' => 'Теперь это '.mb_strtolower($role->label()).'.',
        ]);
    }

    /**
     * Выдать доступ.
     *
     * Тренеру — письмом и в Telegram, тем же сервисом, что и в вебе.
     * Администратору письма не шлём: пароль возвращается в ответе, чтобы
     * назвать его лично. Иначе к панели администратора появился бы почтовый
     * путь, которого сейчас нет даже у восстановления пароля.
     */
    public function access(User $staff, ClientAccessService $access): JsonResponse
    {
        $this->assertStaff($staff);

        if ($staff->role === UserRole::Admin) {
            $password = $access->issueTemporaryPassword($staff);

            return response()->json([
                'password' => $password,
                'message' => 'Новый пароль: '.$password.'. Письмо не отправлялось — передайте лично.',
            ]);
        }

        try {
            $result = $access->sendTemporaryPassword($staff);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $channels = array_filter([
            ($result['email'] ?? false) ? 'email' : null,
            ($result['telegram'] ?? false) ? 'Telegram' : null,
        ]);

        if ($channels === []) {
            // Пароль уже сменён — молчать нельзя, иначе человек без входа.
            return response()->json([
                'message' => 'Пароль обновлён, но отправить не удалось. Новый пароль: '.$result['password'],
            ], 422);
        }

        return response()->json([
            'message' => 'Отправлено ('.implode(' и ', $channels).'). Пароль: '.$result['password'],
        ]);
    }

    /**
     * Снимок для витрины сайта — не аватар.
     *
     * Диск `public`, как у новостей и в веб-админке; кадрирования нет
     * (`ContentImageService`), форму снимка выбирает вёрстка сайта.
     */
    public function storePhoto(Request $request, User $staff): JsonResponse
    {
        $this->assertStaff($staff);

        if ($staff->role !== UserRole::Trainer) {
            return response()->json(['message' => 'Витрина есть только у тренеров.'], 422);
        }

        $request->validate(
            PhotoValidation::rules(),
            PhotoValidation::messages(),
            PhotoValidation::attributes(),
        );

        try {
            $path = $this->images->store($request->file('photo'), 'public', 'trainers', $staff->trainer_photo_path);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $staff->update(['trainer_photo_path' => $path]);

        return response()->json([
            'data' => $this->card($staff->refresh()),
            'message' => 'Фотография сохранена.',
        ]);
    }

    public function destroyPhoto(User $staff): JsonResponse
    {
        $this->assertStaff($staff);

        if ($staff->trainer_photo_path === null) {
            return response()->json(['message' => 'Фотографии и так нет.'], 422);
        }

        $previous = $staff->trainer_photo_path;
        $staff->update(['trainer_photo_path' => null]);
        $this->images->delete($previous, 'public');

        return response()->json([
            'data' => $this->card($staff->refresh()),
            'message' => 'Фотография убрана.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?User $staff, bool $creating): array
    {
        $request->merge(['phone' => PhoneNormalizer::normalize($request->input('phone'))]);

        if ($request->filled('email')) {
            $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        }

        $ignore = $staff === null ? '' : ','.$staff->id;

        $rules = [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'patronymic' => ['nullable', 'string', 'max:100'],
            'phone' => ['required', 'string', 'size:11', 'unique:users,phone'.$ignore],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'.$ignore],
            'birth_day' => ['nullable', 'integer', 'between:1,31'],
            'birth_month' => ['nullable', 'integer', 'between:1,12'],
            'birth_year' => ['nullable', 'integer', 'between:1920,'.now()->year],
            // Витрина: поля тренера. У администратора их просто не присылают.
            'show_on_site' => ['boolean'],
            'site_sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'trainer_title' => ['nullable', 'string', 'max:255'],
            'trainer_bio' => ['nullable', 'string', 'max:2000'],
        ];

        if ($creating) {
            $rules['role'] = ['required', Rule::in([UserRole::Trainer->value, UserRole::Admin->value])];
        }

        return $request->validate($rules, [
            'phone.size' => 'Введите корректный номер телефона.',
            'phone.unique' => 'Этот телефон уже зарегистрирован.',
            'email.unique' => 'Этот email уже занят.',
            'role.in' => 'Сотрудник может быть тренером или администратором.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function card(User $staff): array
    {
        return [
            'id' => $staff->id,
            'name' => $staff->fullName(),
            'initials' => $staff->initials(),
            // Фотография человека в приложении — её ставит он сам.
            'avatar_url' => $staff->avatarUrl(),
            'first_name' => $staff->first_name,
            'last_name' => $staff->last_name,
            'patronymic' => $staff->patronymic,
            'phone' => $staff->phone,
            'phone_formatted' => $staff->formattedPhone(),
            'email' => $staff->email,
            'birth_day' => $staff->birth_day,
            'birth_month' => $staff->birth_month,
            'birth_year' => $staff->birth_year,
            'role' => $staff->role->value,
            'role_label' => $staff->role->label(),
            'has_telegram' => $staff->hasTelegram(),
            'registered_at' => RussianDate::dayMonthYear($staff->created_at),
            // Витрина сайта — только у тренера.
            'show_on_site' => (bool) $staff->show_on_site,
            'site_sort_order' => (int) $staff->site_sort_order,
            'trainer_title' => $staff->trainer_title,
            'trainer_bio' => $staff->trainer_bio,
            'trainer_photo_url' => $staff->trainerPhotoUrl(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stats(User $staff): array
    {
        if ($staff->role !== UserRole::Trainer) {
            return ['upcoming' => 0, 'past' => 0];
        }

        return [
            // Сколько занятий у человека впереди — это видно до того, как его
            // снимут с витрины или сменят роль.
            'upcoming' => $staff->trainedSessions()->where('starts_at', '>=', now())->count(),
            'past' => $staff->trainedSessions()->where('starts_at', '<', now())->count(),
        ];
    }

    /** Здесь только сотрудники: карточка клиента живёт в ClientController. */
    private function assertStaff(User $staff): void
    {
        if (! in_array($staff->role, [UserRole::Trainer, UserRole::Admin], true)) {
            throw ValidationException::withMessages([
                'staff' => ['Это не сотрудник студии.'],
            ]);
        }
    }
}
