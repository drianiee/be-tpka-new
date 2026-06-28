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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExamAttemptService
{
    public function __construct(private QuestionPackageAssignmentService $packageAssignments) {}

    public function start(User $user, Schedule $schedule): ExamAttempt
    {
        $registration = ExamRegistration::whereBelongsTo($user)->whereBelongsTo($schedule)->first();
        abort_unless($registration, 403, 'Pendaftaran belum ditemukan.');
        abort_if($registration->access_status !== 'unlocked', 403, 'Pembayaran belum selesai.');
        abort_unless(in_array($registration->status, ['paid', 'approved'], true), 403, 'Pendaftaran belum memiliki akses ujian.');
        throw_unless($schedule->status === 'published' && now()->between($schedule->start_at, $schedule->end_at), ValidationException::withMessages(['schedule' => 'Jadwal ujian belum aktif atau sudah berakhir.']));

        $existing = ExamAttempt::where('user_id', $user->id)->where('schedule_id', $schedule->id)->where('status', 'in_progress')->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($user, $schedule) {
            $registration = ExamRegistration::whereBelongsTo($user)->whereBelongsTo($schedule)->lockForUpdate()->firstOrFail();
            $package = $this->packageAssignments->assignToRegistration($registration);
            throw_if($package->questions->isEmpty(), ValidationException::withMessages(['package' => 'Paket belum memiliki soal aktif.']));
            $attempt = ExamAttempt::create(['user_id' => $user->id, 'schedule_id' => $schedule->id, 'question_package_id' => $package->id, 'status' => 'in_progress', 'started_at' => now(), 'expires_at' => now()->addMinutes($package->total_duration_minutes)]);
            foreach ($package->sections as $section) {
                $sectionQuestions = $package->questions->where('exam_section_id', $section->id)->shuffle()->values();
                foreach ($sectionQuestions as $index => $question) {
                    $attempt->attemptQuestions()->create([
                        'question_id' => $question->id, 'exam_section_id' => $question->exam_section_id, 'order_number' => $index + 1,
                        'snapshot_question_text' => $question->question_text, 'snapshot_question_image' => $question->question_image,
                        'snapshot_options' => $question->options->shuffle()->map(fn ($o) => ['id' => $o->id, 'option_label' => $o->option_label, 'option_text' => $o->option_text, 'option_image' => $o->option_image])->values()->all(),
                    ]);
                }
            }
            AttemptEvent::create(['exam_attempt_id' => $attempt->id, 'type' => 'started']);

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
        $attempt->loadMissing(['attemptQuestions', 'answers', 'package']);
        $answers = $attempt->answers->keyBy('question_id');
        $questions = $attempt->attemptQuestions->groupBy('exam_section_id');
        $breakdown = [];
        $totalCorrect = 0;
        $baseScore = (int) ($attempt->package?->base_score ?? 200);
        $correctAnswerScore = (int) ($attempt->package?->correct_answer_score ?? 2);

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
                    $totalCorrect++;
                } else {
                    $wrong++;
                }
            }

            $rawScore = (float) $correct;
            $finalScore = (float) ($correct * $correctAnswerScore);

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
                'raw_score' => $rawScore,
                'final_score' => $finalScore,
            ];
        }

        return [
            'score_total' => (float) ($baseScore + ($totalCorrect * $correctAnswerScore)),
            'score_breakdown' => $breakdown,
        ];
    }
}
