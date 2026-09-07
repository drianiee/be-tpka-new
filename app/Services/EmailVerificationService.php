<?php

namespace App\Services;

use App\Mail\EmailVerificationMail;
use App\Models\EmailVerificationToken;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class EmailVerificationService
{
    public function send(User $user): string
    {
        EmailVerificationToken::where('user_id', $user->id)->whereNull('used_at')->update(['used_at' => now()]);

        $token = Str::random(64);
        EmailVerificationToken::create([
            'user_id' => $user->id,
            'email' => $user->email,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHours(2),
        ]);

        Mail::to($user->email)->send(new EmailVerificationMail($user, $this->frontendUrl($token)));

        return $token;
    }

    public function verify(string $token): User
    {
        $row = EmailVerificationToken::where('token_hash', hash('sha256', $token))->first();
        abort_if(! $row || $row->used_at, 422, 'Token verifikasi tidak valid atau sudah digunakan.');
        abort_if(now()->gt($row->expires_at), 422, 'Token verifikasi sudah kedaluwarsa.');

        $user = $row->user;
        $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();
        $row->update(['used_at' => now()]);

        return $user->fresh();
    }

    private function frontendUrl(string $token): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/verify-email?token='.$token;
    }
}
