<?php

declare(strict_types=1);

namespace Martis\Profile;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Martis\Support\TranslatedLine;

/**
 * The mail to the NEW address of an email change, with the link that
 * confirms it (EmailChange). Extend it, and bind your subclass to
 * `EmailChangeConfirmationNotification`, to change the wording.
 */
class EmailChangeConfirmationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $url,
        public int $ttlMinutes,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(TranslatedLine::get('martis::profile.email_change_confirm_subject', ['app' => (string) config('app.name', 'Martis')]))
            ->greeting(TranslatedLine::get('martis::profile.email_change_greeting'))
            ->line(TranslatedLine::get('martis::profile.email_change_confirm_intro', ['minutes' => $this->ttlMinutes]))
            ->action(TranslatedLine::get('martis::profile.email_change_confirm_cta'), $this->url)
            ->line(TranslatedLine::get('martis::profile.email_change_confirm_outro'));
    }
}
