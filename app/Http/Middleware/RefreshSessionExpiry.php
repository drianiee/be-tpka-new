<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menggeser tenggat kedaluwarsa token setiap ada request terautentikasi,
 * sehingga sesi berakhir karena tidak aktif, bukan karena umur semata.
 */
class RefreshSessionExpiry
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $token = $request->user()?->currentAccessToken();
        $idleMinutes = (int) config('sanctum.idle_expiration');

        if ($token instanceof PersonalAccessToken && $idleMinutes > 0) {
            $nextExpiry = now()->addMinutes($idleMinutes);

            // Tenggat geser tidak boleh melewati masa berlaku maksimum token.
            $maxMinutes = (int) config('sanctum.expiration');
            if ($maxMinutes > 0 && $token->created_at) {
                $nextExpiry = $nextExpiry->min($token->created_at->copy()->addMinutes($maxMinutes));
            }

            // Sanctum sudah menulis last_used_at tiap request; tulis expires_at
            // hanya kalau bergeser lebih dari semenit supaya tidak menambah beban.
            if (! $token->expires_at || abs($token->expires_at->diffInSeconds($nextExpiry, false)) > 60) {
                $token->forceFill(['expires_at' => $nextExpiry])->save();
            }
        }

        return $response;
    }
}
