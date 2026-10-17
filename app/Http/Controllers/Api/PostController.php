<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePostRequest;
use App\Http\Resources\PostResource;
use App\Models\Post;
use App\Services\LikeService;
use App\Services\PostService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

#[Group('Posts', 'Creating a post queues notifications to every follower.', weight: 2)]
class PostController extends Controller
{
    public function __construct(
        private readonly PostService $posts,
        private readonly LikeService $likes,
    ) {}

    public function store(StorePostRequest $request): JsonResponse
    {
        $post = $this->posts->create($request->user(), (string) $request->validated('content'));

        $post->load('author');

        return response()->json([
            'data' => new PostResource($post),
        ], 201);
    }

    public function show(Request $request, Post $post): JsonResource
    {
        $post->load('author');

        $this->likes->hydrate(new Collection([$post]), $request->user());

        return new PostResource($post);
    }

    public function destroy(Request $request, Post $post): JsonResponse
    {
        $this->authorize('delete', $post);

        $this->posts->delete($post);

        return response()->json(status: 204);
    }
}
