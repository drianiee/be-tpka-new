<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttemptAnswer extends Model
{
    protected $fillable = ['exam_attempt_id', 'question_id', 'question_option_id', 'answer_text', 'is_flagged', 'answered_at'];

    protected function casts(): array
    {
        return ['is_flagged' => 'boolean', 'answered_at' => 'datetime'];
    }
}
