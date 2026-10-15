<?php

use App\Models\Post;
use App\Models\User;
use App\Services\FeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function feedKey(User $subscriber, int $limit = FeedService::DEFAULT_LIMIT, ?string $cursor = null): string
{
    return app(FeedService::class)->pageCacheKey($subscriber, $limit, $cursor);
}

function feedSubscriberWithPosts(int $postCount): array
{
    $subscriber = User::factory()->create();
    $author = User::factory()->create();
    $subscriber->following()->attach($author);

    foreach (range(1, $postCount) as $i) {
        $post = Post::factory()->for($author, 'author')->make(['content' => "post {$i}"]);
        $post->created_at = Carbon::parse('2026-01-01 00:'.sprintf('%02d', $i).':00');
        $post->updated_at = $post->created_at;
        $post->save();
    }

    return [$subscriber, $author];
}

it('serves a cached feed page identically and with fewer queries on hit', function () {
    [$subscriber] = feedSubscriberWithPosts(5);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $first = $this->actingAs($subscriber, 'sanctum')->getJson('/api/feed');
    $first->assertOk();
    $missQueries = count(DB::getQueryLog());

    $second = $this->actingAs($subscriber, 'sanctum')->getJson('/api/feed');
    $second->assertOk();
    $hitQueries = count(DB::getQueryLog()) - $missQueries;

    // The cached page replays byte-identically, cursors included.
    expect($second->getContent())->toBe($first->getContent());

    // On the hit, the ranking join is skipped; only hydration runs.
    expect($hitQueries)->toBeLessThan($missQueries)
        ->and($hitQueries)->toBeGreaterThanOrEqual(3);

    // The page is actually cached under the per-user key.
    expect(Cache::has(feedKey($subscriber)))->toBeTrue();
});

it('never serves one user\'s cached feed to another user', function () {
    [$alice, $author] = feedSubscriberWithPosts(3);
    $bob = User::factory()->create();
    $bob->following()->attach($author);

    // Alice likes everything; Bob likes nothing.
    $author->posts->each(function ($post) use ($alice) {
        DB::table('likes')->insert([
            'user_id' => $alice->id, 'post_id' => $post->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    // Warm Alice's cache.
    $aliceResponse = $this->actingAs($alice, 'sanctum')->getJson('/api/feed')->assertOk();

    // Bob's request must resolve liked_by_me for Bob, not replay Alice's.
    $bobResponse = $this->actingAs($bob, 'sanctum')->getJson('/api/feed')->assertOk();

    expect($bobResponse->getContent())->not->toBe($aliceResponse->getContent());

    foreach ($aliceResponse->json('data') as $post) {
        expect($post['liked_by_me'])->toBeTrue();
    }

    foreach ($bobResponse->json('data') as $post) {
        expect($post['liked_by_me'])->toBeFalse();
    }

    // Distinct cache entries per user.
    expect(feedKey($bob))->not->toBe(feedKey($alice));
});

it('invalidates all cached feed pages when the follow graph changes', function () {
    [$subscriber, $author] = feedSubscriberWithPosts(2);

    $this->actingAs($subscriber, 'sanctum')->getJson('/api/feed')->assertOk();

    $beforeKey = feedKey($subscriber);
    expect(Cache::has($beforeKey))->toBeTrue();

    // A new author with a newer post, followed after the page was cached.
    $newAuthor = User::factory()->create();
    $post = Post::factory()->for($newAuthor, 'author')->make(['content' => 'fresh voice']);
    $post->created_at = Carbon::parse('2026-02-01 00:00:00');
    $post->save();

    $this->actingAs($subscriber, 'sanctum')
        ->postJson("/api/follow/{$newAuthor->id}")
        ->assertCreated();

    // The old version's entries are orphaned under a new version number.
    expect(Cache::has($beforeKey))->toBeTrue() // old entry lingers until TTL...
        ->and(feedKey($subscriber))->not->toBe($beforeKey);

    // And the feed immediately reflects the new author despite the TTL.
    $this->actingAs($subscriber, 'sanctum')
        ->getJson('/api/feed')
        ->assertOk()
        ->assertJsonPath('data.0.content', 'fresh voice');
});

it('bounds feed staleness to the TTL for new posts by followed authors', function () {
    [$subscriber, $author] = feedSubscriberWithPosts(2);

    $this->actingAs($subscriber, 'sanctum')->getJson('/api/feed')->assertOk();

    // A newer post from an already-followed author: no invalidation in this
    // phase, so the cached page is served until the TTL elapses.
    $post = Post::factory()->for($author, 'author')->make(['content' => 'brand new']);
    $post->created_at = Carbon::parse('2026-03-01 00:00:00');
    $post->save();

    $this->actingAs($subscriber, 'sanctum')
        ->getJson('/api/feed')
        ->assertOk()
        ->assertJsonMissing(['content' => 'brand new']);

    // Once the cached page expires, the post appears.
    Cache::flush();

    $this->actingAs($subscriber, 'sanctum')
        ->getJson('/api/feed')
        ->assertOk()
        ->assertJsonPath('data.0.content', 'brand new');
});

it('normalizes malformed cursors and default limits into one cache key', function () {
    [$subscriber] = feedSubscriberWithPosts(3);

    $this->actingAs($subscriber, 'sanctum')->getJson('/api/feed?limit=20')->assertOk();

    // limit omitted (defaults to 20) and a garbage cursor (treated as first
    // page) must resolve to the same key as the explicit first page.
    expect(feedKey($subscriber, 20, null))
        ->toBe(feedKey($subscriber, 20, 'not-a-valid-cursor'))
        ->toBe(feedKey($subscriber));

    // And the garbage-cursor request hits that cache rather than erroring.
    $response = $this->actingAs($subscriber, 'sanctum')->getJson('/api/feed?cursor='.urlencode('garbage!'));
    $response->assertOk()->assertJsonCount(3, 'data');
});

it('drops posts deleted after caching instead of erroring', function () {
    [$subscriber] = feedSubscriberWithPosts(3);

    $this->actingAs($subscriber, 'sanctum')->getJson('/api/feed')->assertOk();

    // Delete the newest post directly; the cached page still lists its id.
    Post::query()->orderByDesc('id')->first()->delete();

    $this->actingAs($subscriber, 'sanctum')
        ->getJson('/api/feed')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});
