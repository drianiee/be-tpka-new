<?php

namespace App\Mail;

use App\Models\Schedule;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PartnerProfileInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $name, public Schedule $schedule, public string $onboardingUrl) {}

    public function build(): self
    {
        return $this->subject('Konfirmasi Peserta Ujian TPKA')
            ->view('emails.partner.profile-invitation');
    }
}
