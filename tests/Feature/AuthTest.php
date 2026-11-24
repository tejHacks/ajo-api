<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_returns_a_token_that_works(): void
    {
        $res = $this->postJson('/api/register', ['name' => 'Ada', 'email' => 'Ada@Example.com', 'password' => 'secret123'])
            ->assertCreated()
            ->assertJsonPath('user.email', 'ada@example.com');

        $this->withToken($res->json('token'))->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('name', 'Ada');
    }

    public function test_register_rejects_a_taken_email_and_a_short_password(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson('/api/register', ['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'short'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_with_the_right_password(): void
    {
        User::factory()->create(['email' => 'ada@example.com', 'password' => 'secret123']);

        $this->postJson('/api/login', ['email' => 'ADA@example.com', 'password' => 'secret123'])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);
    }

    public function test_login_with_the_wrong_password_fails(): void
    {
        User::factory()->create(['email' => 'ada@example.com', 'password' => 'secret123']);

        $this->postJson('/api/login', ['email' => 'ada@example.com', 'password' => 'nope'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_groups_need_a_signed_in_user(): void
    {
        $this->getJson('/api/groups')->assertUnauthorized();
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_a_request_without_json_headers_still_gets_a_json_401(): void
    {
        $this->get('/api/groups')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_logout_revokes_the_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('phone')->plainTextToken;

        $this->withToken($token)->postJson('/api/logout')->assertOk();
        $this->assertSame(0, $user->tokens()->count());
    }
}
