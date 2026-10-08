<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ETicketMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $pnr,
        public readonly string $filename,
        public readonly string $pdf,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "E-ticket {$this->pnr}");
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Your e-ticket for booking <strong>'.e($this->pnr).'</strong> is attached.</p>');
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->pdf, $this->filename)->withMime('application/pdf')];
    }
}
