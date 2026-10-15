<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Hash of a fixed dummy credential, checked when the email is unknown so
     * that failed logins take comparable time whether or not the email exists.
     */
    private const DUMMY_HASH = '$2y$12$PTPwi1xWzwg4jvARnJV9D.QgLPZPOeKs3c.p51yCbmreIDBAdSyhC';

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::query()->create($request->validated());

        return response()->json([
            'data' => [
                'user' => (new UserResource($user))->markAsOwn(),
                // Plain-text token is returned only here, at issuance time.
                'token' => $user->createToken('api')->plainTextToken,
            ],
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        $user = User::query()->where('email', $credentials['email'])->first();

        // Same generic error for unknown email and wrong password; the dummy
        // hash keeps both failure paths on the same bcrypt cost.
        if (! Hash::check($credentials['password'], $user?->password ?? self::DUMMY_HASH)) {
            throw ValidationException::withMessages([
                'email' => __('These credentials do not match our records.'),
            ]);
        }

        return response()->json([
            'data' => [
                'user' => (new UserResource($user))->markAsOwn(),
                'token' => $user->createToken('api')->plainTextToken,
            ],
        ]);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    /**
     * Revoke only the token used for this request, leaving the user's other
     * sessions untouched.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(status: 204);
    }
}
