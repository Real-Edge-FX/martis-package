<?php

declare(strict_types=1);

namespace Martis\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notification that delivers the magic-link email. Consumers can
 * extend it to swap subject / body / mail driver, then bind their
 * subclass via `MagicLinkController::$notification = MyMail::class`
 * (or by re-binding the controller in the service container).
 */
class MagicLinkNotification extends Notification
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
            ->subject(__('martis::auth.magic_link_subject', [
                'app' => (string) config('app.name', 'Martis'),
            ]))
            ->greeting(__('martis::auth.magic_link_greeting'))
            ->line(__('martis::auth.magic_link_intro', [
                'minutes' => $this->ttlMinutes,
            ]))
            ->action(__('martis::auth.magic_link_cta'), $this->url)
            ->line(__('martis::auth.magic_link_outro'));
    }
}
