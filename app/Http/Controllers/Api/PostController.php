<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePostRequest;
use App\Http\Resources\PostResource;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostController extends Controller
{
    public function store(StorePostRequest $request): JsonResponse
    {
        // The authenticated user is always the author; a client-supplied
        // user_id is never read. Single insert, no transaction needed.
        $post = $request->user()->posts()->create(
            $request->safe()->only(['content'])
        );

        $post->load('author');

        return response()->json([
            'data' => new PostResource($post),
        ], 201);
    }

    public function show(Request $request, Post $post): JsonResource
    {
        $post->load('author');

        return new PostResource($post);
    }

    public function destroy(Request $request, Post $post): JsonResponse
    {
        $this->authorize('delete', $post);

        $post->delete();

        return response()->json(status: 204);
    }
}
