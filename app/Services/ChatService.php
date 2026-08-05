<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Переписка клиентов со студией.
 *
 * Одна переписка на клиента. Со стороны студии отвечает любой администратор —
 * для клиента это всё равно «студия», поэтому собеседник не закрепляется.
 */
class ChatService
{
    /** Дальше этой стороны фото не нужно: экран телефона мельче. */
    private const MAX_PHOTO_SIDE = 1600;

    private const JPEG_QUALITY = 82;

    /** Сколько сообщений отдаём за раз. */
    public const PAGE = 50;

    public function __construct(
        private AdminActivityNotifier $adminNotifier,
    ) {}

    /** Переписка клиента; заводится при первом обращении. */
    public function forClient(User $client): Conversation
    {
        if (! $client->isClient()) {
            throw new InvalidArgumentException('Переписка заводится только на клиента.');
        }

        return Conversation::query()->firstOrCreate(['user_id' => $client->id]);
    }

    /**
     * Лента переписки. `before` листает вглубь истории, `after` забирает
     * только новое — на нём держится автообновление открытого экрана.
     *
     * @return Collection<int, Message>
     */
    public function messages(Conversation $conversation, ?int $before = null, ?int $after = null): Collection
    {
        $query = $conversation->messages()->with('sender');

        if ($after !== null) {
            // Новее указанного — по возрастанию, без ограничения страницей:
            // за один интервал опроса много не накопится.
            return $query->where('id', '>', $after)->orderBy('id')->get();
        }

        if ($before !== null) {
            $query->where('id', '<', $before);
        }

        // Забираем хвост ленты и переворачиваем: в API сообщения всегда
        // идут от старых к новым, независимо от направления листания.
        return $query->orderByDesc('id')->limit(self::PAGE)->get()->reverse()->values();
    }

    /**
     * Наибольший номер собственного сообщения, которое собеседник уже прочитал.
     *
     * Нужен для галочек. Опрос забирает только новое (`after`), поэтому у
     * сообщений, которые уже лежат на экране, статус прочтения сам по себе
     * никогда не менялся бы: отправитель так и видел бы одну галочку.
     * Отмечаем прочтение всегда пачкой, поэтому одного числа достаточно —
     * всё, что не новее его, прочитано.
     */
    public function readThrough(Conversation $conversation, User $viewer): ?int
    {
        $id = $conversation->messages()
            ->where('sender_id', $viewer->id)
            ->whereNotNull('read_at')
            ->max('id');

        return $id !== null ? (int) $id : null;
    }

    /** Есть ли что-то ещё выше указанного сообщения. */
    public function hasMoreBefore(Conversation $conversation, ?int $oldestId): bool
    {
        if ($oldestId === null) {
            return false;
        }

        return $conversation->messages()->where('id', '<', $oldestId)->exists();
    }

    /**
     * Отправка. Пустое сообщение без фото не принимается.
     */
    public function send(
        Conversation $conversation,
        User $sender,
        ?string $body = null,
        ?UploadedFile $photo = null,
    ): Message {
        $body = $body !== null ? trim($body) : null;

        if (($body === null || $body === '') && $photo === null) {
            throw new InvalidArgumentException('Сообщение не может быть пустым.');
        }

        $attachment = $photo !== null ? $this->storePhoto($conversation, $photo) : null;

        $message = $conversation->messages()->create([
            'sender_id' => $sender->id,
            'body' => $body !== '' ? $body : null,
            'attachment_path' => $attachment['path'] ?? null,
            'attachment_width' => $attachment['width'] ?? null,
            'attachment_height' => $attachment['height'] ?? null,
        ]);

        $conversation->forceFill(['last_message_at' => $message->created_at])->save();

        // Клиент написал — сообщаем администратору наружу (Telegram и почта).
        // Иначе Ирина узнает о сообщении, только когда сама откроет приложение.
        if ($sender->id === $conversation->user_id) {
            $this->adminNotifier->clientWroteMessage($conversation->user, $message);
        }

        return $message->load('sender');
    }

    /**
     * Отметить прочитанным всё, что написал собеседник.
     *
     * @return int сколько сообщений отметили
     */
    public function markRead(Conversation $conversation, User $reader): int
    {
        $query = $reader->id === $conversation->user_id
            ? $conversation->unreadFromStudio()
            : $conversation->unreadFromClient();

        return $query->update(['read_at' => now()]);
    }

    /** Сколько непрочитанного у пользователя: клиенту — своё, админу — по всем перепискам. */
    public function unreadFor(User $user): int
    {
        if ($user->role === UserRole::Admin) {
            return Message::query()
                ->whereNull('read_at')
                ->whereHas('conversation', fn ($q) => $q->whereColumn('conversations.user_id', 'messages.sender_id'))
                ->count();
        }

        $conversation = Conversation::query()->where('user_id', $user->id)->first();

        return $conversation === null ? 0 : $conversation->unreadFromStudio()->count();
    }

    /**
     * Список переписок для администратора: клиент, последнее сообщение,
     * счётчик непрочитанного. `$q` ищет по имени, телефону и почте клиента.
     *
     * @return Collection<int, Conversation>
     */
    public function conversationsForAdmin(?string $q = null): Collection
    {
        $query = Conversation::query()
            ->with(['user', 'latestMessage'])
            ->withCount(['messages as unread_count' => fn ($m) => $m
                ->whereNull('read_at')
                ->whereColumn('messages.sender_id', 'conversations.user_id'),
            ])
            ->recent();

        $q = $q !== null ? trim($q) : '';

        if ($q !== '') {
            $digits = preg_replace('/\D+/', '', $q);

            $query->whereHas('user', function ($u) use ($q, $digits) {
                $u->where(function ($w) use ($q, $digits) {
                    $w->where('first_name', 'like', "%{$q}%")
                        ->orWhere('last_name', 'like', "%{$q}%")
                        ->orWhere('patronymic', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%");

                    if ($digits !== '') {
                        $w->orWhere('phone', 'like', "%{$digits}%");
                    }
                });
            });
        }

        return $query->get();
    }

    /**
     * Сохранить фото в приватный диск, попутно уменьшив.
     *
     * Снимок с телефона — это несколько мегабайт и сторона под 4000 px.
     * В переписке столько не нужно ни для показа, ни для места на диске.
     *
     * @return array{path: string, width: int|null, height: int|null}
     */
    private function storePhoto(Conversation $conversation, UploadedFile $photo): array
    {
        $directory = 'chat/'.$conversation->id;
        $name = (string) Str::ulid();

        // Без GD ужать нечем — кладём как есть, чтобы отправка не падала.
        // Так же поступаем, если снимок настолько большой, что распаковка
        // не влезет в память: снимок с современного телефона разворачивается
        // в десятки мегабайт, и падение процесса тут хуже неужатого файла.
        if (! function_exists('imagecreatefromstring') || ! $this->fitsInMemory($photo)) {
            $path = $photo->storeAs($directory, $name.'.'.$photo->extension(), Message::DISK);

            return ['path' => $path, 'width' => null, 'height' => null];
        }

        $source = @imagecreatefromstring((string) file_get_contents($photo->getRealPath()));

        // GD не знает HEIC с айфонов и часть экзотики. Это не повод терять
        // сообщение — кладём файл как есть, размеры остаются неизвестными.
        if ($source === false) {
            $path = $photo->storeAs($directory, $name.'.'.$photo->extension(), Message::DISK);

            return ['path' => $path, 'width' => null, 'height' => null];
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $longest = max($width, $height);

        if ($longest > self::MAX_PHOTO_SIDE) {
            $scale = self::MAX_PHOTO_SIDE / $longest;
            $newWidth = max(1, (int) round($width * $scale));
            $newHeight = max(1, (int) round($height * $scale));

            $resized = imagecreatetruecolor($newWidth, $newHeight);
            // Фото кладём на белое: у JPEG нет прозрачности, и PNG с альфой
            // иначе приезжает с чёрными полями.
            imagefill($resized, 0, 0, imagecolorallocate($resized, 255, 255, 255));
            imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($source);

            $source = $resized;
            $width = $newWidth;
            $height = $newHeight;
        }

        ob_start();
        imagejpeg($source, null, self::JPEG_QUALITY);
        $binary = (string) ob_get_clean();
        imagedestroy($source);

        $path = $directory.'/'.$name.'.jpg';
        Message::disk()->put($path, $binary);

        return ['path' => $path, 'width' => $width, 'height' => $height];
    }

    /**
     * Хватит ли памяти, чтобы распаковать снимок и собрать уменьшенный.
     *
     * GD держит картинку по 4 байта на пиксель, и оригинал с уменьшенной
     * копией какое-то время лежат в памяти одновременно. Считаем это заранее
     * по заголовку файла: разворачивать снимок, чтобы узнать, что он не
     * помещается, уже поздно — процесс к тому моменту падает.
     */
    private function fitsInMemory(UploadedFile $photo): bool
    {
        $size = @getimagesize($photo->getRealPath());

        if ($size === false) {
            return false;
        }

        [$width, $height] = $size;

        $scale = min(1, self::MAX_PHOTO_SIDE / max($width, $height));
        $needed = ($width * $height + ($width * $scale) * ($height * $scale)) * 4;

        $limit = $this->memoryLimitBytes();

        // Без ограничения памяти считаем, что места достаточно.
        if ($limit <= 0) {
            return true;
        }

        // Половина оставшегося — запас на всё остальное в запросе.
        return $needed < ($limit - memory_get_usage(true)) / 2;
    }

    /** memory_limit в байтах; -1 (без ограничения) отдаём как есть. */
    private function memoryLimitBytes(): int
    {
        $raw = trim((string) ini_get('memory_limit'));

        if ($raw === '' || $raw === '-1') {
            return -1;
        }

        $value = (int) $raw;

        return match (strtolower(substr($raw, -1))) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
