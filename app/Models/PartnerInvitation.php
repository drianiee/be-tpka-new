<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerInvitation extends Model
{
    protected $fillable = [
        'schedule_id',
        'partner_id',
        'user_id',
        'name',
        'email',
        'phone',
        'identity_number',
        'token_hash',
        'expires_at',
        'claimed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'claimed_at' => 'datetime'];
    }

    public function schedule()
    {
        return $this->belongsTo(Schedule::class);
    }

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
