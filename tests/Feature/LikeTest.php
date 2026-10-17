<?php

use App\Models\Like;
use App\Models\Post;
use App\Models\User;
use App\Services\LikeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

it('likes a post once and reports the fresh count', function () {
    $user = User::factory()->create();
    $post = Post::factory()->for($user, 'author')->create();

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/posts/{$post->id}/like")
        ->assertCreated()
        ->assertJsonPath('data.post_id', $post->id)
        ->assertJsonPath('data.likes_count', 1)
        ->assertJsonPath('data.liked_by_me', true);

    expect(Like::query()->where(['user_id' => $user->id, 'post_id' => $post->id])->count())->toBe(1);
});

it('keeps duplicate likes idempotent without inflating the count', function () {
    $user = User::factory()->create();
    $post = Post::factory()->for($user, 'author')->create();

    $this->actingAs($user, 'sanctum')->postJson("/api/posts/{$post->id}/like")->assertCreated();
    $this->actingAs($user, 'sanctum')->postJson("/api/posts/{$post->id}/like")
        ->assertOk()
        ->assertJsonPath('data.likes_count', 1)
        ->assertJsonPath('data.liked_by_me', true);

    expect(Like::query()->where('post_id', $post->id)->count())->toBe(1);
});

it('unlikes idempotently and never goes negative', function () {
    $user = User::factory()->create();
    $post = Post::factory()->for($user, 'author')->create();
    Like::query()->insert([
        'user_id' => $user->id, 'post_id' => $post->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($user, 'sanctum')
        ->deleteJson("/api/posts/{$post->id}/like")
        ->assertOk()
        ->assertJsonPath('data.likes_count', 0)
        ->assertJsonPath('data.liked_by_me', false);

    $this->actingAs($user, 'sanctum')
        ->deleteJson("/api/posts/{$post->id}/like")
        ->assertOk()
        ->assertJsonPath('data.likes_count', 0);

    expect(Like::query()->where('post_id', $post->id)->count())->toBe(0);
});

it('rejects unauthenticated like and unlike requests', function (string $method) {
    $post = Post::factory()->create();

    $this->{ $method }("/api/posts/{$post->id}/like")->assertUnauthorized();
})->with([
    'like' => ['postJson'],
    'unlike' => ['deleteJson'],
]);

it('returns 404 for liking or unliking missing posts', function (string $method, string $id) {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->{ $method }("/api/posts/{$id}/like")
        ->assertNotFound();
})->with([
    'like unknown' => ['postJson', '999999'],
    'unlike unknown' => ['deleteJson', '999999'],
    'like non-numeric' => ['postJson', 'abc'],
]);

it('ignores a client-forged user_id when liking', function () {
    $liker = User::factory()->create();
    $victim = User::factory()->create();
    $post = Post::factory()->for($victim, 'author')->create();

    $this->actingAs($liker, 'sanctum')
        ->postJson("/api/posts/{$post->id}/like", ['user_id' => $victim->id])
        ->assertCreated();

    expect(Like::query()->where('post_id', $post->id)->where('user_id', $victim->id)->exists())->toBeFalse()
        ->and(Like::query()->where('post_id', $post->id)->where('user_id', $liker->id)->exists())->toBeTrue();
});

it('exposes correct likes_count and liked_by_me on post detail per user', function () {
    $author = User::factory()->create();
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $post = Post::factory()->for($author, 'author')->create();

    foreach ([$alice, $bob] as $liker) {
        Like::query()->insert([
            'user_id' => $liker->id, 'post_id' => $post->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $this->actingAs($alice, 'sanctum')
        ->getJson("/api/posts/{$post->id}")
        ->assertOk()
        ->assertJsonPath('data.likes_count', 2)
        ->assertJsonPath('data.liked_by_me', true);

    $carol = User::factory()->create();
    $this->actingAs($carol, 'sanctum')
        ->getJson("/api/posts/{$post->id}")
        ->assertOk()
        ->assertJsonPath('data.likes_count', 2)
        ->assertJsonPath('data.liked_by_me', false);
});

it('exposes per-user liked_by_me in the feed while sharing like counts', function () {
    $subscriber = User::factory()->create();
    $other = User::factory()->create();
    $author = User::factory()->create();
    $subscriber->following()->attach($author);
    $other->following()->attach($author);

    $liked = Post::factory()->for($author, 'author')->create(['content' => 'liked by subscriber']);
    $unliked = Post::factory()->for($author, 'author')->create(['content' => 'not liked']);
    Like::query()->insert([
        'user_id' => $subscriber->id, 'post_id' => $liked->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    Like::query()->insert([
        'user_id' => $other->id, 'post_id' => $unliked->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $response = $this->actingAs($subscriber, 'sanctum')->getJson('/api/feed');

    $byContent = collect($response->json('data'))->keyBy('content');

    expect($byContent['liked by subscriber']['likes_count'])->toBe(1)
        ->and($byContent['liked by subscriber']['liked_by_me'])->toBeTrue()
        ->and($byContent['not liked']['likes_count'])->toBe(1)
        ->and($byContent['not liked']['liked_by_me'])->toBeFalse();
});

it('caches like counts and invalidates on real state changes only', function () {
    $author = User::factory()->create();
    $liker = User::factory()->create();
    $post = Post::factory()->for($author, 'author')->create();
    $key = LikeService::countCacheKey($post->id);

    expect(Cache::has($key))->toBeFalse();
    $this->actingAs($author, 'sanctum')
        ->getJson("/api/posts/{$post->id}")
        ->assertJsonPath('data.likes_count', 0);
    expect(Cache::has($key))->toBeTrue();

    $this->actingAs($liker, 'sanctum')->postJson("/api/posts/{$post->id}/like");
    expect(Cache::has($key))->toBeFalse();

    $this->actingAs($author, 'sanctum')
        ->getJson("/api/posts/{$post->id}")
        ->assertJsonPath('data.likes_count', 1);
    expect((int) Cache::get($key))->toBe(1);

    $this->actingAs($liker, 'sanctum')
        ->postJson("/api/posts/{$post->id}/like")
        ->assertOk()
        ->assertJsonPath('data.likes_count', 1);
    expect((int) Cache::get($key))->toBe(1);

    Cache::put($key, 999, 60);
    $this->actingAs($liker, 'sanctum')
        ->deleteJson("/api/posts/{$post->id}/like")
        ->assertOk()
        ->assertJsonPath('data.likes_count', 0);
    expect(Cache::has($key))->toBeFalse();

    Cache::put($key, 999, 60);
    $this->actingAs($liker, 'sanctum')
        ->deleteJson("/api/posts/{$post->id}/like")
        ->assertOk()
        ->assertJsonPath('data.likes_count', 0);
    expect((int) Cache::get($key))->toBe(999);

    $this->actingAs($author, 'sanctum')
        ->getJson("/api/posts/{$post->id}")
        ->assertJsonPath('data.likes_count', 999);
    expect((int) Cache::get($key))->toBe(999);

    $this->actingAs($author, 'sanctum')->postJson("/api/posts/{$post->id}/like");
    $this->actingAs($author, 'sanctum')
        ->getJson("/api/posts/{$post->id}")
        ->assertJsonPath('data.likes_count', 1);
    expect((int) Cache::get($key))->toBe(1);
});

it('serves warm feed counts from the batched cache read, not the database', function () {
    $subscriber = User::factory()->create();
    $author = User::factory()->create();
    $subscriber->following()->attach($author);

    $posts = Post::factory()->for($author, 'author')->count(3)->create();
    foreach ($posts as $post) {
        Like::query()->insert([
            'user_id' => $subscriber->id, 'post_id' => $post->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $countLikeTableQueries = function () use ($subscriber) {
        Cache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($subscriber, 'sanctum')->getJson('/api/feed')->assertOk();

        $cold = count(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], '`likes`')));

        DB::flushQueryLog();

        $this->actingAs($subscriber, 'sanctum')->getJson('/api/feed')->assertOk();

        $warm = count(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], '`likes`')));

        DB::disableQueryLog();

        return [$cold, $warm];
    };

    [$cold, $warm] = $countLikeTableQueries();

    expect($cold)->toBe(2)->and($warm)->toBe(1);

    $warmData = $this->actingAs($subscriber, 'sanctum')->getJson('/api/feed')->json('data');
    foreach ($warmData as $post) {
        expect($post['likes_count'])->toBe(1)->and($post['liked_by_me'])->toBeTrue();
    }
});
