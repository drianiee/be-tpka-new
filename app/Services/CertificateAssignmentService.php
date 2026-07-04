<?php

namespace App\Services;

use App\Models\Certificate;
use App\Models\ExamRegistration;
use Illuminate\Support\Str;

class CertificateAssignmentService
{
    public function __construct(private CertificateTemplateService $templates) {}

    public function assignToRegistration(ExamRegistration $registration, ?Certificate $template = null): ExamRegistration
    {
        $registration->loadMissing(['schedule.certificate']);

        if (
            $registration->certificate_id
            && $registration->certificate_no
            && $registration->certificate_verify_uuid
            && $registration->certificate_verify_hash
        ) {
            return $registration;
        }

        $template ??= $registration->certificate
            ?? $registration->schedule?->certificate
            ?? $this->templates->active();
        if (! $template) {
            return $registration;
        }

        $uuid = $registration->certificate_verify_uuid ?: (string) Str::uuid();

        $registration->forceFill([
            'certificate_id' => $registration->certificate_id ?: $template->id,
            'certificate_no' => $registration->certificate_no ?: $this->certificateNumber($registration, $template),
            'certificate_verify_uuid' => $uuid,
            'certificate_verify_hash' => $registration->certificate_verify_hash ?: $this->verifyHash($uuid),
        ])->save();

        return $registration->fresh(['certificate']);
    }

    public function verifyHash(string $uuid): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            $key = $decoded !== false ? $decoded : $key;
        }

        return hash_hmac('sha256', $uuid, $key);
    }

    private function certificateNumber(ExamRegistration $registration, Certificate $template): string
    {
        $prefix = trim(trim($template->letter_prefix), '/');

        return sprintf('%s/%d/%06d', $prefix !== '' ? $prefix : 'TPA', $registration->schedule_id, $registration->id);
    }
}
