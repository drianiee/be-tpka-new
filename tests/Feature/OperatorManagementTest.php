<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperatorManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_an_operator_requires_matching_password_confirmation(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@tpka.com')->firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/users/operator', [
                'name' => 'Operator Baru',
                'email' => 'operator.baru@tpka.com',
                'password' => 'Rahasia123',
                'password_confirmation' => 'Beda12345',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'operator.baru@tpka.com']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/users/operator', [
                'name' => 'Operator Baru',
                'email' => 'operator.baru@tpka.com',
                'password' => 'Rahasia123',
                'password_confirmation' => 'Rahasia123',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'operator.baru@tpka.com', 'role' => 'operator']);
    }

    public function test_admin_can_change_operator_password_and_old_sessions_are_revoked(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'admin@tpka.com')->firstOrFail();
        $operator = User::create([
            'name' => 'Operator Lama',
            'email' => 'operator.lama@tpka.com',
            'password' => 'LamaSekali1',
            'role' => 'operator',
            'is_active' => true,
        ]);
        $operator->createToken('sesi-lama');
        $this->assertSame(1, $operator->tokens()->count());

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/operators/{$operator->id}/password", [
                'password' => 'BaruBanget9',
                'password_confirmation' => 'Salah12345',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/operators/{$operator->id}/password", [
                'password' => 'BaruBanget9',
                'password_confirmation' => 'BaruBanget9',
            ])
            ->assertOk();

        // Semua sesi lama operator dicabut begitu password diganti.
        $this->assertSame(0, $operator->fresh()->tokens()->count());

        $this->postJson('/api/auth/login', ['email' => $operator->email, 'password' => 'LamaSekali1'])
            ->assertStatus(422);
        $this->postJson('/api/auth/login', ['email' => $operator->email, 'password' => 'BaruBanget9'])
            ->assertOk();
    }

    public function test_operator_cannot_change_another_operator_password(): void
    {
        $this->seed(DatabaseSeeder::class);
        $operator = User::create([
            'name' => 'Operator Satu',
            'email' => 'operator.satu@tpka.com',
            'password' => 'Password1',
            'role' => 'operator',
            'is_active' => true,
        ]);
        $target = User::create([
            'name' => 'Operator Dua',
            'email' => 'operator.dua@tpka.com',
            'password' => 'Password1',
            'role' => 'operator',
            'is_active' => true,
        ]);

        $this->actingAs($operator, 'sanctum')
            ->patchJson("/api/admin/operators/{$target->id}/password", [
                'password' => 'Diubah123',
                'password_confirmation' => 'Diubah123',
            ])
            ->assertForbidden();
    }
}
