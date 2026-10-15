<?php

use App\Jobs\FanOutPostNotifications;
use App\Jobs\NotifyFollowerChunk;
use App\Models\Post;
use App\Models\PostNotification;
use App\Models\User;
use App\Services\PostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Runs the full fan-out synchronously in-process: executes the walker,
 * then executes every chunk job it dispatched. Mirrors what a queue
 * worker does, without pretending the queue ran.
 */
function runFanOut(int $postId): void
{
    (new FanOutPostNotifications($postId))->handle();

    Queue::pushed(NotifyFollowerChunk::class)
        ->each(fn (NotifyFollowerChunk $job) => $job->handle());
}

it('dispatches the fan-out walker when a post is created', function () {
    Queue::fake();

    $user = User::factory()->create();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/posts', ['content' => 'hello followers']);

    $post = $response->assertCreated()->json('data.id');

    // The 201 response is unchanged and the walker carries only the id.
    Queue::assertPushed(
        FanOutPostNotifications::class,
        fn (FanOutPostNotifications $job) => $job->postId === $post
    );
});

it('does not enqueue the walker before the creating transaction commits', function () {
    // Queue::fake() cannot demonstrate after-commit deferral (the fake
    // records pushes immediately), so this observes the real database
    // queue driver, whose enqueueUsing registers the actual transaction
    // callback - the same code path the Redis connection uses.
    $user = User::factory()->create();
    $service = app(PostService::class);

    DB::transaction(function () use ($service, $user) {
        $service->create($user, 'inside a transaction');

        // Deferred: nothing has been enqueued yet.
        expect(DB::table('jobs')->count())->toBe(0);
    });

    // Committed: the walker is enqueued.
    expect(DB::table('jobs')->count())->toBe(1);
});

it('does not process the queue during the post request', function () {
    // No queue fake: the phpunit queue connection stores jobs without
    // executing them, exactly like a real queue without a worker.
    $user = User::factory()->create();
    User::factory()->count(3)->create()->each(
        fn (User $follower) => $follower->following()->attach($user)
    );

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/posts', ['content' => 'queued, not inline'])
        ->assertCreated();

    expect(PostNotification::query()->count())->toBe(0)
        // The walker is parked in the queue, waiting for a worker.
        ->and(DB::table('jobs')->count())->toBe(1);
});

it('notifies every follower but never the author', function () {
    Queue::fake();

    $author = User::factory()->create();
    $followers = User::factory()->count(3)->create();
    $followers->each(fn (User $follower) => $follower->following()->attach($author));

    $post = app(PostService::class)->create($author, 'a notification journey');

    runFanOut($post->id);

    $notifiedIds = PostNotification::query()
        ->where('post_id', $post->id)
        ->pluck('follower_id')
        ->all();

    sort($notifiedIds);

    expect($notifiedIds)->toBe($followers->pluck('id')->sort()->values()->all())
        ->and(in_array($author->id, $notifiedIds))->toBeFalse();
});

it('creates no notification records for authors without followers', function () {
    Queue::fake();

    $post = app(PostService::class)->create(User::factory()->create(), 'shouting into the void');

    runFanOut($post->id);

    Queue::assertNotPushed(NotifyFollowerChunk::class);
    expect(PostNotification::query()->count())->toBe(0);
});

it('splits large follower lists into bounded chunks and covers everyone exactly once', function () {
    Queue::fake();

    $author = User::factory()->create();
    $followers = User::factory()->count(FanOutPostNotifications::CHUNK_SIZE * 2 + 201)->create();
    $followers->each(fn (User $follower) => $follower->following()->attach($author));

    $post = app(PostService::class)->create($author, 'a big announcement');

    runFanOut($post->id);

    Queue::assertPushed(NotifyFollowerChunk::class, 3);

    $chunkSizes = Queue::pushed(NotifyFollowerChunk::class)
        ->map(fn (NotifyFollowerChunk $job) => count($job->followerIds))
        ->all();
    sort($chunkSizes);

    expect($chunkSizes)->toBe([201, FanOutPostNotifications::CHUNK_SIZE, FanOutPostNotifications::CHUNK_SIZE])
        ->and(PostNotification::query()->where('post_id', $post->id)->count())->toBe($followers->count())
        ->and(PostNotification::query()->where('post_id', $post->id)->distinct('follower_id')->count('follower_id'))->toBe($followers->count());
});

it('retries create no duplicate notifications', function () {
    Queue::fake();

    $author = User::factory()->create();
    $followers = User::factory()->count(5)->create();
    $followers->each(fn (User $follower) => $follower->following()->attach($author));

    $post = app(PostService::class)->create($author, 'double delivery test');

    // First, complete pass.
    runFanOut($post->id);

    expect(PostNotification::query()->where('post_id', $post->id)->count())->toBe(5);

    // Worst-case retry: the whole walker and every chunk run again.
    Queue::fake();
    runFanOut($post->id);

    expect(PostNotification::query()->where('post_id', $post->id)->count())->toBe(5)
        ->and(PostNotification::query()->where('post_id', $post->id)->distinct()->count('follower_id'))->toBe(5);
});

it('handles posts deleted before or during fan-out', function () {
    Queue::fake();

    $author = User::factory()->create();
    $follower = User::factory()->create();
    $follower->following()->attach($author);

    $post = app(PostService::class)->create($author, 'ephemeral');

    // Walker runs, chunk jobs are dispatched...
    (new FanOutPostNotifications($post->id))->handle();

    // ...then the post is deleted before any chunk is processed.
    $post->delete();

    Queue::pushed(NotifyFollowerChunk::class)
        ->each(fn (NotifyFollowerChunk $job) => $job->handle());

    expect(PostNotification::query()->count())->toBe(0);

    // A walker that starts after deletion exits without dispatching work.
    Queue::fake();
    (new FanOutPostNotifications($post->id))->handle();
    Queue::assertNotPushed(NotifyFollowerChunk::class);
});

it('recovers from a failed fan-out without skipping followers', function () {
    Queue::fake();

    $author = User::factory()->create();
    $followers = User::factory()->count(4)->create();
    $followers->each(fn (User $follower) => $follower->following()->attach($author));

    $post = app(PostService::class)->create($author, 'resilient');

    // Simulate a crash after the first chunk was processed: the walker is
    // retried from scratch; already-notified followers are re-dispatched,
    // and the unique constraint absorbs the duplicates.
    Queue::fake();
    (new FanOutPostNotifications($post->id))->handle();

    $firstChunk = Queue::pushed(NotifyFollowerChunk::class)->first();
    $firstChunk->handle(); // only the first chunk completed before the "crash"

    Queue::fake();
    runFanOut($post->id); // full retry

    expect(PostNotification::query()->where('post_id', $post->id)->count())->toBe(4)
        ->and(PostNotification::query()->where('post_id', $post->id)->distinct()->count('follower_id'))->toBe(4);
});

it('keeps the post response identical whether the queue is available or not', function () {
    $user = User::factory()->create();

    $healthy = $this->actingAs($user, 'sanctum')
        ->postJson('/api/posts', ['content' => 'normal flow'])
        ->assertCreated()
        ->json('data');

    // A queue outage at dispatch time must not fail the write: the
    // dispatcher itself throws, as Redis would when unreachable.
    Bus::shouldReceive('dispatch')->andThrow(new RuntimeException('connection refused'));

    $degraded = $this->actingAs($user, 'sanctum')
        ->postJson('/api/posts', ['content' => 'queue down'])
        ->assertCreated()
        ->json('data');

    expect($degraded['content'])->toBe('queue down')
        ->and($degraded['author']['id'])->toBe($user->id)
        ->and(Post::query()->where('content', 'queue down')->exists())->toBeTrue()
        ->and($healthy['content'])->toBe('normal flow');
});
