<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExamAttemptResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['schedule', 'package.sections', 'attemptQuestions', 'answers']);

        return [
            'id' => $this->id,
            'status' => $this->status,
            'server_time' => now()->toISOString(),
            'started_at' => $this->started_at->toISOString(),
            'expires_at' => $this->expires_at->toISOString(),
            'paused_ms' => (int) ($this->metadata['paused_ms'] ?? 0),
            'resume_count' => (int) ($this->metadata['resume_count'] ?? 0),
            'schedule' => ['id' => $this->schedule->id, 'title' => $this->schedule->title, 'start_at' => $this->schedule->start_at->toISOString(), 'end_at' => $this->schedule->end_at->toISOString()],
            'question_package' => ['id' => $this->package->id, 'name' => $this->package->name, 'total_duration_minutes' => $this->package->total_duration_minutes, 'total_questions' => $this->package->total_questions],
            'sections' => $this->package->sections->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'description' => $s->description, 'order_number' => $s->order_number, 'duration_minutes' => $s->duration_minutes, 'question_count' => $s->question_count])->values(),
            'questions' => $this->attemptQuestions->map(fn ($q) => ['id' => $q->question_id, 'section_id' => $q->exam_section_id, 'order_number' => $q->order_number, 'question_text' => $q->snapshot_question_text, 'question_image' => $q->snapshot_question_image, 'options' => $this->normalizedOptions($q->snapshot_options)])->values(),
            'answers' => $this->answers->map(fn ($a) => ['question_id' => $a->question_id, 'question_option_id' => $a->question_option_id, 'answer_text' => $a->answer_text, 'is_flagged' => $a->is_flagged, 'answered_at' => $a->answered_at?->toISOString()])->values(),
        ];
    }

    private function normalizedOptions(array $options): array
    {
        $labels = ['A', 'B', 'C', 'D'];

        return collect($options)
            ->values()
            ->map(fn (array $option, int $index) => [
                'id' => $option['id'] ?? null,
                'option_label' => $labels[$index] ?? chr(65 + $index),
                'option_text' => $option['option_text'] ?? null,
                'option_image' => $option['option_image'] ?? null,
            ])
            ->all();
    }
}
