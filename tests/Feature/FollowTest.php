<?php

use App\Models\Follow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rejects unauthenticated follow and unfollow requests', function (string $method) {
    $target = User::factory()->create();

    $this->{ $method }("/api/follow/{$target->id}")
        ->assertUnauthorized();
})->with([
    'follow' => ['postJson'],
    'unfollow' => ['deleteJson'],
]);

it('follows a user and creates exactly one relationship', function () {
    $follower = User::factory()->create();
    $target = User::factory()->create();

    $first = $this->actingAs($follower, 'sanctum')
        ->postJson("/api/follow/{$target->id}");

    $first->assertCreated()
        ->assertJsonPath('data.user_id', $target->id)
        ->assertJsonPath('data.following', true);

    $duplicate = $this->actingAs($follower, 'sanctum')
        ->postJson("/api/follow/{$target->id}");

    $duplicate->assertOk()
        ->assertJsonPath('data.following', true);

    expect(Follow::query()->where('follower_id', $follower->id)->where('followee_id', $target->id)->count())->toBe(1);
});

it('returns 404 when the follow target does not exist or is not numeric', function (string $userId) {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/follow/{$userId}")
        ->assertNotFound();
})->with([
    'unknown id' => ['999999'],
    'non-numeric id' => ['abc'],
]);

it('rejects following and unfollowing yourself', function (string $method) {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->{ $method }("/api/follow/{$user->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('user_id');
})->with([
    'follow' => ['postJson'],
    'unfollow' => ['deleteJson'],
]);

it('unfollows a user and stays idempotent when nothing is followed', function () {
    $follower = User::factory()->create();
    $target = User::factory()->create();

    $follower->following()->attach($target->id);

    $this->actingAs($follower, 'sanctum')
        ->deleteJson("/api/follow/{$target->id}")
        ->assertOk()
        ->assertJsonPath('data.user_id', $target->id)
        ->assertJsonPath('data.following', false);

    expect(Follow::query()->where('follower_id', $follower->id)->count())->toBe(0);

    $this->actingAs($follower, 'sanctum')
        ->deleteJson("/api/follow/{$target->id}")
        ->assertOk()
        ->assertJsonPath('data.following', false);
});

it('always operates on the authenticated user as the follower', function () {
    $attacker = User::factory()->create();
    $victim = User::factory()->create();
    $target = User::factory()->create();

    $this->actingAs($attacker, 'sanctum')
        ->postJson("/api/follow/{$target->id}", ['follower_id' => $victim->id])
        ->assertCreated();

    expect(Follow::query()->where('follower_id', $attacker->id)->where('followee_id', $target->id)->exists())->toBeTrue()
        ->and(Follow::query()->where('follower_id', $victim->id)->exists())->toBeFalse();
});
