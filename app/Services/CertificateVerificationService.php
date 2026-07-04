<?php

namespace App\Services;

use App\Models\ExamRegistration;
use Illuminate\Support\Facades\Storage;

class CertificateVerificationService
{
    public function findValid(string $uuid, ?string $hash): ?ExamRegistration
    {
        if (! $hash) {
            return null;
        }

        $registration = ExamRegistration::query()
            ->where('certificate_verify_uuid', $uuid)
            ->with(['certificate', 'user', 'schedule'])
            ->first();

        if (! $registration || ! $registration->certificate_verify_hash) {
            return null;
        }

        if (! hash_equals($registration->certificate_verify_hash, $hash)) {
            return null;
        }

        return $registration;
    }

    public function metadata(ExamRegistration $registration): array
    {
        $certificate = $registration->certificate;

        return [
            'certificate_no' => $registration->certificate_no,
            'participant_name' => $registration->user?->name,
            'participant_email' => $registration->user?->email,
            'schedule_id' => $registration->schedule_id,
            'exam_date' => $registration->schedule?->start_at?->toDateString(),
            'certificate_name' => $certificate?->name,
            'period' => $certificate?->period,
            'year' => $certificate?->year,
            'status' => $registration->certificate_revoked_at ? 'revoked' : 'valid',
            'issued_at' => $registration->certificate_issued_at?->toISOString(),
            'pdf_available' => $registration->certificate_file_path
                ? Storage::disk('local')->exists($registration->certificate_file_path)
                : false,
        ];
    }
}
