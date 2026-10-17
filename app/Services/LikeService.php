<?php

namespace App\Services;

use App\Models\Like;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class LikeService
{
    public static function countCacheKey(int $postId): string
    {
        return "post:{$postId}:likes_count";
    }

    /**
     * @return array{0: int, 1: bool} [fresh count, whether a row was created]
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
     * @return array{0: int, 1: bool} [fresh count, whether a row was removed]
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

    public function freshCount(Post $post): int
    {
        return Like::query()->where('post_id', $post->id)->count();
    }

    /**
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
