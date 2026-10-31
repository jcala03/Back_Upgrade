<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class CommercialDocumentMail extends Mailable
{
    public function __construct(public array $document, private string $pdf) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('commercial_documents.from_address'), config('commercial_documents.from_name')),
            subject: $this->document['title'].' '.$this->document['code'].' | Upgrade La 79',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.documents.commercial');
    }

    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->pdf, $this->document['filename'])->withMime('application/pdf')];
    }
}
