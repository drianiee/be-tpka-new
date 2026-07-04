<?php

namespace App\Services;

use App\Models\ExamAttempt;
use App\Models\ExamRegistration;

class CertificateDataService
{
    public function __construct(
        private CertificateAssignmentService $assignment,
        private CertificateScoreProvider $scores,
        private CertificateVerificationService $verification,
    ) {}

    public function payload(ExamAttempt $attempt): ?array
    {
        $registration = ExamRegistration::query()
            ->where('user_id', $attempt->user_id)
            ->where('schedule_id', $attempt->schedule_id)
            ->with(['certificate', 'user', 'schedule'])
            ->first();

        if (! $registration) {
            return null;
        }

        return $this->fromRegistration($registration);
    }

    public function fromRegistration(ExamRegistration $registration, bool $admin = false): ?array
    {
        $registration = $this->assignment
            ->assignToRegistration($registration)
            ->loadMissing(['certificate', 'user', 'schedule']);

        if (! $registration->certificate_verify_uuid || ! $registration->certificate_verify_hash) {
            return null;
        }

        $scores = [];
        try {
            $scores = $this->scores->scoresForRegistration($registration);
        } catch (\Throwable) {
            $scores = [];
        }

        return [
            ...$this->verification->metadata($registration),
            'score_verbal' => (float) ($scores['verbal'] ?? 0),
            'score_kuantitatif' => (float) ($scores['kuantitatif'] ?? 0),
            'score_logika' => (float) ($scores['logika'] ?? 0),
            'score_spasial' => (float) ($scores['spasial'] ?? 0),
            'score_total' => (float) ($scores['total'] ?? 0),
            'verify_url' => $this->frontendVerifyUrl($registration),
            'download_url' => $admin
                ? "/api/admin/certificates/registrations/{$registration->id}/download"
                : "/api/participant/certificate-downloads/{$registration->id}",
            'public_pdf_url' => "/api/certificates/pdf/{$registration->certificate_verify_uuid}?h={$registration->certificate_verify_hash}",
        ];
    }

    private function frontendVerifyUrl(ExamRegistration $registration): string
    {
        return rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/')
            ."/certificates/verify/{$registration->certificate_verify_uuid}?h={$registration->certificate_verify_hash}";
    }
}
