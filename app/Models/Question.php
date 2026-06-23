<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Question extends Model
{
    protected $fillable = ['question_package_id', 'exam_section_id', 'type', 'question_text', 'question_image', 'explanation', 'difficulty', 'score', 'is_active', 'order_number'];

    protected function casts(): array
    {
        return ['score' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function section()
    {
        return $this->belongsTo(ExamSection::class, 'exam_section_id');
    }

    public function package()
    {
        return $this->belongsTo(QuestionPackage::class, 'question_package_id');
    }

    public function options()
    {
        return $this->hasMany(QuestionOption::class)->orderBy('order_number');
    }
}
