<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuestionOption extends Model
{
    protected $fillable = ['question_id', 'option_label', 'option_text', 'option_image', 'is_correct', 'order_number'];

    protected function casts(): array
    {
        return ['is_correct' => 'boolean'];
    }

    public function question()
    {
        return $this->belongsTo(Question::class);
    }
}
