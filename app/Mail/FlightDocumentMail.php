<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** PNL / passenger list / manifest sent to an e-mail address as an attachment. */
class FlightDocumentMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $documentTitle,
        public readonly string $filename,
        public readonly string $fileContent,
        public readonly string $mime,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->documentTitle);
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>'.e($this->documentTitle).' is attached.</p>');
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->fileContent, $this->filename)->withMime($this->mime)];
    }
}
