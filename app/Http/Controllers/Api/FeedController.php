<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PostResource;
use App\Services\FeedService;
use App\Services\LikeService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FeedController extends Controller
{
    public function __construct(
        private readonly FeedService $feed,
        private readonly LikeService $likes,
    ) {}

    public function index(Request $request): JsonResource
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.FeedService::MAX_LIMIT],
            'cursor' => ['nullable', 'string'],
        ]);

        $limit = $validated['limit'] ?? FeedService::DEFAULT_LIMIT;

        $posts = $this->feed->paginate(
            $request->user(),
            $limit,
            $validated['cursor'] ?? null
        );

        // User-specific, so it runs on cache hits too.
        $this->likes->hydrate($posts->getCollection(), $request->user());

        return PostResource::collection($posts);
    }
}
