<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use App\Services\EmailVerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private EmailVerificationService $emailVerification) {}

    public function login(LoginRequest $request)
    {
        $user = User::where('email', $request->string('email'))->first();
        if (! $user || ! $user->password || ! Hash::check($request->string('password'), $user->password) || ! $user->is_active) {
            throw ValidationException::withMessages(['email' => 'Email/password salah atau akun tidak aktif.']);
        }
        if ($user->role === 'peserta' && ! $user->email_verified_at) {
            return response()->json([
                'message' => 'Email belum diverifikasi. Silakan cek email untuk verifikasi.',
                'requires_email_verification' => true,
            ], 403);
        }

        return ['user' => $user, 'token' => $user->createSessionToken(), 'requires_profile_completion' => $user->role === 'peserta' && ! $user->profile_completed_at];
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ]);
        $user = User::create([...$data, 'role' => 'peserta', 'is_active' => true]);
        $this->emailVerification->send($user);

        return response()->json(['message' => 'Registrasi berhasil. Silakan cek email untuk verifikasi.', 'requires_email_verification' => true], 201);
    }

    public function verifyEmail(Request $request)
    {
        $data = $request->validate(['token' => ['required', 'string']]);
        $user = $this->emailVerification->verify($data['token']);

        return [
            'message' => 'Email berhasil diverifikasi.',
            'next' => '/complete-profile',
            'user' => $user,
            'token' => $user->createSessionToken(),
        ];
    }

    public function me(Request $request)
    {
        return $request->user();
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return ['message' => 'Logout berhasil.'];
    }

    public function completeProfile(Request $request)
    {
        abort_if($request->user()->role === 'peserta' && ! $request->user()->email_verified_at, 403, 'Email harus diverifikasi sebelum melengkapi profil.');
        $data = $request->validate(['id_type' => ['required', 'in:ktp,ktm,sim'], 'id_image' => ['required', 'image', 'max:4096'], 'birth_date' => ['required', 'date', 'before:today'], 'phone' => ['required', 'string', 'max:30'], 'identity_number' => ['nullable', 'string', 'max:100']]);
        $data['id_image_path'] = $request->file('id_image')->store('identities', 'public');
        unset($data['id_image']);
        $data['profile_completed_at'] = now();
        $request->user()->update($data);

        return ['message' => 'Profil berhasil dilengkapi.', 'user' => $request->user()->fresh(), 'token' => $request->bearerToken()];
    }

    public function resend(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $key = 'email-verification:'.sha1(strtolower($data['email']).'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return response()->json(['message' => 'Terlalu banyak permintaan. Coba lagi beberapa menit.'], 429);
        }
        RateLimiter::hit($key, 300);
        $user = User::where('email', $data['email'])->first();
        if ($user && ! $user->email_verified_at) {
            $this->emailVerification->send($user);
        }
        if ($user && $user->email_verified_at) {
            return ['message' => 'Email sudah diverifikasi.'];
        }

        return ['message' => 'Jika akun terdaftar, email verifikasi akan dikirim.'];
    }
}
