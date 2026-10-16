<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Services\LikeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LikeController extends Controller
{
    public function __construct(
        private readonly LikeService $likes,
    ) {
        //
    }

    public function store(Request $request, Post $post): JsonResponse
    {
        [$count, $created] = $this->likes->like($request->user(), $post);

        return response()->json([
            'data' => [
                'post_id' => $post->id,
                'likes_count' => $count,
                'liked_by_me' => true,
            ],
        ], $created ? 201 : 200);
    }

    public function destroy(Request $request, Post $post): JsonResponse
    {
        [$count] = $this->likes->unlike($request->user(), $post);

        return response()->json([
            'data' => [
                'post_id' => $post->id,
                'likes_count' => $count,
                'liked_by_me' => false,
            ],
        ]);
    }
}
