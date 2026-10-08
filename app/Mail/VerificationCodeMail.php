<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerificationCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Online check-in verification code');
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Your online check-in code is <strong>'.e($this->code).'</strong>. It is valid for '
            .(int) config('flightmode.online_checkin.code_ttl_minutes').' minutes.</p>');
    }
}
