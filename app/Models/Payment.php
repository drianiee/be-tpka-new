<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = ['user_id', 'schedule_id', 'order_id', 'amount', 'transaction_status', 'payment_type', 'payload', 'paid_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'paid_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function schedule()
    {
        return $this->belongsTo(Schedule::class);
    }
}
