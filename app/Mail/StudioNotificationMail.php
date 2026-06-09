<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Универсальное письмо-уведомление студии (отмена занятия, окончание абонемента и т.п.).
 *
 * @param  list<string>  $lines
 */
class StudioNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public string $heading,
        public array $lines,
        public ?string $subjectLine = null,
        public ?string $footnote = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: ($this->subjectLine ?? $this->heading).' · ЭКО YOGA',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.studio-notification',
            with: [
                'heading' => $this->heading,
                'lines' => $this->lines,
                'footnote' => $this->footnote,
            ],
        );
    }
}
