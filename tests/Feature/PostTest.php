<?php

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a post owned by the authenticated user', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/posts', ['content' => 'hello world']);

    $response->assertCreated()
        ->assertJsonPath('data.content', 'hello world')
        ->assertJsonPath('data.author.id', $user->id);

    expect(Post::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and($response->json('data.author.email'))->toBe($user->email);
});

it('ignores a client-forged user_id on creation', function () {
    $author = User::factory()->create();
    $someoneElse = User::factory()->create();

    $this->actingAs($author, 'sanctum')
        ->postJson('/api/posts', ['content' => 'forged attempt', 'user_id' => $someoneElse->id])
        ->assertCreated();

    $post = Post::query()->where('content', 'forged attempt')->firstOrFail();

    expect($post->user_id)->toBe($author->id);
});

it('rejects invalid post content', function (mixed $content) {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/posts', ['content' => $content])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('content');
})->with([
    'missing' => [null],
    'empty string' => [''],
    'integer' => [42],
    'array' => [['nested']],
    'over 1000 characters' => [str_repeat('a', 1001)],
]);

it('accepts content at exactly 1000 characters', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/posts', ['content' => str_repeat('a', 1000)])
        ->assertCreated();
});

it('rejects unauthenticated post requests', function (string $method, string $uri) {
    $this->{ $method }($uri)->assertUnauthorized();
})->with([
    'create' => ['postJson', '/api/posts'],
    'show' => ['getJson', '/api/posts/1'],
    'delete' => ['deleteJson', '/api/posts/1'],
]);

it('shows a post with its author and no foreign email', function () {
    $author = User::factory()->create();
    $post = Post::factory()->for($author, 'author')->create(['content' => 'a post']);
    $reader = User::factory()->create();

    $this->actingAs($reader, 'sanctum')
        ->getJson("/api/posts/{$post->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $post->id)
        ->assertJsonPath('data.content', 'a post')
        ->assertJsonPath('data.author.id', $author->id)
        ->assertJsonPath('data.author.name', $author->name)
        ->assertJsonMissingPath('data.author.email');
});

it('returns 404 for missing or non-numeric post ids', function (string $id) {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->getJson("/api/posts/{$id}")
        ->assertNotFound();
})->with([
    'unknown id' => ['999999'],
    'non-numeric id' => ['abc'],
]);

it('lets the owner delete their post', function () {
    $user = User::factory()->create();
    $post = Post::factory()->for($user, 'author')->create();

    $this->actingAs($user, 'sanctum')
        ->deleteJson("/api/posts/{$post->id}")
        ->assertNoContent();

    expect($post->fresh())->toBeNull();
});

it('forbids deleting another user\'s post', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $post = Post::factory()->for($owner, 'author')->create();

    $this->actingAs($intruder, 'sanctum')
        ->deleteJson("/api/posts/{$post->id}")
        ->assertForbidden();

    expect($post->fresh())->not->toBeNull();
});

it('returns 404 when deleting a missing post', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->deleteJson('/api/posts/999999')
        ->assertNotFound();
});
