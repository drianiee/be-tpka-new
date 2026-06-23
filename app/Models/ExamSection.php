<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamSection extends Model
{
    protected $fillable = ['question_package_id', 'name', 'code', 'description', 'order_number', 'duration_minutes', 'question_count', 'target_question_count'];

    public function package()
    {
        return $this->belongsTo(QuestionPackage::class, 'question_package_id');
    }

    public function questions()
    {
        return $this->hasMany(Question::class)->orderBy('order_number');
    }
}
