<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request)
    {
        $user = User::where('email', $request->string('email'))->first();
        if (! $user || ! Hash::check($request->string('password'), $user->password) || ! $user->is_active) {
            throw ValidationException::withMessages(['email' => 'Email/password salah atau akun tidak aktif.']);
        }

        return ['user' => $user, 'token' => $user->createToken('frontend')->plainTextToken];
    }

    public function register(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'unique:users'], 'password' => ['required', 'confirmed', 'min:8']]);
        $user = User::create([...$data, 'role' => 'peserta', 'is_active' => true]);

        return response()->json(['message' => 'Registrasi berhasil.', 'user' => $user, 'token' => $user->createToken('frontend')->plainTextToken], 201);
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
        $data = $request->validate(['id_type' => ['required', 'in:ktp,ktm,sim'], 'id_image' => ['required', 'image', 'max:4096'], 'birth_date' => ['required', 'date', 'before:today'], 'phone' => ['required', 'string', 'max:30']]);
        $data['id_image_path'] = $request->file('id_image')->store('identities', 'public');
        unset($data['id_image']);
        $data['profile_completed_at'] = now();
        $request->user()->update($data);

        return ['message' => 'Profil berhasil dilengkapi.', 'user' => $request->user()->fresh(), 'token' => $request->bearerToken()];
    }

    public function resend(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);

        return ['message' => 'Jika akun terdaftar, email verifikasi akan dikirim.'];
    }
}
