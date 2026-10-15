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
 * Walks the post author's followers in bounded chunks and dispatches one
 * NotifyFollowerChunk job per chunk. The job payload is only the post ID:
 * follower IDs are read at processing time.
 *
 * Follower-selection semantics: followers are selected WHILE FAN-OUT RUNS,
 * not snapshotted at post-creation time. A user who follows the author
 * after the post was created but before the walker passes their
 * follower_id range will receive a notification; a user who unfollows
 * before their chunk is processed will not.
 *
 * Recovery: the walker is deterministic and idempotent. If it fails and
 * retries, it re-walks from the first chunk; already-processed chunks are
 * dispatched again, but NotifyFollowerChunk deduplicates through the
 * (post_id, follower_id) unique constraint, so no duplicate notifications
 * can result. The cursor is a follower_id range condition, never an
 * OFFSET, so rows inserted or removed between attempts cannot cause
 * skipped or duplicated followers within a completed pass.
 */
class FanOutPostNotifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const CHUNK_SIZE = 500;

    public int $tries = 5;

    public int $timeout = 300;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60, 120, 300];

    public function __construct(public readonly int $postId) {}

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
            // Covering range read on the (followee_id, follower_id) index:
            // at most CHUNK_SIZE integer IDs in memory at any time.
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
