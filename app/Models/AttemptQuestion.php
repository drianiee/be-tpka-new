<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttemptQuestion extends Model
{
    protected $fillable = ['exam_attempt_id', 'question_id', 'exam_section_id', 'order_number', 'snapshot_question_text', 'snapshot_question_image', 'snapshot_options'];

    protected function casts(): array
    {
        return ['snapshot_options' => 'array'];
    }
}
