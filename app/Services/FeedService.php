<?php

namespace App\Services;

use App\Models\Post;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;

class FeedService
{
    /**
     * Bounded page size: default 20, hard ceiling 50.
     */
    public const DEFAULT_LIMIT = 20;

    public const MAX_LIMIT = 50;

    /**
     * The user's feed: posts from accounts they follow, newest first with a
     * deterministic (created_at DESC, id DESC) order that cursor pagination
     * walks via the tuple predicate (created_at, id).
     *
     * The join drives from the follows index (follower_id, followee_id) and
     * then matches posts on (user_id, created_at, id); the user's own posts
     * are excluded because a self-follow row cannot exist.
     */
    public function paginate(User $subscriber, int $limit, ?string $cursor): CursorPaginator
    {
        return Post::query()
            ->select('posts.*')
            ->join('follows', function ($join) use ($subscriber) {
                $join->on('follows.followee_id', '=', 'posts.user_id')
                    ->where('follows.follower_id', '=', $subscriber->id);
            })
            ->with('author')
            ->orderByDesc('posts.created_at')
            ->orderByDesc('posts.id')
            ->cursorPaginate(perPage: $limit, cursor: $cursor);
    }
}
