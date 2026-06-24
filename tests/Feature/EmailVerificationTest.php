<?php

namespace Tests\Feature;

use App\Mail\EmailVerificationMail;
use App\Models\EmailVerificationToken;
use App\Models\User;
use App\Services\EmailVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_unverified_user_and_sends_verification_email(): void
    {
        Mail::fake();

        $this->postJson('/api/auth/register', [
            'name' => 'Peserta Baru',
            'email' => 'baru@example.com',
            'password' => 'Password=1',
            'password_confirmation' => 'Password=1',
        ])->assertCreated()
            ->assertJsonPath('requires_email_verification', true)
            ->assertJsonMissing(['token']);

        $user = User::where('email', 'baru@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertDatabaseHas('email_verification_tokens', ['user_id' => $user->id, 'used_at' => null]);
        $this->assertTrue(EmailVerificationToken::firstOrFail()->expires_at->between(now()->addMinutes(119), now()->addMinutes(121)));
        Mail::assertSent(EmailVerificationMail::class, fn ($mail) => $mail->hasTo('baru@example.com'));
    }

    public function test_verify_email_token_is_single_use_and_complete_profile_requires_verified_email(): void
    {
        Storage::fake('public');
        Mail::fake();
        $user = User::create([
            'name' => 'Peserta Baru',
            'email' => 'baru@example.com',
            'password' => 'Password=1',
            'role' => 'peserta',
            'is_active' => true,
        ]);

        $token = app(EmailVerificationService::class)->send($user);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/complete-profile', [
                'id_type' => 'ktp',
                'birth_date' => '2000-01-01',
                'phone' => '081234567890',
                'id_image' => UploadedFile::fake()->image('ktp.jpg'),
            ])->assertForbidden()
            ->assertJsonPath('message', 'Email harus diverifikasi sebelum melengkapi profil.');

        $this->postJson('/api/auth/email/verify', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('message', 'Email berhasil diverifikasi.')
            ->assertJsonStructure(['token', 'user']);

        $this->assertNotNull($user->fresh()->email_verified_at);

        $this->postJson('/api/auth/email/verify', ['token' => $token])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Token verifikasi tidak valid atau sudah digunakan.');

        $this->actingAs($user->fresh(), 'sanctum')
            ->postJson('/api/auth/complete-profile', [
                'id_type' => 'ktp',
                'birth_date' => '2000-01-01',
                'phone' => '081234567890',
                'id_image' => UploadedFile::fake()->image('ktp.jpg'),
            ])->assertOk()
            ->assertJsonPath('message', 'Profil berhasil dilengkapi.');

        $this->assertNotNull($user->fresh()->profile_completed_at);
    }

    public function test_expired_email_verification_token_is_rejected(): void
    {
        $user = User::create([
            'name' => 'Peserta Baru',
            'email' => 'baru@example.com',
            'password' => 'Password=1',
            'role' => 'peserta',
            'is_active' => true,
        ]);
        $token = 'expired-token';
        EmailVerificationToken::create([
            'user_id' => $user->id,
            'email' => $user->email,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/auth/email/verify', ['token' => $token])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Token verifikasi sudah kedaluwarsa.');
    }
}
