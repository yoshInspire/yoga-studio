<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\AvatarService;
use App\Support\PhotoValidation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Фотография пользователя.
 *
 * Не под ролью: аватар ставит себе кто угодно из вошедших — клиент,
 * тренер, администратор.
 */
class AvatarController extends Controller
{
    public function __construct(private AvatarService $avatars) {}

    /** Загрузить новую фотографию. */
    public function store(Request $request): JsonResponse
    {
        $request->validate(
            PhotoValidation::rules(),
            PhotoValidation::messages(),
            PhotoValidation::attributes(),
        );

        try {
            $user = $this->avatars->update($request->user(), $request->file('photo'));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['photo' => [$e->getMessage()]]);
        }

        return response()->json([
            'user' => new UserResource($user),
            'message' => 'Фотография обновлена.',
        ]);
    }

    /** Убрать фотографию — останется кружок с инициалами. */
    public function destroy(Request $request): JsonResponse
    {
        $user = $this->avatars->remove($request->user());

        return response()->json([
            'user' => new UserResource($user),
            'message' => 'Фотография удалена.',
        ]);
    }
}
