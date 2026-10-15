<?php

namespace App\Services;

use App\Models\Like;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class LikeService
{
    /**
     * Cache key convention: post:{id}:likes_count. The database is the
     * source of truth; cached values are only ever invalidated (never
     * incremented or decremented), so a cached count can never drift
     * negative or compound an error.
     */
    public static function countCacheKey(int $postId): string
    {
        return "post:{$postId}:likes_count";
    }

    /**
     * Like a post. Returns [fresh count, whether a new like was created].
     *
     * insertOrIgnore against the (user_id, post_id) unique constraint is
     * race-safe: under concurrency exactly one request inserts a row and
     * invalidates the cache; the others see "no state change". The cache key
     * is only forgotten after the insert has committed, and the response
     * count always comes fresh from the database.
     *
     * @return array{0: int, 1: bool}
     */
    public function like(User $user, Post $post): array
    {
        $created = (bool) Like::query()->insertOrIgnore([
            'user_id' => $user->id,
            'post_id' => $post->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($created) {
            Cache::forget(self::countCacheKey($post->id));
        }

        return [$this->freshCount($post), $created];
    }

    /**
     * Unlike a post. Returns [fresh count, whether a like was removed].
     * Idempotent: removing a nonexistent like changes no state and touches
     * no cache, so counts can never be decremented twice.
     *
     * @return array{0: int, 1: bool}
     */
    public function unlike(User $user, Post $post): array
    {
        $removed = (bool) Like::query()
            ->where('user_id', $user->id)
            ->where('post_id', $post->id)
            ->delete();

        if ($removed) {
            Cache::forget(self::countCacheKey($post->id));
        }

        return [$this->freshCount($post), $removed];
    }

    /**
     * Source-of-truth count for mutation responses; bypasses the cache so
     * the client that just acted always sees the real number.
     */
    public function freshCount(Post $post): int
    {
        return Like::query()->where('post_id', $post->id)->count();
    }

    /**
     * Cached count for a single post (post detail views).
     */
    public function countFor(Post $post): int
    {
        return (int) Cache::remember(
            self::countCacheKey($post->id),
            config('pulsefeed.likes.count_cache_ttl'),
            fn () => $this->freshCount($post),
        );
    }

    /**
     * Attach likes_count and liked_by_me attributes to a page of posts:
     * one batched MGET for cached counts, one grouped COUNT for misses,
     * one indexed existence query for the viewer's likes. Never a query
     * or cache call per post.
     *
     * @param  Collection<int, Post>  $posts
     */
    public function hydrate(Collection $posts, ?User $viewer): void
    {
        if ($posts->isEmpty()) {
            return;
        }

        $counts = $this->countsForPosts($posts->pluck('id')->all());

        $likedByViewer = $viewer === null
            ? []
            : Like::query()
                ->where('user_id', $viewer->id)
                ->whereIn('post_id', $posts->pluck('id')->all())
                ->pluck('post_id')
                ->all();

        foreach ($posts as $post) {
            $post->likes_count = $counts[$post->id] ?? 0;
            $post->liked_by_me = in_array($post->id, $likedByViewer);
        }
    }

    /**
     * Counts for many posts: one Cache::many (a single Redis MGET) plus one
     * grouped COUNT(*) for the misses. The write-back for misses is one SET
     * per uncached post, bounded by the page size.
     *
     * @param  array<int, int>  $postIds
     * @return array<int, int>
     */
    private function countsForPosts(array $postIds): array
    {
        $keys = array_map(self::countCacheKey(...), $postIds);

        $cached = Cache::many($keys);

        $counts = [];
        $missing = [];

        foreach ($postIds as $index => $postId) {
            $value = $cached[$keys[$index]] ?? null;

            if ($value === null) {
                $missing[] = $postId;
            } else {
                $counts[$postId] = (int) $value;
            }
        }

        if ($missing !== []) {
            $fresh = Like::query()
                ->whereIn('post_id', $missing)
                ->groupBy('post_id')
                ->selectRaw('post_id, COUNT(*) AS aggregate')
                ->pluck('aggregate', 'post_id');

            $ttl = config('pulsefeed.likes.count_cache_ttl');

            foreach ($missing as $postId) {
                $counts[$postId] = $count = (int) ($fresh[$postId] ?? 0);

                Cache::put(self::countCacheKey($postId), $count, $ttl);
            }
        }

        return $counts;
    }
}
