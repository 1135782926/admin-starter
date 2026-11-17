<?php

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

test('returns 401 when the API token is missing or invalid', function (array $headers): void {
    $response = $this->get('/api/user', $headers);

    $response->assertUnauthorized()
        ->assertJsonPath('message', 'Unauthenticated.');
})->with([
    'missing token' => [[]],
    'invalid token' => [['Authorization' => 'Bearer invalid-token']],
]);

test('returns the authenticated user without credentials for a valid API token', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('api-test')->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/user');

    $response->assertOk()
        ->assertJsonPath('id', $user->id)
        ->assertJsonPath('email', $user->email)
        ->assertJsonMissingPaths(['password', 'remember_token']);
});
