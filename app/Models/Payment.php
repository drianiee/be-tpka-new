<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'user_id',
        'schedule_id',
        'exam_registration_id',
        'order_id',
        'transaction_id',
        'snap_token',
        'snap_redirect_url',
        'amount',
        'transaction_status',
        'payment_type',
        'fraud_status',
        'status_code',
        'status_message',
        'bank',
        'va_number',
        'va_numbers',
        'transaction_time',
        'settlement_time',
        'payload',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'va_numbers' => 'array',
            'payload' => 'array',
            'transaction_time' => 'datetime',
            'settlement_time' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function schedule()
    {
        return $this->belongsTo(Schedule::class);
    }

    public function registration()
    {
        return $this->belongsTo(ExamRegistration::class, 'exam_registration_id');
    }
}
