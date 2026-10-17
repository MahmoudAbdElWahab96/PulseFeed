<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('throttles write endpoints per user', function () {
    config(['pulsefeed.rate_limits.writes_per_minute' => 3]);

    $user = User::factory()->create();
    $other = User::factory()->create();

    foreach (range(1, 3) as $i) {
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/posts', ['content' => "post {$i}"])
            ->assertCreated();
    }

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/posts', ['content' => 'one too many'])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After');

    $this->actingAs($other, 'sanctum')
        ->postJson('/api/posts', ['content' => 'separate bucket'])
        ->assertCreated();
});

it('does not count reads against the write budget', function () {
    config(['pulsefeed.rate_limits.writes_per_minute' => 1]);

    $user = User::factory()->create();

    foreach (range(1, 3) as $i) {
        $this->actingAs($user, 'sanctum')->getJson('/api/feed')->assertOk();
    }

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/posts', ['content' => 'still allowed'])
        ->assertCreated();
});

it('throttles authenticated reads per user', function () {
    config(['pulsefeed.rate_limits.reads_per_minute' => 2]);

    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')->getJson('/api/feed')->assertOk();
    $this->actingAs($user, 'sanctum')->getJson('/api/feed')->assertOk();
    $this->actingAs($user, 'sanctum')->getJson('/api/feed')->assertTooManyRequests();
});

it('throttles registration per ip', function () {
    config(['pulsefeed.rate_limits.register_per_minute' => 2]);

    foreach (range(1, 2) as $i) {
        $this->postJson('/api/register', [
            'name' => "User {$i}",
            'email' => "user{$i}@example.com",
            'password' => 'sup3r-secret1',
        ])->assertCreated();
    }

    $this->postJson('/api/register', [
        'name' => 'User 3',
        'email' => 'user3@example.com',
        'password' => 'sup3r-secret1',
    ])->assertTooManyRequests();
});
