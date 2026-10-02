<?php

declare(strict_types=1);

namespace Martis\Profile;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Martis\Support\TranslatedLine;

/**
 * The mail to the OLD address when a change of the email address is asked
 * for (EmailChange): nothing has changed yet, and whoever reads it can tell
 * the request was not theirs.
 */
class EmailChangeRequestedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $newEmail,
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
            ->subject(TranslatedLine::get('martis::profile.email_change_requested_subject', ['app' => (string) config('app.name', 'Martis')]))
            ->greeting(TranslatedLine::get('martis::profile.email_change_greeting'))
            ->line(TranslatedLine::get('martis::profile.email_change_requested_intro', ['email' => $this->newEmail, 'minutes' => $this->ttlMinutes]))
            ->line(TranslatedLine::get('martis::profile.email_change_requested_outro'));
    }
}
