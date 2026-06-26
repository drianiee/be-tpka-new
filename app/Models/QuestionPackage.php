<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuestionPackage extends Model
{
    protected $fillable = ['name', 'status', 'total_duration_minutes', 'total_questions', 'target_total_questions', 'base_score', 'correct_answer_score', 'created_by'];

    protected function casts(): array
    {
        return [
            'base_score' => 'integer',
            'correct_answer_score' => 'integer',
        ];
    }

    public function sections()
    {
        return $this->hasMany(ExamSection::class)->orderBy('order_number');
    }

    public function questions()
    {
        return $this->hasMany(Question::class)->orderBy('order_number');
    }
}
