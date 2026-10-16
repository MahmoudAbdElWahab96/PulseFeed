<?php

namespace App\Jobs;

use App\Models\Follow;
use App\Models\Post;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Dispatches one NotifyFollowerChunk per bounded chunk of the author's
 * followers. Followers are selected while the fan-out runs, not at post
 * creation; a retried walker re-walks from the first chunk and the chunk
 * jobs deduplicate through the (post_id, follower_id) unique constraint.
 */
class FanOutPostNotifications implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const CHUNK_SIZE = 500;

    public int $tries = 5;

    public int $timeout = 300;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60, 120, 300];

    public function __construct(
        public readonly int $postId,
    ) {
        //
    }

    public function handle(): void
    {
        $post = Post::query()->find($this->postId, ['id', 'user_id']);

        if ($post === null) {
            Log::info('Post notification fan-out skipped: post no longer exists.', [
                'post_id' => $this->postId,
            ]);

            return;
        }

        $cursor = 0;

        do {
            // Covering range read on (followee_id, follower_id); at most
            // CHUNK_SIZE ids in memory.
            $followerIds = Follow::query()
                ->where('followee_id', $post->user_id)
                ->where('follower_id', '>', $cursor)
                ->orderBy('follower_id')
                ->limit(self::CHUNK_SIZE)
                ->pluck('follower_id')
                ->all();

            if ($followerIds !== []) {
                NotifyFollowerChunk::dispatch($this->postId, $followerIds);

                $cursor = (int) end($followerIds);
            }
        } while (count($followerIds) === self::CHUNK_SIZE);
    }
}
