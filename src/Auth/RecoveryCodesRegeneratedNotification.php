<?php

declare(strict_types=1);

namespace Martis\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Martis\Support\TranslatedLine;

/**
 * The email that tells a user their two-factor recovery codes were
 * regenerated. The old codes stopped working and a new set exists: an owner
 * who did not do it learns that someone else used their session. Extend it
 * to change the wording or the mail driver.
 */
class RecoveryCodesRegeneratedNotification extends Notification
{
    use Queueable;

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
            ->subject(TranslatedLine::get('martis::profile.2fa_regen_mail_subject', [
                'app' => (string) config('app.name', 'Martis'),
            ]))
            ->greeting(TranslatedLine::get('martis::profile.2fa_regen_mail_greeting'))
            ->line(TranslatedLine::get('martis::profile.2fa_regen_mail_intro', [
                'app' => (string) config('app.name', 'Martis'),
            ]))
            ->line(TranslatedLine::get('martis::profile.2fa_regen_mail_outro'));
    }
}
