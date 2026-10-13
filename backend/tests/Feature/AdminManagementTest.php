<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminManagementTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): array
    {
        $admin = User::create([
            'name' => 'Admin Principal',
            'email' => 'admin@test.ma',
            'password' => 'motdepasse1',
            'role' => 'admin',
            'active' => true,
        ]);

        return [$admin, $admin->createToken('session')->plainTextToken];
    }

    private function authHeader(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_unauthenticated_users_cannot_manage_administrators(): void
    {
        $this->getJson('/api/admins')->assertUnauthorized();
        $this->postJson('/api/admins', ['name' => 'X', 'email' => 'x@test.ma', 'password' => 'password1', 'password_confirmation' => 'password1'])->assertUnauthorized();
        //(Json) note kept: all admin management requests use *_Json helpers.
    }

    public function test_administrators_can_create_additional_administrators(): void
    {
        [, $token] = $this->actingAdmin();

        $response = $this->postJson('/api/admins', [
            'name' => 'Deuxième Admin',
            'email' => 'admin2@test.ma',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ], $this->authHeader($token));

        $response->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'admin2@test.ma', 'role' => 'admin']);
    }

    public function test_created_admin_password_is_hashed(): void
    {
        [, $token] = $this->actingAdmin();

        $this->postJson('/api/admins', [
            'name' => 'Hash Check',
            'email' => 'hash@test.ma',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ], $this->authHeader($token));

        $raw = \DB::table('users')->where('email', 'hash@test.ma')->value('password');
        $this->assertNotSame('password123', $raw);
        $this->assertStringStartsWith('$2y$', $raw);
    }

    public function test_invalid_administrator_data_is_rejected(): void
    {
        [, $token] = $this->actingAdmin();

        $this->postJson('/api/admins', [
            'name' => '',
            'email' => 'pas-un-email',
            'password' => 'court',
            'password_confirmation' => 'autre',
        ], $this->authHeader($token))->assertStatus(422);

        $this->postJson('/api/admins', [
            'name' => 'Doublon',
            'email' => 'admin@test.ma',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ], $this->authHeader($token))->assertStatus(422);
    }

    public function test_last_active_administrator_cannot_be_disabled(): void
    {
        [$admin, $token] = $this->actingAdmin();

        $this->patchJson("/api/admins/{$admin->id}", ['active' => false], $this->authHeader($token))
            ->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'active' => true]);
    }

    public function test_last_active_administrator_cannot_be_deleted(): void
    {
        [$admin, $token] = $this->actingAdmin();

        $this->deleteJson("/api/admins/{$admin->id}", [], $this->authHeader($token))
            ->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_admin_cannot_delete_their_own_account(): void
    {
        [$admin, $token] = $this->actingAdmin();

        $other = User::create([
            'name' => 'Second',
            'email' => 'second@test.ma',
            'password' => 'motdepasse1',
            'role' => 'admin',
            'active' => true,
        ]);

        // Avec un second admin, la suppression de soi reste interdite.
        $this->deleteJson("/api/admins/{$admin->id}", [], $this->authHeader($token))
            ->assertStatus(422);
    }

    public function test_admin_can_update_and_deactivate_when_another_admin_is_active(): void
    {
        [$admin, $token] = $this->actingAdmin();
        $other = User::create([
            'name' => 'Second',
            'email' => 'second@test.ma',
            'password' => 'motdepasse1',
            'role' => 'admin',
            'active' => true,
        ]);

        $this->patchJson("/api/admins/{$other->id}", ['active' => false], $this->authHeader($token))
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $other->id, 'active' => false]);
        $this->deleteJson("/api/admins/{$other->id}", [], $this->authHeader($token))
            ->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $other->id]);
    }

    public function test_admin_list_never_exposes_passwords(): void
    {
        [, $token] = $this->actingAdmin();

        $response = $this->getJson('/api/admins', $this->authHeader($token))->assertOk();

        $json = $response->getContent();
        $this->assertStringNotContainsString('password', $json);
    }
}
