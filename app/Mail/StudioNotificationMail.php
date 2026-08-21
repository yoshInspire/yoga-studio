<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * Универсальное письмо-уведомление студии (отмена занятия, окончание абонемента и т.п.).
 *
 * `$unsubscribeUrl` заполняется только у информационных рассылок. У писем про
 * собственную запись клиента, отмену занятия или код входа его нет и быть не
 * должно: предлагать отписаться от того, что всё равно придёт, — обман.
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
        public ?string $unsubscribeUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: ($this->subjectLine ?? $this->heading).' · ЭКО YOGA',
        );
    }

    /**
     * Кнопка «Отписаться» в самой почте (RFC 8058).
     *
     * Ради неё всё и затевалось: Яндекс, Mail.ru и Gmail показывают её рядом с
     * письмом, и человек, которому надоела рассылка, нажимает её вместо
     * «Спам». Для репутации отправителя это разница между «от нас отписались»
     * и «на нас пожаловались».
     */
    public function headers(): Headers
    {
        if ($this->unsubscribeUrl === null) {
            return new Headers;
        }

        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->unsubscribeUrl.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.studio-notification',
            with: [
                'heading' => $this->heading,
                'lines' => $this->lines,
                'footnote' => $this->footnote,
                'unsubscribeUrl' => $this->unsubscribeUrl,
            ],
        );
    }
}
