<?php

namespace App\Services;

use App\Models\Certificate;
use App\Models\ExamAttempt;
use Illuminate\Support\Str;

class CertificateDataService
{
    public function fromIdentifier(string $identifier): ?array
    {
        $attemptId = $this->attemptIdFromIdentifier($identifier);

        if (! $attemptId) {
            return null;
        }

        $attempt = ExamAttempt::with(['user', 'schedule', 'scores'])
            ->whereKey($attemptId)
            ->whereIn('status', ['submitted', 'expired'])
            ->first();

        if (! $attempt || $attempt->scores->isEmpty()) {
            return null;
        }

        return $this->payload($attempt);
    }

    public function payload(ExamAttempt $attempt): array
    {
        $attempt->loadMissing(['user', 'schedule', 'scores']);
        $template = Certificate::where('is_active', true)->latest()->first();
        $year = (int) ($attempt->submitted_at ?? $attempt->schedule->end_at ?? now())->format('Y');
        $prefix = $this->prefix($template?->letter_prefix ?? 'TPA');
        $certificateNo = $this->certificateNumber($attempt, $prefix, $year);

        return [
            'certificate_no' => $certificateNo,
            'participant_name' => $attempt->user?->name,
            'participant_email' => $attempt->user?->email,
            'schedule_id' => $attempt->schedule_id,
            'exam_date' => $attempt->schedule?->start_at?->format('Y-m-d'),
            'certificate_name' => $template?->name ?? 'Sertifikat Tes Potensi Akademik',
            'period' => $template?->period,
            'year' => $template?->year ?? $year,
            'score_total' => (float) $attempt->score_total,
            'verify_url' => url("/certificates/verify/{$certificateNo}"),
            'download_url' => url("/api/certificates/pdf/{$certificateNo}"),
        ];
    }

    private function certificateNumber(ExamAttempt $attempt, string $prefix, int $year): string
    {
        return sprintf('%s-%d-%06d', $prefix, $year, $attempt->id);
    }

    private function attemptIdFromIdentifier(string $identifier): ?int
    {
        if (ctype_digit($identifier)) {
            return (int) $identifier;
        }

        if (preg_match('/(\d+)$/', $identifier, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function prefix(string $prefix): string
    {
        return Str::of($prefix)->upper()->replaceMatches('/[^A-Z0-9]+/', '-')->trim('-')->value() ?: 'TPA';
    }
}
