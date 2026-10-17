<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('registers a user and issues a token', function () {
    $response = $this->postJson('/api/register', [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'sup3r-secret',
    ]);

    $response->assertCreated()
        ->assertJsonStructure([
            'data' => [
                'user' => ['id', 'name', 'email', 'created_at'],
                'token',
            ],
        ])
        ->assertJsonPath('data.user.email', 'jane@example.com');

    $user = User::query()->where('email', 'jane@example.com')->firstOrFail();

    expect($user->password)->not->toBe('sup3r-secret')
        ->and($response->json('data.user.password'))->toBeNull();
});

it('rejects registration with missing or invalid fields', function (array $payload, array $expectedErrors) {
    $this->postJson('/api/register', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($expectedErrors);
})->with([
    'missing name' => [['name' => null, 'email' => 'a@b.co', 'password' => 'sup3r-secret'], ['name']],
    'invalid email' => [['name' => 'A', 'email' => 'not-an-email', 'password' => 'sup3r-secret'], ['email']],
    'weak password' => [['name' => 'A', 'email' => 'a@b.co', 'password' => 'short'], ['password']],
    'password without numbers' => [['name' => 'A', 'email' => 'a@b.co', 'password' => 'onlyletters'], ['password']],
]);

it('rejects registration with a duplicate email', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->postJson('/api/register', [
        'name' => 'Jane Doe',
        'email' => 'taken@example.com',
        'password' => 'sup3r-secret',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');
});

it('logs in with valid credentials and the token authenticates', function () {
    $user = User::factory()->create(['password' => 'sup3r-secret']);

    $response = $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'sup3r-secret',
    ]);

    $token = $response->assertOk()
        ->assertJsonStructure(['data' => ['user' => ['id', 'name', 'email'], 'token']])
        ->json('data.token');

    expect($token)->toBeString();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.email', $user->email);
});

it('returns indistinguishable errors for wrong password and unknown email', function () {
    $user = User::factory()->create(['password' => 'sup3r-secret']);

    $wrongPassword = $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertUnprocessable();

    $unknownEmail = $this->postJson('/api/login', [
        'email' => 'nobody@example.com',
        'password' => 'sup3r-secret',
    ])->assertUnprocessable();

    expect($wrongPassword->getContent())->toBe($unknownEmail->getContent())
        ->and($wrongPassword->json('errors.email'))->toBe(['These credentials do not match our records.']);
});

it('throttles repeated login failures', function () {
    $user = User::factory()->create(['password' => 'sup3r-secret']);

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertUnprocessable();
    }

    $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertTooManyRequests();

    $this->postJson('/api/login', [
        'email' => 'other@example.com',
        'password' => 'wrong-password',
    ])->assertUnprocessable();
});

it('rejects unauthenticated and invalid-token access to protected routes', function (string $authorization) {
    $this->withHeader('Authorization', $authorization)
        ->getJson('/api/user')
        ->assertUnauthorized();
})->with([
    'no token' => [''],
    'malformed token' => ['Bearer not-a-real-token'],
]);

it('logs out by revoking only the current token', function () {
    $user = User::factory()->create(['password' => 'sup3r-secret']);

    $issueToken = fn () => $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'sup3r-secret',
    ])->json('data.token');

    $tokenA = $issueToken();
    $tokenB = $issueToken();

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->postJson('/api/logout')
        ->assertNoContent();

    expect($user->tokens()->count())->toBe(1);

    auth()->forgetGuards();

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->getJson('/api/user')
        ->assertUnauthorized();

    auth()->forgetGuards();

    $this->withHeader('Authorization', "Bearer {$tokenB}")
        ->getJson('/api/user')
        ->assertOk();
});
