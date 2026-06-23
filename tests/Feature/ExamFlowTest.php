<?php

namespace Tests\Feature;

use App\Models\ExamAttempt;
use App\Models\ExamRegistration;
use App\Models\ExamSection;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\QuestionPackage;
use App\Models\Schedule;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->getJson("/api/participant/attempts/{$attemptId}/result", $headers)
            ->assertOk()
            ->assertJsonPath('status', 'submitted');

        $scores = $this->getJson('/api/participant/scores', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.certificate.certificate_no', "TPA-{$schedule->start_at->year}-".str_pad((string) $attemptId, 6, '0', STR_PAD_LEFT));

        $this->getJson('/api/certificates/data/'.$scores->json('data.0.certificate.certificate_no'))
            ->assertOk()
            ->assertJsonPath('data.participant_email', 'peserta@tpka.com');

        $this->assertSame($first->json('score_total'), $second->json('score_total'));
        $this->assertDatabaseCount('exam_attempts', 1);
        $this->assertDatabaseCount('exam_scores', 1);
        $this->assertSame(1, ExamAttempt::firstOrFail()->answers()->count());
    }

    public function test_participant_cannot_start_unregistered_schedule(): void
    {
        $this->seed(DatabaseSeeder::class);
        $participant = User::where('email', 'peserta@tpka.com')->firstOrFail();
        $schedule = $this->createExamFixture($participant);
        $schedule->registrations()->where('user_id', $participant->id)->delete();
        $this->actingAs($participant, 'sanctum')->postJson("/api/participant/schedules/{$schedule->id}/attempts")
            ->assertUnprocessable()->assertJsonValidationErrors('schedule');
    }

    private function createExamFixture(User $participant): Schedule
    {
        $admin = User::where('role', 'administrator')->firstOrFail();
        $package = QuestionPackage::create([
            'name' => 'Paket TPA Test',
            'description' => 'Fixture internal untuk test exam engine.',
            'status' => 'published',
            'total_duration_minutes' => 30,
            'total_questions' => 2,
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
                'is_active' => true,
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
            'start_at' => now()->subHour(),
            'end_at' => now()->addDay(),
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
            'status' => 'approved',
            'access_status' => 'unlocked',
            'registered_at' => now(),
        ]);

        return $schedule;
    }
}
