<?php

namespace App\Mail;

use App\Models\Schedule;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PartnerScheduleConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user, public Schedule $schedule, public string $scheduleUrl) {}

    public function build(): self
    {
        return $this->subject('Konfirmasi Jadwal Ujian TPKA')
            ->view('emails.partner.schedule-confirmation');
    }
}
