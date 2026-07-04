<?php

namespace App\Services;

use App\Models\ExamAttempt;
use App\Models\ExamRegistration;
use Illuminate\Validation\ValidationException;

class CertificateScoreProvider
{
    public function __construct(private ExamAttemptService $attempts) {}

    public function scoresForRegistration(ExamRegistration $registration): array
    {
        $attempt = ExamAttempt::query()
            ->where('user_id', $registration->user_id)
            ->where('schedule_id', $registration->schedule_id)
            ->whereIn('status', ['submitted', 'expired'])
            ->with(['scores', 'package.sections'])
            ->latest('submitted_at')
            ->latest('id')
            ->first();

        throw_unless($attempt, ValidationException::withMessages([
            'certificate' => 'Sertifikat belum dapat dibuat karena peserta belum menyelesaikan ujian.',
        ]));

        $attempt = $this->attempts
            ->recalculateCompletedAttempt($attempt)
            ->loadMissing(['scores', 'package.sections']);

        $scoresBySectionId = $attempt->scores->keyBy('exam_section_id');
        $result = [
            'verbal' => 0.0,
            'kuantitatif' => 0.0,
            'logika' => 0.0,
            'spasial' => 0.0,
        ];

        foreach (($attempt->package?->sections ?? collect()) as $section) {
            $code = strtolower((string) $section->code);
            if (! array_key_exists($code, $result)) {
                continue;
            }

            $result[$code] = (float) ($scoresBySectionId->get($section->id)?->final_score ?? 0);
        }

        $result['total'] = (float) ($attempt->score_total ?? array_sum($result));
        $result['attempt_id'] = $attempt->id;

        return $result;
    }
}
