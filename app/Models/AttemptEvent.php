<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttemptEvent extends Model
{
    protected $fillable = ['exam_attempt_id', 'type', 'payload', 'ip_address', 'user_agent'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
