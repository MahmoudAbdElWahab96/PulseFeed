<?php

namespace App\Services;

use App\Jobs\FanOutPostNotifications;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class PostService
{
    public function create(User $author, string $content): Post
    {
        $post = $author->posts()->create(['content' => $content]);

        try {
            FanOutPostNotifications::dispatch($post->id)->afterCommit();
        } catch (Throwable $e) {
            Log::error('Post notification fan-out could not be queued.', [
                'post_id' => $post->id,
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
            ]);
        }

        return $post;
    }

    public function delete(Post $post): void
    {
        $post->delete();

        Cache::forget(LikeService::countCacheKey($post->id));
    }
}
