<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'email_verified_at', 'password', 'role', 'is_active', 'phone', 'birth_date', 'id_type', 'identity_number', 'id_image_path', 'profile_completed_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'birth_date' => 'date',
            'profile_completed_at' => 'datetime',
        ];
    }

    /** Token login dengan tenggat idle; digeser tiap request oleh RefreshSessionExpiry. */
    public function createSessionToken(string $name = 'frontend'): string
    {
        $idleMinutes = (int) config('sanctum.idle_expiration');

        return $this->createToken($name, ['*'], $idleMinutes > 0 ? now()->addMinutes($idleMinutes) : null)->plainTextToken;
    }
}
