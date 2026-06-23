<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamRegistration extends Model
{
    protected $fillable = ['user_id', 'schedule_id', 'payment_id', 'status', 'access_status', 'registered_at'];

    protected function casts(): array
    {
        return ['registered_at' => 'datetime'];
    }

    public function schedule()
    {
        return $this->belongsTo(Schedule::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
