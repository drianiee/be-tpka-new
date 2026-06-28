<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamRegistration;
use App\Services\PartnerInvitationService;
use Illuminate\Http\Request;

class PartnerOnboardingController extends Controller
{
    public function __construct(private PartnerInvitationService $invitations) {}

    public function preview(Request $request)
    {
        $data = $request->validate(['token' => ['required', 'string']]);
        $invitation = $this->invitations->findValidByToken($data['token']);
        $user = $invitation->user;
        $requiresProfile = ! $user?->profile_completed_at;

        return [
            'valid' => true,
            'expired' => false,
            'email' => $invitation->email,
            'name' => $invitation->name,
            'schedule' => [
                'id' => $invitation->schedule->id,
                'title' => $invitation->schedule->title,
                'date' => $invitation->schedule->start_at?->format('Y-m-d H:i:s'),
                'start_time' => $invitation->schedule->start_at?->format('H:i:s'),
                'end_time' => $invitation->schedule->end_at?->format('H:i:s'),
            ],
            'requires_profile_completion' => $requiresProfile,
            'redirect_to' => $requiresProfile ? '/partner/onboarding?token='.$data['token'] : '/dashboard/peserta/my-schedules/'.$invitation->schedule_id,
        ];
    }

    public function claim(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'password' => ['nullable', 'confirmed', 'min:8'],
            'id_type' => ['nullable', 'in:ktp,ktm,sim'],
            'id_image' => ['nullable', 'image', 'max:4096'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'phone' => ['nullable', 'string', 'max:30'],
            'identity_number' => ['nullable', 'string', 'max:100'],
        ]);
        $invitation = $this->invitations->findValidByToken($data['token']);
        $user = $invitation->user;
        abort_if(! $user, 422, 'User undangan tidak ditemukan.');

        if (! $user->profile_completed_at) {
            $request->validate([
                'password' => [$user->password ? 'nullable' : 'required', 'confirmed', 'min:8'],
                'id_type' => ['required', 'in:ktp,ktm,sim'],
                'id_image' => ['required', 'image', 'max:4096'],
                'birth_date' => ['required', 'date', 'before:today'],
                'phone' => ['required', 'string', 'max:30'],
            ]);
            $profile = [
                'email_verified_at' => $user->email_verified_at ?? now(),
                'id_type' => $data['id_type'],
                'birth_date' => $data['birth_date'],
                'phone' => $data['phone'],
                'identity_number' => $data['identity_number'] ?? $invitation->identity_number,
                'id_image_path' => $request->file('id_image')->store('identities', 'public'),
                'profile_completed_at' => now(),
            ];
            if (filled($data['password'] ?? null)) {
                $profile['password'] = $data['password'];
            }
            $user->update($profile);
            $invitation->update(['claimed_at' => now()]);
        }

        return [
            'message' => 'Onboarding berhasil.',
            'user' => $user->fresh(),
            'token' => $user->createToken('frontend')->plainTextToken,
            'schedule_id' => $invitation->schedule_id,
            'partner' => $invitation->schedule->partner ? ['id' => $invitation->schedule->partner->id, 'name' => $invitation->schedule->partner->name] : null,
            'package_id' => ExamRegistration::where('user_id', $user->id)->where('schedule_id', $invitation->schedule_id)->value('question_package_id'),
            'redirect_to' => '/dashboard/peserta/my-schedules/'.$invitation->schedule_id,
        ];
    }
}
