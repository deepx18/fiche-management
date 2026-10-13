<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): User
    {
        return User::create([
            'name' => 'Admin Test',
            'email' => 'admin@test.ma',
            'password' => 'motdepasse1',
            'role' => 'admin',
            'active' => true,
        ]);
    }

    public function test_unauthenticated_users_cannot_access_protected_endpoints(): void
    {
        $this->getJson('/api/files')->assertUnauthorized();
        $this->getJson('/api/user')->assertUnauthorized();
        $this->getJson('/api/dashboard')->assertUnauthorized();
        $this->getJson('/api/import-history')->assertUnauthorized();
        $this->getJson('/api/admins')->assertUnauthorized();
    }

    public function test_valid_administrator_credentials_allow_login(): void
    {
        $this->createAdmin();

        $response = $this->postJson('/api/login', [
            'email' => 'admin@test.ma',
            'password' => 'motdepasse1',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'role', 'active']]);

        // Le mot de passe n'est jamais renvoyé.
        $this->assertArrayNotHasKey('password', $response->json('user'));
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $this->createAdmin();

        $this->postJson('/api/login', ['email' => 'admin@test.ma', 'password' => 'nope'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_rate_limited_after_repeated_failures(): void
    {
        $this->createAdmin();

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/login', ['email' => 'admin@test.ma', 'password' => 'nope']);
        }

        $this->postJson('/api/login', ['email' => 'admin@test.ma', 'password' => 'motdepasse1'])
            ->assertStatus(429);
    }

    public function test_logout_invalidates_the_token(): void
    {
        $admin = $this->createAdmin();
        $token = $admin->createToken('session')->plainTextToken;

        $this->postJson('/api/logout', [], ['Authorization' => "Bearer {$token}"])
            ->assertOk();

        // Révocation vérifiée en base. (La revérification HTTP dans le même
        // test n'est pas fiable : le RequestGuard de Sanctum met en cache
        // l'utilisateur résolu, et les tests exécutent plusieurs requêtes
        // dans un même noyau.)
        $this->assertSame(0, \DB::table('personal_access_tokens')->count());
        $this->assertNull(\Laravel\Sanctum\PersonalAccessToken::findToken($token));
    }

    public function test_suspended_admin_cannot_login(): void
    {
        User::create([
            'name' => 'Suspendu',
            'email' => 'suspended@test.ma',
            'password' => 'motdepasse1',
            'role' => 'admin',
            'active' => false,
        ]);

        $this->postJson('/api/login', ['email' => 'suspended@test.ma', 'password' => 'motdepasse1'])
            ->assertStatus(422);
    }

    public function test_public_administrator_registration_is_unavailable(): void
    {
        $this->postJson('/api/register', ['name' => 'X', 'email' => 'x@test.ma', 'password' => 'password1'])
            ->assertNotFound();

        $this->postJson('/register', ['name' => 'X', 'email' => 'x@test.ma', 'password' => 'password1'])
            ->assertNotFound();
    }

    public function test_change_password_with_current_password(): void
    {
        $admin = $this->createAdmin();
        $token = $admin->createToken('session')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/user/password', [
                'current_password' => 'wrong',
                'password' => 'nouveaumdp1',
                'password_confirmation' => 'nouveaumdp1',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/user/password', [
                'current_password' => 'motdepasse1',
                'password' => 'nouveaumdp1',
                'password_confirmation' => 'nouveaumdp1',
            ])
            ->assertOk();

        $this->postJson('/api/login', ['email' => 'admin@test.ma', 'password' => 'nouveaumdp1'])
            ->assertOk();
    }
}
