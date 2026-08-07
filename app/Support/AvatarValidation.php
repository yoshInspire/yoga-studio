<?php

namespace App\Support;

/**
 * Проверка загружаемой фотографии — одна на сайт и на приложение,
 * чтобы правила и тексты ошибок не разъезжались.
 */
final class AvatarValidation
{
    /** 12 МБ: снимок с телефона в исходном качестве. */
    public const MAX_KILOBYTES = 12288;

    public const FIELD = 'photo';

    /**
     * HEIC с айфонов в списке нет намеренно: GD его не разворачивает, а
     * квадрат для аватара получить обязательно. Приложение приводит снимок
     * к JPEG само, на сайте такие файлы почти не встречаются.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            self::FIELD => ['required', 'file', 'mimes:jpeg,jpg,png,webp', 'max:'.self::MAX_KILOBYTES],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            self::FIELD.'.required' => 'Выберите фотографию.',
            self::FIELD.'.mimes' => 'Такой формат не поддерживается. Подойдут JPEG, PNG или WEBP.',
            self::FIELD.'.max' => 'Фотография слишком большая — до 12 МБ.',
        ];
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        return [self::FIELD => 'фотография'];
    }
}
