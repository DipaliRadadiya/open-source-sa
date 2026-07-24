<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

it('updates the password with correct current password', function () {
    $user = User::factory()->create(['password' => bcrypt('OldPassword123')]);
    $token = $user->createToken('test')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/auth/password', [
            'current_password' => 'OldPassword123',
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ]);

    $response->assertOk()->assertJsonStructure(['token']);
    expect(Hash::check('NewPassword123', $user->fresh()->password))->toBeTrue();
});

it('rejects password update with wrong current password', function () {
    $user = User::factory()->create(['password' => bcrypt('OldPassword123')]);
    $token = $user->createToken('test')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/auth/password', [
            'current_password' => 'WrongPassword',
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ]);

    $response->assertUnprocessable();
});

it('revokes all existing tokens when password changes', function () {
    $user = User::factory()->create(['password' => bcrypt('OldPassword123')]);
    $oldToken = $user->createToken('old')->plainTextToken;
    $oldTokenId = explode('|', $oldToken)[0];

    $this->withHeader('Authorization', "Bearer {$oldToken}")
        ->putJson('/api/auth/password', [
            'current_password' => 'OldPassword123',
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ]);

    expect(PersonalAccessToken::find($oldTokenId))->toBeNull();
    expect($user->fresh()->tokens()->count())->toBe(1);
});
