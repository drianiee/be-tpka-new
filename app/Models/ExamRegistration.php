<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamRegistration extends Model
{
    protected $fillable = [
        'user_id',
        'schedule_id',
        'question_package_id',
        'payment_id',
        'certificate_id',
        'status',
        'access_status',
        'registration_source',
        'registered_at',
        'retake_granted_at',
        'retake_consumed_at',
        'certificate_no',
        'certificate_verify_uuid',
        'certificate_verify_hash',
        'certificate_issued_at',
        'certificate_file_path',
        'certificate_revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'registered_at' => 'datetime',
            'retake_granted_at' => 'datetime',
            'retake_consumed_at' => 'datetime',
            'certificate_issued_at' => 'datetime',
            'certificate_revoked_at' => 'datetime',
        ];
    }

    public function schedule()
    {
        return $this->belongsTo(Schedule::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function certificate()
    {
        return $this->belongsTo(Certificate::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function package()
    {
        return $this->belongsTo(QuestionPackage::class, 'question_package_id');
    }
}
