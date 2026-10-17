<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FeedRequest;
use App\Http\Resources\PostResource;
use App\Services\FeedService;
use App\Services\LikeService;
use Illuminate\Http\Resources\Json\JsonResource;

class FeedController extends Controller
{
    public function __construct(
        private readonly FeedService $feed,
        private readonly LikeService $likes,
    ) {}

    public function index(FeedRequest $request): JsonResource
    {
        $posts = $this->feed->paginate($request->user(), $request->limit(), $request->cursor());

        if ($request->filled('limit')) {
            $posts->appends('limit', $request->limit());
        }

        $this->likes->hydrate($posts->getCollection(), $request->user());

        return PostResource::collection($posts);
    }
}
