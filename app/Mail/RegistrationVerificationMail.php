<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RegistrationVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $code,
        public int $ttlMinutes,
        public string $context = 'registration',
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->context === 'profile'
            ? 'Код подтверждения email · ЭКО YOGA'
            : 'Код подтверждения регистрации · ЭКО YOGA';

        return new Envelope(
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.registration-verification',
            with: [
                'context' => $this->context,
            ],
        );
    }
}
