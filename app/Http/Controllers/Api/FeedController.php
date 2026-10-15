<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PostResource;
use App\Services\FeedService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FeedController extends Controller
{
    public function __construct(private readonly FeedService $feed) {}

    public function index(Request $request): JsonResource
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.FeedService::MAX_LIMIT],
            'cursor' => ['nullable', 'string'],
            // A malformed cursor decodes to null and is served the first page.
        ]);

        $limit = $validated['limit'] ?? FeedService::DEFAULT_LIMIT;

        $posts = $this->feed->paginate(
            $request->user(),
            $limit,
            $validated['cursor'] ?? null
        );

        return PostResource::collection($posts);
    }
}
