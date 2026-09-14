<?php

namespace App\Services;

use App\Models\AttemptAnswer;
use App\Models\AttemptEvent;
use App\Models\ExamAttempt;
use App\Models\ExamRegistration;
use App\Models\ExamScore;
use App\Models\QuestionOption;
use App\Models\Schedule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExamAttemptService
{
    /** Gap heartbeat (detik) yang dianggap indikasi koneksi bermasalah. Heartbeat normal tiap 30 detik. */
    public const NETWORK_SUSPECT_GAP_SECONDS = 75;

    public function __construct(private QuestionPackageAssignmentService $packageAssignments) {}

    public function start(User $user, Schedule $schedule): ExamAttempt
    {
        $registration = ExamRegistration::whereBelongsTo($user)->whereBelongsTo($schedule)->first();
        abort_unless($registration, 403, 'Pendaftaran belum ditemukan.');
        abort_if($registration->access_status !== 'unlocked', 403, 'Pembayaran belum selesai.');
        abort_unless(in_array($registration->status, ['paid', 'approved'], true), 403, 'Pendaftaran belum memiliki akses ujian.');
        $retakePending = $this->hasPendingRetake($registration);
        throw_unless(
            $schedule->status === 'published' && ($retakePending || now()->between($schedule->start_at, $schedule->end_at)),
            ValidationException::withMessages(['schedule' => 'Jadwal ujian belum aktif atau sudah berakhir.'])
        );

        $existing = ExamAttempt::where('user_id', $user->id)
            ->where('schedule_id', $schedule->id)
            ->where('status', 'in_progress')
            ->latest('id')
            ->first();
        if ($existing && ! $retakePending) {
            return $existing;
        }

        $latestCompleted = ExamAttempt::where('user_id', $user->id)
            ->where('schedule_id', $schedule->id)
            ->whereIn('status', ['submitted', 'expired'])
            ->latest('id')
            ->first();
        throw_if(
            $latestCompleted && ! $retakePending,
            ValidationException::withMessages([
                'attempt' => 'Ujian ini sudah berakhir. Silakan hubungi admin jika membutuhkan ujian ulang.',
            ])
        );

        return DB::transaction(function () use ($user, $schedule) {
            $registration = ExamRegistration::whereBelongsTo($user)->whereBelongsTo($schedule)->lockForUpdate()->firstOrFail();
            $package = $this->packageAssignments->assignToRegistration($registration);
            $retakePending = $this->hasPendingRetake($registration);

            $latestCompleted = ExamAttempt::where('user_id', $user->id)
                ->where('schedule_id', $schedule->id)
                ->whereIn('status', ['submitted', 'expired'])
                ->lockForUpdate()
                ->latest('id')
                ->first();
            throw_if(
                $latestCompleted && ! $retakePending,
                ValidationException::withMessages([
                    'attempt' => 'Ujian ini sudah berakhir. Silakan hubungi admin jika membutuhkan ujian ulang.',
                ])
            );

            if ($retakePending) {
                $attemptStartedAt = now();
                $attemptExpiresAt = now()->copy()->addMinutes((int) $package->total_duration_minutes);
            } else {
                $attemptStartedAt = $schedule->start_at->copy();
                $attemptExpiresAt = $attemptStartedAt->copy()->addMinutes((int) $package->total_duration_minutes);
                if ($schedule->end_at->lt($attemptExpiresAt)) {
                    $attemptExpiresAt = $schedule->end_at->copy();
                }
            }

            throw_if(
                now()->greaterThanOrEqualTo($attemptExpiresAt),
                ValidationException::withMessages(['schedule' => 'Waktu ujian untuk paket ini sudah habis.'])
            );

            throw_if($package->questions->isEmpty(), ValidationException::withMessages(['package' => 'Paket belum memiliki soal aktif.']));
            $attempt = ExamAttempt::create([
                'user_id' => $user->id,
                'schedule_id' => $schedule->id,
                'question_package_id' => $package->id,
                'status' => 'in_progress',
                'started_at' => $attemptStartedAt,
                'expires_at' => $attemptExpiresAt,
                'metadata' => [
                    'mode' => $retakePending ? 'retake' : 'scheduled',
                    'proctoring' => 'strict',
                ],
            ]);
            foreach ($package->sections as $section) {
                $sectionQuestions = $package->questions->where('exam_section_id', $section->id)->shuffle()->values();
                foreach ($sectionQuestions as $index => $question) {
                    $attempt->attemptQuestions()->create([
                        'question_id' => $question->id, 'exam_section_id' => $question->exam_section_id, 'order_number' => $index + 1,
                        'snapshot_question_text' => $question->question_text, 'snapshot_question_image' => $question->question_image,
                        'snapshot_options' => $this->buildSnapshotOptions($question->options),
                    ]);
                }
            }
            if ($retakePending) {
                $registration->forceFill(['retake_consumed_at' => now()])->save();
            }

            AttemptEvent::create(['exam_attempt_id' => $attempt->id, 'type' => 'started', 'payload' => ['retake' => $retakePending]]);

            return $attempt;
        });
    }

    public function saveAnswer(ExamAttempt $attempt, int $questionId, array $data): AttemptAnswer
    {
        $this->assertInProgress($attempt);
        abort_unless($attempt->attemptQuestions()->where('question_id', $questionId)->exists(), 404, 'Soal tidak termasuk attempt.');
        if (! empty($data['question_option_id'])) {
            abort_unless(QuestionOption::whereKey($data['question_option_id'])->where('question_id', $questionId)->exists(), 422, 'Opsi tidak valid untuk soal ini.');
        }

        return AttemptAnswer::updateOrCreate(['exam_attempt_id' => $attempt->id, 'question_id' => $questionId], ['question_option_id' => $data['question_option_id'] ?? null, 'answer_text' => $data['answer_text'] ?? null, 'is_flagged' => $data['is_flagged'] ?? false, 'answered_at' => now()]);
    }

    public function submit(ExamAttempt $attempt, bool $expired = false): ExamAttempt
    {
        return DB::transaction(function () use ($attempt, $expired) {
            $locked = ExamAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, ['submitted', 'expired'], true)) {
                return $locked;
            }
            $scores = $this->scoreAttempt($locked);
            $locked->update([
                'status' => $expired || now()->greaterThan($locked->expires_at) ? 'expired' : 'submitted',
                'submitted_at' => now(),
                'score_total' => $scores['score_total'],
                'score_breakdown' => $scores['score_breakdown'],
            ]);
            AttemptEvent::create(['exam_attempt_id' => $locked->id, 'type' => 'submitted', 'payload' => ['expired' => $expired]]);

            return $locked->fresh('scores');
        });
    }

    public function terminateForViolation(ExamAttempt $attempt, string $reason, array $context = []): ExamAttempt
    {
        return DB::transaction(function () use ($attempt, $reason, $context) {
            $locked = ExamAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, ['submitted', 'expired'], true)) {
                return $locked->fresh('scores');
            }

            $scores = $this->scoreAttempt($locked);
            $violatedAt = now();
            $lastHeartbeatAt = AttemptEvent::where('exam_attempt_id', $locked->id)
                ->whereIn('type', ['heartbeat', 'started', 'resume_granted', 'connection_restored'])
                ->latest('id')
                ->value('created_at');
            $heartbeatGapSeconds = $lastHeartbeatAt ? (int) Carbon::parse($lastHeartbeatAt)->diffInSeconds($violatedAt) : null;
            $reportDelaySeconds = null;
            if (! empty($context['client_time'])) {
                try {
                    $reportDelaySeconds = (int) abs($violatedAt->diffInSeconds(Carbon::parse($context['client_time'])));
                } catch (\Throwable) {
                    $reportDelaySeconds = null;
                }
            }
            // Heartbeat normalnya tiap 30 detik. Gap yang jauh lebih lama, atau laporan
            // yang sampai ke server jauh setelah kejadian, adalah indikasi kuat koneksi
            // peserta bermasalah, bukan pelanggaran yang disengaja.
            $networkSuspect = ($heartbeatGapSeconds !== null && $heartbeatGapSeconds > self::NETWORK_SUSPECT_GAP_SECONDS)
                || ($reportDelaySeconds !== null && $reportDelaySeconds > self::NETWORK_SUSPECT_GAP_SECONDS)
                || ! empty($context['offline_events']);

            $metadata = array_merge($locked->metadata ?? [], [
                'termination_reason' => 'violation',
                'violation_reason' => $reason,
                'violated_at' => $violatedAt->toISOString(),
                'violation_context' => $context,
                'remaining_ms_at_violation' => max(0, (int) $violatedAt->diffInMilliseconds($locked->expires_at, false)),
                'answered_count_at_violation' => $locked->answers()->whereNotNull('question_option_id')->count(),
                'heartbeat_gap_seconds' => $heartbeatGapSeconds,
                'network_suspect' => $networkSuspect,
            ]);

            $locked->update([
                'status' => 'submitted',
                'submitted_at' => now(),
                'score_total' => $scores['score_total'],
                'score_breakdown' => $scores['score_breakdown'],
                'metadata' => $metadata,
            ]);

            AttemptEvent::create([
                'exam_attempt_id' => $locked->id,
                'type' => 'proctoring_violation',
                'payload' => [
                    'reason' => $reason,
                    'context' => $context,
                ],
            ]);

            return $locked->fresh('scores');
        });
    }

    public function grantRetake(ExamRegistration $registration, ?User $actor = null): ExamRegistration
    {
        return DB::transaction(function () use ($registration, $actor) {
            $lockedRegistration = ExamRegistration::whereKey($registration->id)->lockForUpdate()->firstOrFail();
            throw_if(
                $lockedRegistration->certificate_issued_at,
                ValidationException::withMessages(['certificate' => 'Peserta tidak dapat mengulang tes karena sertifikat sudah pernah diterbitkan.'])
            );

            $grantTime = now();

            ExamAttempt::where('user_id', $lockedRegistration->user_id)
                ->where('schedule_id', $lockedRegistration->schedule_id)
                ->where('status', 'in_progress')
                ->lockForUpdate()
                ->get()
                ->each(function (ExamAttempt $attempt) use ($grantTime, $actor) {
                    $attempt->update([
                        'status' => 'cancelled',
                        'submitted_at' => $grantTime,
                        'metadata' => array_merge($attempt->metadata ?? [], [
                            'termination_reason' => 'retake_reset',
                            'retake_granted_at' => $grantTime->toISOString(),
                            'retake_granted_by' => $actor?->id,
                        ]),
                    ]);

                    AttemptEvent::create([
                        'exam_attempt_id' => $attempt->id,
                        'type' => 'retake_granted',
                        'payload' => [
                            'granted_by' => $actor?->id,
                            'granted_at' => $grantTime->toISOString(),
                        ],
                    ]);
                });

            $lockedRegistration->forceFill([
                'retake_granted_at' => $grantTime,
                'retake_consumed_at' => null,
            ])->save();

            return $lockedRegistration->fresh();
        });
    }

    /**
     * Attempt terakhir peserta yang berhenti karena pelanggaran dan masih bisa dilanjutkan.
     * Null jika attempt terakhir masih berjalan, selesai normal, atau sisa waktunya sudah habis.
     */
    public function resumableAttempt(ExamRegistration $registration): ?ExamAttempt
    {
        $attempt = ExamAttempt::where('user_id', $registration->user_id)
            ->where('schedule_id', $registration->schedule_id)
            ->latest('id')
            ->first();

        if (! $attempt || $attempt->status !== 'submitted') {
            return null;
        }
        if (($attempt->metadata['termination_reason'] ?? null) !== 'violation') {
            return null;
        }
        if ((int) ($attempt->metadata['remaining_ms_at_violation'] ?? 0) <= 0) {
            return null;
        }

        return $attempt;
    }

    /**
     * Dipanggil saat peserta membuka attempt yang baru dilanjutkan admin: mulai hitung
     * sisa waktu dari sekarang dan tambahkan jeda menunggu ke paused_ms.
     */
    public function activateResumedAttempt(ExamAttempt $attempt): ExamAttempt
    {
        if ($attempt->status !== 'in_progress' || empty($attempt->metadata['resume_pending'])) {
            return $attempt;
        }

        return DB::transaction(function () use ($attempt) {
            $locked = ExamAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $metadata = $locked->metadata ?? [];
            if (empty($metadata['resume_pending'])) {
                return $locked;
            }

            $activatedAt = now();
            $remainingMs = (int) ($metadata['resume_remaining_ms'] ?? 0);
            $resumedAt = Carbon::parse($metadata['last_resumed_at'] ?? $activatedAt);
            $waitedMs = max(0, (int) $resumedAt->diffInMilliseconds($activatedAt, false));

            unset($metadata['resume_pending'], $metadata['resume_remaining_ms']);
            $metadata['paused_ms'] = (int) ($metadata['paused_ms'] ?? 0) + $waitedMs;
            $metadata['last_resume_activated_at'] = $activatedAt->toISOString();

            $locked->update([
                'expires_at' => $activatedAt->copy()->addMilliseconds($remainingMs),
                'metadata' => $metadata,
            ]);

            AttemptEvent::create([
                'exam_attempt_id' => $locked->id,
                'type' => 'resume_activated',
                'payload' => ['waited_ms' => $waitedMs, 'remaining_ms' => $remainingMs],
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Lanjutkan attempt yang berhenti karena pelanggaran: jawaban dipertahankan,
     * sisa waktu saat terhenti dikembalikan, skor dihapus supaya dihitung ulang saat submit.
     */
    public function resumeAttempt(ExamRegistration $registration, ?User $actor = null): ExamAttempt
    {
        return DB::transaction(function () use ($registration, $actor) {
            $lockedRegistration = ExamRegistration::whereKey($registration->id)->lockForUpdate()->firstOrFail();
            throw_if(
                $lockedRegistration->certificate_issued_at,
                ValidationException::withMessages(['certificate' => 'Peserta tidak dapat melanjutkan tes karena sertifikat sudah pernah diterbitkan.'])
            );

            $attempt = $this->resumableAttempt($lockedRegistration);
            throw_unless(
                $attempt,
                ValidationException::withMessages(['attempt' => 'Tidak ada attempt yang bisa dilanjutkan. Attempt terakhir masih berjalan, selesai secara normal, atau sisa waktunya sudah habis. Gunakan Ulangi Tes.'])
            );

            $locked = ExamAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $resumeTime = now();
            $violatedAt = Carbon::parse($locked->metadata['violated_at']);
            $remainingMs = (int) $locked->metadata['remaining_ms_at_violation'];
            $pausedMs = max(0, (int) $violatedAt->diffInMilliseconds($resumeTime, false));
            $metadata = $locked->metadata ?? [];

            $history = $metadata['violation_history'] ?? [];
            $history[] = [
                'reason' => $metadata['violation_reason'] ?? null,
                'violated_at' => $metadata['violated_at'] ?? null,
                'network_suspect' => (bool) ($metadata['network_suspect'] ?? false),
                'resumed_at' => $resumeTime->toISOString(),
                'resumed_by' => $actor?->id,
            ];
            unset(
                $metadata['termination_reason'],
                $metadata['violation_reason'],
                $metadata['violated_at'],
                $metadata['violation_context'],
                $metadata['remaining_ms_at_violation'],
                $metadata['answered_count_at_violation'],
                $metadata['heartbeat_gap_seconds'],
                $metadata['network_suspect'],
            );
            $metadata['violation_history'] = $history;
            $metadata['paused_ms'] = (int) ($metadata['paused_ms'] ?? 0) + $pausedMs;
            $metadata['resume_count'] = (int) ($metadata['resume_count'] ?? 0) + 1;
            $metadata['last_resumed_at'] = $resumeTime->toISOString();
            $metadata['last_resumed_by'] = $actor?->id;
            // Sisa waktu baru benar-benar berjalan saat peserta membuka kembali attempt
            // (lihat activateResumedAttempt), bukan saat admin menekan tombol.
            $metadata['resume_pending'] = true;
            $metadata['resume_remaining_ms'] = $remainingMs;

            $locked->scores()->delete();
            $locked->update([
                'status' => 'in_progress',
                'submitted_at' => null,
                'score_total' => null,
                'score_breakdown' => null,
                'expires_at' => $resumeTime->copy()->addMilliseconds($remainingMs),
                'metadata' => $metadata,
            ]);

            // Izin ulangi tes yang belum terpakai dibatalkan supaya tidak bentrok dengan attempt yang dilanjutkan.
            if ($this->hasPendingRetake($lockedRegistration)) {
                $lockedRegistration->forceFill(['retake_consumed_at' => $resumeTime])->save();
            }

            AttemptEvent::create([
                'exam_attempt_id' => $locked->id,
                'type' => 'resume_granted',
                'payload' => [
                    'granted_by' => $actor?->id,
                    'granted_at' => $resumeTime->toISOString(),
                    'paused_ms' => $pausedMs,
                    'remaining_ms' => $remainingMs,
                ],
            ]);

            return $locked->fresh();
        });
    }

    public function recalculateCompletedAttempt(ExamAttempt $attempt): ExamAttempt
    {
        if ($attempt->status === 'in_progress') {
            return $attempt;
        }

        return DB::transaction(function () use ($attempt) {
            $locked = ExamAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $scores = $this->scoreAttempt($locked);

            $locked->update([
                'score_total' => $scores['score_total'],
                'score_breakdown' => $scores['score_breakdown'],
            ]);

            return $locked->fresh('scores');
        });
    }

    private function assertInProgress(ExamAttempt $attempt): void
    {
        throw_unless($attempt->status === 'in_progress', ValidationException::withMessages(['attempt' => 'Attempt sudah selesai.']));
        if (now()->greaterThanOrEqualTo($attempt->expires_at)) {
            $this->submit($attempt, true);
            throw ValidationException::withMessages(['attempt' => 'Waktu ujian sudah habis.']);
        }
    }

    private function scoreAttempt(ExamAttempt $attempt): array
    {
        $attempt->loadMissing(['attemptQuestions', 'answers', 'package.sections']);
        $answers = $attempt->answers->keyBy('question_id');
        $questions = $attempt->attemptQuestions->groupBy('exam_section_id');
        $breakdown = [];
        $sectionScores = [];
        $packageSections = $attempt->package?->sections?->keyBy('id') ?? collect();
        $baseScore = max(0, (int) ($attempt->package?->base_score ?? 200));
        $scoreSpan = max(0, (int) ($attempt->package?->correct_answer_score ?? 600));

        foreach ($questions as $sectionId => $rows) {
            $correct = 0;
            $wrong = 0;
            $unanswered = 0;

            foreach ($rows as $row) {
                $answer = $answers->get($row->question_id);
                if (! $answer || ! $answer->question_option_id) {
                    $unanswered++;

                    continue;
                }

                $isCorrect = QuestionOption::query()
                    ->whereKey($answer->question_option_id)
                    ->where('question_id', $row->question_id)
                    ->where('is_correct', true)
                    ->exists();

                if ($isCorrect) {
                    $correct++;
                } else {
                    $wrong++;
                }
            }

            $totalQuestions = max(
                0,
                (int) ($packageSections->get($sectionId)?->target_question_count ?: $rows->count())
            );
            $rawScore = (float) $correct;
            $finalScore = $this->sectionScore($correct, $totalQuestions, $baseScore, $scoreSpan);
            $sectionScores[] = $finalScore;

            ExamScore::updateOrCreate(
                ['exam_attempt_id' => $attempt->id, 'exam_section_id' => $sectionId],
                [
                    'correct_count' => $correct,
                    'wrong_count' => $wrong,
                    'unanswered_count' => $unanswered,
                    'raw_score' => $rawScore,
                    'final_score' => $finalScore,
                ]
            );

            $breakdown[] = [
                'section_id' => (int) $sectionId,
                'correct_count' => $correct,
                'wrong_count' => $wrong,
                'unanswered_count' => $unanswered,
                'total_questions' => $totalQuestions,
                'raw_score' => $rawScore,
                'final_score' => $finalScore,
            ];
        }

        return [
            'score_total' => (float) $this->totalScore($sectionScores),
            'score_breakdown' => $breakdown,
        ];
    }

    private function sectionScore(int $correctCount, int $totalQuestions, int $baseScore, int $scoreSpan): int
    {
        if ($totalQuestions <= 0) {
            return $baseScore;
        }

        return (int) round($baseScore + (($correctCount / $totalQuestions) * $scoreSpan));
    }

    private function totalScore(array $sectionScores): int
    {
        if ($sectionScores === []) {
            return 200;
        }

        return (int) round(array_sum($sectionScores) / count($sectionScores));
    }

    public function hasPendingRetake(ExamRegistration $registration): bool
    {
        if (! $registration->retake_granted_at) {
            return false;
        }

        return ! $registration->retake_consumed_at || $registration->retake_consumed_at->lt($registration->retake_granted_at);
    }

    private function buildSnapshotOptions($options): array
    {
        $labels = ['A', 'B', 'C', 'D'];

        return $options
            ->shuffle()
            ->values()
            ->map(fn ($option, int $index) => [
                'id' => $option->id,
                'option_label' => $labels[$index] ?? chr(65 + $index),
                'option_text' => $option->option_text,
                'option_image' => $option->option_image,
            ])
            ->all();
    }
}
