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

/**
 * Inserts notification rows for one bounded chunk of follower IDs.
 *
 * Idempotency: the write is insertOrIgnore against the UNIQUE
 * (post_id, follower_id) constraint, so any retry of this job - or a
 * duplicate dispatch produced by a retried walker - can never create a
 * second notification for the same (post, follower) pair.
 */
class NotifyFollowerChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
