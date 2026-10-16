<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FollowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FollowController extends Controller
{
    public function __construct(
        private readonly FollowService $follows,
    ) {
        //
    }

    public function store(Request $request, string $userId): JsonResponse
    {
        $created = $this->follows->follow($request->user(), (int) $userId);

        return response()->json([
            'data' => [
                'user_id' => (int) $userId,
                'following' => true,
            ],
        ], $created ? 201 : 200);
    }

    public function destroy(Request $request, string $userId): JsonResponse
    {
        $this->follows->unfollow($request->user(), (int) $userId);

        return response()->json([
            'data' => [
                'user_id' => (int) $userId,
                'following' => false,
            ],
        ]);
    }
}
