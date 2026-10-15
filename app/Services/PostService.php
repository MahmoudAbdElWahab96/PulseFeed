<?php

namespace App\Services;

use App\Jobs\FanOutPostNotifications;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

class PostService
{
    /**
     * Create a post and queue the notification fan-out. The 201 response
     * never waits for follower processing: the walker runs on the queue.
     */
    public function create(User $author, string $content): Post
    {
        $post = $author->posts()->create(['content' => $content]);

        try {
            // Dispatched only after the surrounding database transaction
            // (if any) commits; with the plain autocommitted insert used
            // here it fires immediately after the row is durable.
            FanOutPostNotifications::dispatch($post->id)->afterCommit();
        } catch (Throwable $e) {
            // The post itself is durable; a queue outage must not fail the
            // write. This is a dispatch failure, not a delivery: no
            // notification rows exist for this post until the fan-out is
            // (re)queued successfully.
            Log::error('Post notification fan-out could not be queued.', [
                'post_id' => $post->id,
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
            ]);
        }

        return $post;
    }
}
