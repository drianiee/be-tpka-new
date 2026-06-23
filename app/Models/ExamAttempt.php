<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamAttempt extends Model
{
    protected $fillable = ['user_id', 'schedule_id', 'question_package_id', 'status', 'started_at', 'expires_at', 'submitted_at', 'score_total', 'score_breakdown', 'metadata'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'expires_at' => 'datetime', 'submitted_at' => 'datetime', 'score_total' => 'decimal:2', 'score_breakdown' => 'array', 'metadata' => 'array'];
    }

    public function schedule()
    {
        return $this->belongsTo(Schedule::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function package()
    {
        return $this->belongsTo(QuestionPackage::class, 'question_package_id');
    }

    public function attemptQuestions()
    {
        return $this->hasMany(AttemptQuestion::class)->orderBy('order_number');
    }

    public function answers()
    {
        return $this->hasMany(AttemptAnswer::class);
    }

    public function scores()
    {
        return $this->hasMany(ExamScore::class);
    }
}
