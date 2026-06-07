<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LeadRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $leadName,
        public string $leadPhone,
        public ?string $leadMessage = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Новая заявка с сайта · '.$this->leadName,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.lead',
        );
    }
}
