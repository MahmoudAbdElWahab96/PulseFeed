<?php

namespace App\Services;

use App\Models\Post;
use App\Models\User;
use App\Support\HydratedFeedPage;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class FeedService
{
    /**
     * Bounded page size: default 20, hard ceiling 50.
     */
    public const DEFAULT_LIMIT = 20;

    public const MAX_LIMIT = 50;

    /**
     * The user's feed page: posts from accounts they follow, newest first
     * with a deterministic (created_at DESC, id DESC) order that cursor
     * pagination walks via the tuple predicate (created_at, id).
     *
     * The ordered page (post IDs plus cursor bookends) is cached per user
     * for a short TTL; cache hits rehydrate the models - authors and like
     * metadata are always fetched fresh for the current page - so no user's
     * entry can ever be served to another, and per-post data goes stale
     * only as far as each sub-cache allows.
     */
    public function paginate(User $subscriber, int $limit, ?string $cursor): CursorPaginator
    {
        $cursor = $this->normalizeCursor($cursor);

        $key = $this->pageCacheKey($subscriber, $limit, $cursor);

        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $this->hydrateCachedPage($cached, $limit);
        }

        $page = $this->runQuery($subscriber, $limit, $cursor);

        Cache::put(
            $key,
            [
                'ids' => $page->getCollection()->pluck('id')->all(),
                'next_cursor' => $page->nextCursor()?->encode(),
                'prev_cursor' => $page->previousCursor()?->encode(),
            ],
            config('pulsefeed.feed.page_cache_ttl'),
        );

        return $page;
    }

    /**
     * Cache key per user, feed-graph version, page size, and cursor. The
     * version component lets a follow/unfollow invalidate every cached page
     * for that user with a single O(1) increment instead of key scans.
     */
    public function pageCacheKey(User $subscriber, int $limit, ?string $cursor): string
    {
        $version = (int) Cache::get(self::versionKey($subscriber->id), 0);

        // Normalized here as well so external callers derive exactly the
        // key paginate() uses for the same request parameters.
        $cursor = $this->normalizeCursor($cursor);
        $cursorPart = $cursor === null ? 'first' : hash('sha256', $cursor);

        return "feed:{$subscriber->id}:v{$version}:{$limit}:{$cursorPart}";
    }

    /**
     * Called by FollowService after the follow graph actually changes.
     */
    public static function invalidateForUser(int $userId): void
    {
        Cache::increment(self::versionKey($userId));
    }

    private static function versionKey(int $userId): string
    {
        return "feed:{$userId}:version";
    }

    /**
     * A cursor that does not decode to a valid Cursor object is treated as
     * absent, before it can influence cache keys (Laravel's paginator
     * applies the same leniency when reading the request).
     */
    private function normalizeCursor(?string $cursor): ?string
    {
        if ($cursor === null || Cursor::fromEncoded($cursor) === null) {
            return null;
        }

        return $cursor;
    }

    /**
     * @param  array{ids: int[], next_cursor: ?string, prev_cursor: ?string}  $cached
     */
    private function hydrateCachedPage(array $cached, int $limit): CursorPaginator
    {
        $posts = Post::query()
            ->with('author')
            ->whereIn('id', $cached['ids'])
            ->get()
            ->keyBy('id');

        // Preserve the cached order; posts deleted since caching simply
        // drop out of the page.
        $items = (new Collection($cached['ids']))
            ->filter(fn (int $id) => $posts->has($id))
            ->map(fn (int $id) => $posts[$id])
            ->values();

        return new HydratedFeedPage($items, $limit, $cached['next_cursor'], $cached['prev_cursor']);
    }

    /**
     * The join drives from the follows index (follower_id, followee_id) and
     * then matches posts on (user_id, created_at, id); the user's own posts
     * are excluded because a self-follow row cannot exist.
     */
    private function runQuery(User $subscriber, int $limit, ?string $cursor): CursorPaginator
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
