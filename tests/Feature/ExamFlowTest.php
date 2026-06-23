<?php

namespace Tests\Feature;

use App\Models\ExamAttempt;
use App\Models\QuestionOption;
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
        $login = $this->postJson('/api/auth/login', ['email' => 'peserta@example.com', 'password' => 'password'])
            ->assertOk()->assertJsonStructure(['user', 'token']);
        $headers = ['Authorization' => 'Bearer '.$login->json('token')];
        $schedule = Schedule::where('status', 'published')->firstOrFail();

        $attemptResponse = $this->postJson("/api/participant/schedules/{$schedule->id}/attempts", [], $headers)
            ->assertSuccessful()->assertJsonPath('status', 'in_progress')->assertJsonMissing(['is_correct']);
        $attemptId = $attemptResponse->json('id');
        $questionId = $attemptResponse->json('questions.0.id');
        $correctOption = QuestionOption::where('question_id', $questionId)->where('is_correct', true)->firstOrFail();

        $this->putJson("/api/participant/attempts/{$attemptId}/answers/{$questionId}", ['question_option_id' => $correctOption->id], $headers)
            ->assertOk()->assertJsonPath('message', 'Jawaban tersimpan.');

        $first = $this->postJson("/api/participant/attempts/{$attemptId}/submit", [], $headers)
            ->assertOk()->assertJsonPath('status', 'submitted');
        $second = $this->postJson("/api/participant/attempts/{$attemptId}/submit", [], $headers)
            ->assertOk()->assertJsonPath('status', 'submitted');

        $this->assertSame($first->json('score_total'), $second->json('score_total'));
        $this->assertDatabaseCount('exam_attempts', 1);
        $this->assertDatabaseCount('exam_scores', 1);
        $this->assertSame(1, ExamAttempt::firstOrFail()->answers()->count());
    }

    public function test_participant_cannot_start_unregistered_schedule(): void
    {
        $this->seed(DatabaseSeeder::class);
        $participant = User::where('email', 'peserta@example.com')->firstOrFail();
        $schedule = Schedule::firstOrFail();
        $schedule->registrations()->where('user_id', $participant->id)->delete();
        $this->actingAs($participant, 'sanctum')->postJson("/api/participant/schedules/{$schedule->id}/attempts")
            ->assertUnprocessable()->assertJsonValidationErrors('schedule');
    }
}
