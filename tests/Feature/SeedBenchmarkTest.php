<?php

use App\Models\Follow;
use App\Models\Like;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('seeds a benchmark dataset with the requested shape', function () {
    $exit = Artisan::call('pulsefeed:seed-benchmark', [
        '--users' => 60,
        '--posts' => 240,
        '--follows' => 500,
        '--likes' => 800,
        '--celebrities' => 2,
        '--chunk' => 200,
        '--fresh' => true,
    ]);

    expect($exit)->toBe(0);

    // Users: 60 bulk + 3 fixed benchmark subscribers.
    expect(User::query()->count())->toBe(63);

    // Posts: exact - no duplicate suppression is needed for posts.
    expect(Post::query()->count())->toBe(240);

    // Every post belongs to a bulk-seeded user.
    expect(Post::query()->whereNotBetween('user_id', [1, 60])->exists())->toBeFalse();

    // Follows and likes land near, but under, their budgets: the celebrity
    // share is capped by the available follower pool (57 per celebrity
    // here), and the random share drops hashed duplicates and self-follows.
    expect(Follow::query()->count())->toBeGreaterThan(300)
        ->and(Follow::query()->count())->toBeLessThanOrEqual(500)
        ->and(Like::query()->count())->toBeGreaterThan(500)
        ->and(Like::query()->count())->toBeLessThanOrEqual(800);

    // No CHECK-violating self-follows survived.
    expect(Follow::query()->whereColumn('follower_id', 'followee_id')->exists())->toBeFalse();

    // Celebrity concentration: the two designated accounts hold far more
    // followers than a random user.
    $topFollowerCounts = Follow::query()
        ->selectRaw('followee_id, COUNT(*) AS c')
        ->groupBy('followee_id')
        ->orderByDesc('c')
        ->limit(2)
        ->pluck('followee_id')
        ->sort()
        ->values()
        ->all();

    expect($topFollowerCounts)->toBe([1, 2]);

    // Benchmark subscriber accounts log in with the shared password and
    // follow a bounded number of authors.
    $subscriber = User::query()->where('email', 'bench-sub-500@bench.local')->firstOrFail();

    expect(Hash::check('sup3r-secret', $subscriber->password))->toBeTrue()
        ->and($subscriber->following()->count())->toBe(60); // capped at available authors
});

it('is additive by default and respects gap-free id ranges', function () {
    $existing = User::factory()->create();

    Artisan::call('pulsefeed:seed-benchmark', [
        '--users' => 10,
        '--posts' => 30,
        '--follows' => 0,
        '--likes' => 0,
        '--skip-bench-subscribers' => true,
        '--chunk' => 100,
    ]);

    // The pre-existing user and everything referencing the seeded range.
    expect(User::query()->count())->toBe(11)
        ->and(Post::query()->count())->toBe(30)
        ->and(User::query()->where('id', $existing->id)->exists())->toBeTrue()
        ->and(Post::query()->whereNotBetween('user_id', [$existing->id + 1, $existing->id + 10])->doesntExist())->toBeTrue();
});
