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
    public const DEFAULT_LIMIT = 20;

    public const MAX_LIMIT = 50;

    /**
     * The cached payload is the ordered post IDs plus the cursor bookends;
     * hits rehydrate models, authors, and like metadata fresh, so entries
     * are per-user and user-specific data is never replayed.
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
     * The version component lets a follow/unfollow invalidate every cached
     * page for a user with one increment instead of key scans.
     */
    public function pageCacheKey(User $subscriber, int $limit, ?string $cursor): string
    {
        $version = (int) Cache::get(self::versionKey($subscriber->id), 0);

        $cursor = $this->normalizeCursor($cursor);
        $cursorPart = $cursor === null ? 'first' : hash('sha256', $cursor);

        return "feed:{$subscriber->id}:v{$version}:{$limit}:{$cursorPart}";
    }

    public static function invalidateForUser(int $userId): void
    {
        Cache::increment(self::versionKey($userId));
    }

    private static function versionKey(int $userId): string
    {
        return "feed:{$userId}:version";
    }

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

        // Cached order wins; posts deleted since caching drop out.
        $items = (new Collection($cached['ids']))
            ->filter(fn (int $id) => $posts->has($id))
            ->map(fn (int $id) => $posts[$id])
            ->values();

        return new HydratedFeedPage($items, $limit, $cached['next_cursor'], $cached['prev_cursor']);
    }

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
