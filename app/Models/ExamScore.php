<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamScore extends Model
{
    protected $fillable = ['exam_attempt_id', 'exam_section_id', 'correct_count', 'wrong_count', 'unanswered_count', 'raw_score', 'final_score'];
}
