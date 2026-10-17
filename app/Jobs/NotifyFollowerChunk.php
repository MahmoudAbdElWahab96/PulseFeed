<?php

namespace App\Jobs;

use App\Models\Post;
use App\Models\PostNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NotifyFollowerChunk implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $timeout = 60;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60, 120, 300];

    /**
     * @param  array<int, int>  $followerIds
     */
    public function __construct(
        public readonly int $postId,
        public readonly array $followerIds,
    ) {}

    public function handle(): void
    {
        if (! Post::query()->whereKey($this->postId)->exists()) {
            Log::info('Post notification chunk skipped: post no longer exists.', [
                'post_id' => $this->postId,
                'follower_count' => count($this->followerIds),
            ]);

            return;
        }

        $now = now();

        PostNotification::query()->insertOrIgnore(
            array_map(
                fn (int $followerId) => [
                    'post_id' => $this->postId,
                    'follower_id' => $followerId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $this->followerIds
            )
        );
    }
}
