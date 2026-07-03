<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveAnswerRequest;
use App\Http\Resources\ExamAttemptResource;
use App\Models\AttemptEvent;
use App\Models\ExamAttempt;
use App\Models\Schedule;
use App\Services\ExamAttemptService;
use Illuminate\Http\Request;

class ExamAttemptController extends Controller
{
    public function __construct(private ExamAttemptService $service) {}

    public function start(Request $request, Schedule $schedule)
    {
        return new ExamAttemptResource($this->service->start($request->user(), $schedule));
    }

    public function show(Request $request, ExamAttempt $attempt)
    {
        $this->owner($request, $attempt);
        if ($attempt->status === 'in_progress' && now()->greaterThanOrEqualTo($attempt->expires_at)) {
            $attempt = $this->service->submit($attempt, true);
        }

        return new ExamAttemptResource($attempt);
    }

    public function answer(SaveAnswerRequest $request, ExamAttempt $attempt, int $questionId)
    {
        $this->owner($request, $attempt);

        return ['message' => 'Jawaban tersimpan.', 'answer' => $this->service->saveAnswer($attempt, $questionId, $request->validated())];
    }

    public function batch(Request $request, ExamAttempt $attempt)
    {
        $this->owner($request, $attempt);
        $data = $request->validate(['answers' => ['required', 'array', 'max:100'], 'answers.*.question_id' => ['required', 'integer'], 'answers.*.question_option_id' => ['nullable', 'integer'], 'answers.*.answer_text' => ['nullable', 'string'], 'answers.*.is_flagged' => ['sometimes', 'boolean']]);
        foreach ($data['answers'] as $answer) {
            $questionId = $answer['question_id'];
            unset($answer['question_id']);
            $this->service->saveAnswer($attempt, $questionId, $answer);
        }

        return ['message' => 'Jawaban tersimpan.'];
    }

    public function heartbeat(Request $request, ExamAttempt $attempt)
    {
        $this->owner($request, $attempt);
        AttemptEvent::create(['exam_attempt_id' => $attempt->id, 'type' => 'heartbeat', 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);

        return ['server_time' => now()->toISOString(), 'expires_at' => $attempt->expires_at->toISOString(), 'status' => $attempt->status];
    }

    public function submit(Request $request, ExamAttempt $attempt)
    {
        $this->owner($request, $attempt);

        return $this->resultData($this->service->submit($attempt));
    }

    public function violation(Request $request, ExamAttempt $attempt)
    {
        $this->owner($request, $attempt);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:100'],
            'context' => ['nullable', 'array'],
        ]);

        return $this->resultData($this->service->terminateForViolation(
            $attempt,
            $data['reason'],
            $data['context'] ?? []
        ));
    }

    public function result(Request $request, ExamAttempt $attempt)
    {
        $this->owner($request, $attempt);
        abort_if($attempt->status === 'in_progress', 409, 'Attempt belum disubmit.');

        return $this->resultData($attempt->load(['schedule', 'package', 'scores']));
    }

    private function owner(Request $request, ExamAttempt $attempt): void
    {
        abort_unless($attempt->user_id === $request->user()->id, 403);
    }

    private function resultData(ExamAttempt $a): array
    {
        $a->loadMissing(['schedule', 'package', 'scores']);

        return [
            'id' => $a->id,
            'status' => $a->status,
            'submitted_at' => $a->submitted_at?->toISOString(),
            'score_total' => (float) $a->score_total,
            'score_breakdown' => $a->score_breakdown,
            'scores' => $a->scores,
            'schedule' => ['id' => $a->schedule->id, 'title' => $a->schedule->title],
            'question_package' => ['id' => $a->package->id, 'name' => $a->package->name],
        ];
    }
}
