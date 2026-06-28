<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Schedule extends Model
{
    protected $fillable = ['question_package_id', 'partner_id', 'title', 'description', 'start_at', 'end_at', 'registration_start_at', 'registration_end_at', 'price', 'quota', 'status', 'is_partner', 'created_by'];

    protected function casts(): array
    {
        return ['start_at' => 'datetime', 'end_at' => 'datetime', 'registration_start_at' => 'datetime', 'registration_end_at' => 'datetime', 'price' => 'decimal:2', 'is_partner' => 'boolean'];
    }

    public function package()
    {
        return $this->belongsTo(QuestionPackage::class, 'question_package_id');
    }

    public function publishedPackages()
    {
        return QuestionPackage::query()
            ->where('status', 'published')
            ->whereHas('questions')
            ->orderBy('name');
    }

    public function registrations()
    {
        return $this->hasMany(ExamRegistration::class);
    }

    public function attempts()
    {
        return $this->hasMany(ExamAttempt::class);
    }

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }
}
