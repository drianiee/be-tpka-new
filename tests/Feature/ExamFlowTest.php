<?php

namespace Tests\Feature;

use App\Models\ExamAttempt;
use App\Models\ExamRegistration;
use App\Models\ExamScore;
use App\Models\ExamSection;
use App\Models\Certificate;
use App\Models\Payment;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\QuestionPackage;
use App\Models\Schedule;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExamFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_participant_can_complete_internal_exam_without_answer_key_leak(): void
    {
        $this->seed(DatabaseSeeder::class);
        $participant = User::where('email', 'peserta@tpka.com')->firstOrFail();
        $schedule = $this->createExamFixture($participant);

        $login = $this->postJson('/api/auth/login', ['email' => 'peserta@tpka.com', 'password' => 'password'])
            ->assertOk()->assertJsonStructure(['user', 'token']);
        $headers = ['Authorization' => 'Bearer '.$login->json('token')];

        $this->getJson('/api/me', $headers)
            ->assertOk()
            ->assertJsonPath('email', 'peserta@tpka.com');

        $this->getJson('/api/participant/schedules', $headers)
            ->assertOk()
            ->assertJsonFragment(['id' => $schedule->id]);

        $attemptResponse = $this->postJson("/api/participant/schedules/{$schedule->id}/attempts", [], $headers)
            ->assertSuccessful()->assertJsonPath('status', 'in_progress')->assertJsonMissing(['is_correct']);
        $attemptId = $attemptResponse->json('id');
        $questionId = $attemptResponse->json('questions.0.id');
        $correctOption = QuestionOption::where('question_id', $questionId)->where('is_correct', true)->firstOrFail();

        $this->getJson("/api/participant/attempts/{$attemptId}", $headers)
            ->assertOk()
            ->assertJsonStructure(['server_time', 'started_at', 'expires_at', 'sections', 'questions', 'answers'])
            ->assertJsonMissing(['is_correct']);

        $this->putJson("/api/participant/attempts/{$attemptId}/answers/{$questionId}", ['question_option_id' => $correctOption->id], $headers)
            ->assertOk()->assertJsonPath('message', 'Jawaban tersimpan.');

        $this->postJson("/api/participant/attempts/{$attemptId}/answers/batch", ['answers' => [['question_id' => $questionId, 'question_option_id' => $correctOption->id]]], $headers)
            ->assertOk()
            ->assertJsonPath('message', 'Jawaban tersimpan.');

        $first = $this->postJson("/api/participant/attempts/{$attemptId}/submit", [], $headers)
            ->assertOk()->assertJsonPath('status', 'submitted');
        $second = $this->postJson("/api/participant/attempts/{$attemptId}/submit", [], $headers)
            ->assertOk()->assertJsonPath('status', 'submitted');
        $first->assertJsonPath('score_total', 202)
            ->assertJsonPath('score_breakdown.0.correct_count', 1)
            ->assertJsonPath('score_breakdown.0.final_score', 2);

        $this->getJson("/api/participant/attempts/{$attemptId}/result", $headers)
            ->assertOk()
            ->assertJsonPath('status', 'submitted')
            ->assertJsonPath('score_total', 202);

        $scores = $this->getJson('/api/participant/scores', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.scores.verbal', 2)
            ->assertJsonPath('data.0.scores.total', 202)
            ->assertJsonPath('data.0.certificate.certificate_no', 'TPA/'.$schedule->id.'/'.str_pad((string) ExamRegistration::where('schedule_id', $schedule->id)->where('user_id', $participant->id)->value('id'), 6, '0', STR_PAD_LEFT));

        $verifyUrl = $scores->json('data.0.certificate.verify_url');
        $verifyParts = parse_url($verifyUrl);
        parse_str($verifyParts['query'] ?? '', $verifyQuery);
        $uuid = basename($verifyParts['path'] ?? '');

        $this->getJson('/api/certificates/data/'.$uuid.'?h='.($verifyQuery['h'] ?? ''))
            ->assertOk()
            ->assertJsonPath('data.participant_email', 'peserta@tpka.com');

        $this->assertSame($first->json('score_total'), $second->json('score_total'));
        $this->assertDatabaseCount('exam_attempts', 1);
        $this->assertDatabaseCount('exam_scores', 1);
        $this->assertSame(1, ExamAttempt::firstOrFail()->answers()->count());
    }

    public function test_participant_scores_recalculate_legacy_completed_attempts(): void
    {
        $this->seed(DatabaseSeeder::class);
        $participant = User::where('email', 'peserta@tpka.com')->firstOrFail();
        $schedule = $this->createExamFixture($participant);

        $login = $this->postJson('/api/auth/login', ['email' => 'peserta@tpka.com', 'password' => 'password'])
            ->assertOk();
        $headers = ['Authorization' => 'Bearer '.$login->json('token')];

        $attemptId = $this->postJson("/api/participant/schedules/{$schedule->id}/attempts", [], $headers)
            ->assertSuccessful()
            ->json('id');
        $questionId = ExamAttempt::findOrFail($attemptId)->attemptQuestions()->value('question_id');
        $correctOptionId = QuestionOption::where('question_id', $questionId)
            ->where('is_correct', true)
            ->value('id');

        $this->putJson("/api/participant/attempts/{$attemptId}/answers/{$questionId}", [
            'question_option_id' => $correctOptionId,
        ], $headers)->assertOk();

        $this->postJson("/api/participant/attempts/{$attemptId}/submit", [], $headers)->assertOk();

        ExamAttempt::whereKey($attemptId)->update([
            'score_total' => 0,
            'score_breakdown' => [],
        ]);

        ExamScore::query()->where('exam_attempt_id', $attemptId)->delete();

        $this->getJson('/api/participant/scores', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.score_total', 202)
            ->assertJsonPath('data.0.scores.verbal', 2)
            ->assertJsonPath('data.0.scores.total', 202);
    }

    public function test_certificate_download_is_idempotent_and_blocks_retake_after_issue(): void
    {
        $this->seed(DatabaseSeeder::class);
        $participant = User::where('email', 'peserta@tpka.com')->firstOrFail();
        $admin = User::where('role', 'administrator')->firstOrFail();
        $schedule = $this->createExamFixture($participant);

        $login = $this->postJson('/api/auth/login', ['email' => 'peserta@tpka.com', 'password' => 'password'])
            ->assertOk();
        $headers = ['Authorization' => 'Bearer '.$login->json('token')];

        $attempt = $this->postJson("/api/participant/schedules/{$schedule->id}/attempts", [], $headers)
            ->assertSuccessful();
        $questionId = $attempt->json('questions.0.id');
        $correctOptionId = QuestionOption::where('question_id', $questionId)
            ->where('is_correct', true)
            ->value('id');

        $this->putJson("/api/participant/attempts/{$attempt->json('id')}/answers/{$questionId}", [
            'question_option_id' => $correctOptionId,
        ], $headers)->assertOk();
        $this->postJson("/api/participant/attempts/{$attempt->json('id')}/submit", [], $headers)->assertOk();

        $registration = ExamRegistration::where('user_id', $participant->id)->where('schedule_id', $schedule->id)->firstOrFail();
        $this->get("/api/participant/certificate-downloads/{$registration->id}", $headers)
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $issued = $registration->fresh();
        $this->assertNotNull($issued->certificate_issued_at);
        $this->assertNotNull($issued->certificate_file_path);

        $this->get("/api/participant/certificate-downloads/{$registration->id}", $headers)
            ->assertOk();
        $this->assertSame($issued->certificate_file_path, $registration->fresh()->certificate_file_path);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/schedules/{$schedule->id}/participants/{$participant->id}/retake")
            ->assertStatus(422)
            ->assertJsonPath('errors.certificate.0', 'Peserta tidak dapat mengulang tes karena sertifikat sudah pernah diterbitkan.');
    }

    public function test_participant_cannot_start_unregistered_schedule(): void
    {
        $this->seed(DatabaseSeeder::class);
        $participant = User::where('email', 'peserta@tpka.com')->firstOrFail();
        $schedule = $this->createExamFixture($participant);
        $schedule->registrations()->where('user_id', $participant->id)->delete();
        $this->actingAs($participant, 'sanctum')->postJson("/api/participant/schedules/{$schedule->id}/attempts")
            ->assertForbidden()->assertJsonPath('message', 'Pendaftaran belum ditemukan.');
    }

    public function test_attempt_time_is_anchored_to_schedule_start(): void
    {
        $this->seed(DatabaseSeeder::class);
        $participant = User::where('email', 'peserta@tpka.com')->firstOrFail();
        $schedule = $this->createExamFixture($participant);

        $login = $this->postJson('/api/auth/login', ['email' => 'peserta@tpka.com', 'password' => 'password'])
            ->assertOk();
        $headers = ['Authorization' => 'Bearer '.$login->json('token')];

        $attempt = $this->postJson("/api/participant/schedules/{$schedule->id}/attempts", [], $headers)
            ->assertSuccessful();

        $this->assertSame($schedule->start_at->toISOString(), $attempt->json('started_at'));
        $this->assertSame($schedule->start_at->copy()->addMinutes(30)->toISOString(), $attempt->json('expires_at'));
    }

    public function test_participant_my_schedule_marks_unlocked_regular_schedule_as_paid(): void
    {
        $this->seed(DatabaseSeeder::class);
        $participant = User::where('email', 'peserta@tpka.com')->firstOrFail();
        $schedule = $this->createExamFixture($participant);
        $registration = ExamRegistration::where('user_id', $participant->id)
            ->where('schedule_id', $schedule->id)
            ->firstOrFail();

        Payment::create([
            'user_id' => $participant->id,
            'schedule_id' => $schedule->id,
            'exam_registration_id' => $registration->id,
            'order_id' => 'TPKA-TEST-PENDING',
            'amount' => 150000,
            'transaction_status' => 'pending',
        ]);

        $login = $this->postJson('/api/auth/login', ['email' => 'peserta@tpka.com', 'password' => 'password'])
            ->assertOk();
        $headers = ['Authorization' => 'Bearer '.$login->json('token')];

        $this->getJson('/api/participant/my-schedules', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.participant_status_label', 'Telah melakukan pembayaran')
            ->assertJsonPath('data.0.payment_status', 'paid');

        $this->getJson("/api/participant/my-schedules/{$schedule->id}", $headers)
            ->assertOk()
            ->assertJsonPath('participant_status_label', 'Telah melakukan pembayaran')
            ->assertJsonPath('payment_status', 'paid');
    }

    public function test_attempt_is_submitted_with_violation_status(): void
    {
        $this->seed(DatabaseSeeder::class);
        $participant = User::where('email', 'peserta@tpka.com')->firstOrFail();
        $schedule = $this->createExamFixture($participant);

        $login = $this->postJson('/api/auth/login', ['email' => 'peserta@tpka.com', 'password' => 'password'])
            ->assertOk();
        $headers = ['Authorization' => 'Bearer '.$login->json('token')];

        $attemptId = $this->postJson("/api/participant/schedules/{$schedule->id}/attempts", [], $headers)
            ->assertSuccessful()
            ->json('id');

        $this->postJson("/api/participant/attempts/{$attemptId}/violation", [
            'reason' => 'tab_hidden',
            'context' => ['event' => 'visibilitychange'],
        ], $headers)
            ->assertOk()
            ->assertJsonPath('status', 'submitted');

        $this->assertDatabaseHas('exam_attempts', [
            'id' => $attemptId,
            'status' => 'submitted',
        ]);

        $this->assertSame(
            'violation',
            ExamAttempt::findOrFail($attemptId)->metadata['termination_reason'] ?? null
        );

        $this->postJson("/api/participant/schedules/{$schedule->id}/attempts", [], $headers)
            ->assertStatus(422)
            ->assertJsonPath('errors.attempt.0', 'Ujian ini sudah berakhir. Silakan hubungi admin jika membutuhkan ujian ulang.');
    }

    public function test_admin_can_grant_retake_and_participant_gets_fresh_timer(): void
    {
        try {
            Carbon::setTestNow('2026-06-29 08:10:00');
            $this->seed(DatabaseSeeder::class);
            $participant = User::where('email', 'peserta@tpka.com')->firstOrFail();
            $admin = User::where('role', 'administrator')->firstOrFail();
            $schedule = $this->createExamFixture($participant);

            $firstAttemptId = $this->actingAs($participant, 'sanctum')
                ->postJson("/api/participant/schedules/{$schedule->id}/attempts")
                ->assertSuccessful()
                ->json('id');

            $this->actingAs($admin, 'sanctum')
                ->postJson("/api/schedules/{$schedule->id}/participants/{$participant->id}/retake")
                ->assertOk()
                ->assertJsonPath('message', 'Peserta diizinkan mengulang ujian.');

            Carbon::setTestNow('2026-06-29 11:45:00');

            $retake = $this->actingAs($participant, 'sanctum')
                ->postJson("/api/participant/schedules/{$schedule->id}/attempts")
                ->assertSuccessful();

            $retakeAttemptId = $retake->json('id');

            $this->assertNotSame($firstAttemptId, $retakeAttemptId);
            $this->assertSame(now()->toISOString(), $retake->json('started_at'));
            $this->assertSame(now()->copy()->addMinutes(30)->toISOString(), $retake->json('expires_at'));
            $this->assertSame('retake', ExamAttempt::findOrFail($retakeAttemptId)->metadata['mode'] ?? null);
            $this->assertDatabaseHas('exam_registrations', [
                'user_id' => $participant->id,
                'schedule_id' => $schedule->id,
            ]);
            $this->assertSame(
                'cancelled',
                ExamAttempt::findOrFail($firstAttemptId)->status
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_participant_can_start_retake_after_original_schedule_end(): void
    {
        try {
            Carbon::setTestNow('2026-06-29 08:05:00');
            $this->seed(DatabaseSeeder::class);
            $participant = User::where('email', 'peserta@tpka.com')->firstOrFail();
            $admin = User::where('role', 'administrator')->firstOrFail();
            $schedule = $this->createExamFixture($participant);

            $this->actingAs($participant, 'sanctum')
                ->postJson("/api/participant/schedules/{$schedule->id}/attempts")
                ->assertSuccessful();

            Carbon::setTestNow('2026-06-29 09:45:00');

            $this->actingAs($admin, 'sanctum')
                ->postJson("/api/schedules/{$schedule->id}/participants/{$participant->id}/retake")
                ->assertOk();

            $detail = $this->actingAs($participant, 'sanctum')
                ->getJson("/api/participant/my-schedules/{$schedule->id}")
                ->assertOk()
                ->assertJsonPath('can_start', true);

            $retake = $this->actingAs($participant, 'sanctum')
                ->postJson("/api/participant/schedules/{$schedule->id}/attempts")
                ->assertSuccessful()
                ->assertJsonPath('status', 'in_progress');

            $this->assertSame(now()->toISOString(), $retake->json('started_at'));
            $this->assertSame(now()->copy()->addMinutes(30)->toISOString(), $retake->json('expires_at'));
            $this->assertSame($detail->json('attempt_id'), null);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_participant_scores_only_show_latest_completed_attempt_per_schedule(): void
    {
        $this->seed(DatabaseSeeder::class);
        $participant = User::where('email', 'peserta@tpka.com')->firstOrFail();
        $admin = User::where('role', 'administrator')->firstOrFail();
        $schedule = $this->createExamFixture($participant);

        $login = $this->postJson('/api/auth/login', ['email' => 'peserta@tpka.com', 'password' => 'password'])
            ->assertOk();
        $headers = ['Authorization' => 'Bearer '.$login->json('token')];

        $firstAttemptId = $this->postJson("/api/participant/schedules/{$schedule->id}/attempts", [], $headers)
            ->assertSuccessful()
            ->json('id');

        $this->postJson("/api/participant/attempts/{$firstAttemptId}/violation", [
            'reason' => 'window_blur',
            'context' => ['event' => 'blur'],
        ], $headers)->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/schedules/{$schedule->id}/participants/{$participant->id}/retake")
            ->assertOk();

        $this->actingAs($participant, 'sanctum');
        $secondAttempt = $this->postJson("/api/participant/schedules/{$schedule->id}/attempts", [], $headers)
            ->assertSuccessful();
        $secondAttemptId = $secondAttempt->json('id');
        $questionId = $secondAttempt->json('questions.0.id');
        $correctOptionId = QuestionOption::where('question_id', $questionId)
            ->where('is_correct', true)
            ->value('id');

        $this->putJson("/api/participant/attempts/{$secondAttemptId}/answers/{$questionId}", [
            'question_option_id' => $correctOptionId,
        ], $headers)->assertOk();

        $this->postJson("/api/participant/attempts/{$secondAttemptId}/submit", [], $headers)
            ->assertOk();

        $scores = $this->getJson('/api/participant/scores', $headers)
            ->assertOk();

        $this->assertCount(1, $scores->json('data'));
        $this->assertSame($secondAttemptId, $scores->json('data.0.id'));
    }

    private function createExamFixture(User $participant): Schedule
    {
        $admin = User::where('role', 'administrator')->firstOrFail();
        $this->createCertificateTemplate();
        $package = QuestionPackage::create([
            'name' => 'Paket TPA Test',
            'description' => 'Fixture internal untuk test exam engine.',
            'status' => 'published',
            'total_duration_minutes' => 30,
            'total_questions' => 2,
            'base_score' => 200,
            'correct_answer_score' => 2,
            'created_by' => $admin->id,
        ]);
        $section = ExamSection::create([
            'question_package_id' => $package->id,
            'name' => 'Penalaran Umum',
            'code' => 'verbal',
            'description' => 'Fixture subtes',
            'order_number' => 1,
            'duration_minutes' => 30,
            'question_count' => 2,
        ]);

        $questions = [
            ['text' => 'Jika semua A adalah B dan semua B adalah C, maka semua A adalah ...', 'correct' => 'C', 'options' => ['A', 'B', 'C', 'D']],
            ['text' => 'Deret berikutnya dari 2, 4, 8, 16 adalah ...', 'correct' => '32', 'options' => ['18', '24', '30', '32']],
        ];
        foreach ($questions as $i => $item) {
            $question = Question::create([
                'question_package_id' => $package->id,
                'exam_section_id' => $section->id,
                'type' => 'single_choice',
                'question_text' => $item['text'],
                'score' => 1,
                'order_number' => $i + 1,
            ]);
            foreach ($item['options'] as $j => $option) {
                $question->options()->create([
                    'option_label' => chr(65 + $j),
                    'option_text' => $option,
                    'is_correct' => $option === $item['correct'],
                    'order_number' => $j + 1,
                ]);
            }
        }

        $schedule = Schedule::create([
            'question_package_id' => $package->id,
            'title' => 'TPA Test Aktif',
            'description' => 'Fixture jadwal untuk test exam player.',
            'start_at' => now()->subMinutes(5),
            'end_at' => now()->addMinutes(25),
            'registration_start_at' => now()->subDay(),
            'registration_end_at' => now()->addHours(12),
            'price' => 0,
            'quota' => 100,
            'status' => 'published',
            'created_by' => $admin->id,
        ]);
        ExamRegistration::create([
            'user_id' => $participant->id,
            'schedule_id' => $schedule->id,
            'question_package_id' => $package->id,
            'status' => 'approved',
            'access_status' => 'unlocked',
            'registered_at' => now(),
        ]);

        return $schedule;
    }

    private function createCertificateTemplate(): Certificate
    {
        $path = 'certificates/test-template.png';
        Storage::disk('public')->put($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAB4AAAAV4CAIAAAA4xSjbAAAAGXRFWHRTb2Z0d2FyZQBwYWludC5uZXQgNC4wLjEyQm9YSQAAABNJREFUeJztwTEBAAAAwqD1T20ND6AAAAAAAAAAAAAAAPg1fgABtQAB2gAAAABJRU5ErkJggg=='));

        return Certificate::firstOrCreate(
            ['name' => 'Template Test'],
            [
                'period' => 'I',
                'year' => 2026,
                'sign_name' => 'Direktur',
                'sign_nip' => '123',
                'letter_prefix' => 'TPA',
                'template_file' => $path,
                'layout_json' => [
                    'canvas' => ['width' => 1200, 'height' => 850],
                    'fields' => [
                        'participant_name' => ['x' => 100, 'y' => 100, 'fontSize' => 32, 'align' => 'left'],
                        'certificate_no' => ['x' => 100, 'y' => 150, 'fontSize' => 20, 'align' => 'left'],
                        'score_total' => ['x' => 100, 'y' => 200, 'fontSize' => 20, 'align' => 'left'],
                        'qr_code' => ['x' => 900, 'y' => 600, 'size' => 160],
                    ],
                ],
                'is_active' => true,
            ]
        );
    }
}
