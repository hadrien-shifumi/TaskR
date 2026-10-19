<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_with_remember_sets_persistent_token(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => true,
        ]);

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->refresh()->remember_token);
    }

    public function test_login_without_remember_does_not_set_remember_token(): void
    {
        $user = User::factory()->create(['remember_token' => null]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->refresh()->remember_token);
    }

    public function test_login_with_remember_false_does_not_set_remember_token(): void
    {
        $user = User::factory()->create(['remember_token' => null]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => false,
        ]);

        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->refresh()->remember_token);
    }

    public function test_login_rejects_non_boolean_remember(): void
    {
        $user = User::factory()->create();

        $this->from(route('login', absolute: false))
            ->post('/login', [
                'email' => $user->email,
                'password' => 'password',
                'remember' => 'banana',
            ])
            ->assertSessionHasErrors('remember');

        $this->assertGuest();
    }

    public function test_login_fails_with_generic_error_on_bad_credentials(): void
    {
        $user = User::factory()->create();

        $this->from(route('login', absolute: false))
            ->post('/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }
}
