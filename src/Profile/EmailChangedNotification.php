<?php

declare(strict_types=1);

namespace Martis\Profile;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Martis\Support\TranslatedLine;

/**
 * The mail to the OLD address once the change took effect (EmailChange): the
 * account no longer holds it, so this is the last mail it gets.
 */
class EmailChangedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $oldEmail,
        public string $newEmail,
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
            ->subject(TranslatedLine::get('martis::profile.email_change_applied_subject', ['app' => (string) config('app.name', 'Martis')]))
            ->greeting(TranslatedLine::get('martis::profile.email_change_greeting'))
            ->line(TranslatedLine::get('martis::profile.email_change_applied_intro', ['old' => $this->oldEmail, 'new' => $this->newEmail]))
            ->line(TranslatedLine::get('martis::profile.email_change_applied_outro'));
    }
}
