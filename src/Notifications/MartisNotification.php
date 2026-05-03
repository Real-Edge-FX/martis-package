<?php

namespace Martis\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Base class for in-app notifications surfaced in the Martis bell
 * dropdown. Provides a fluent constructor and standardises the data
 * shape so the React renderer can resolve title, message, severity,
 * icon and an optional action link without per-notification glue.
 *
 * Apps that already use Laravel notifications can keep their existing
 * classes — Martis renders any database-channel notification, falling
 * back to the class name when `title` is missing. This class exists to
 * make the common case (one-off notifications without a dedicated
 * class) a one-liner:
 *
 * ```php
 * $user->notify(MartisNotification::make(
 *     title: 'Invoice paid',
 *     message: 'INV-2026-001 has been paid.',
 *     level: NotificationLevel::Success,
 *     icon: 'check-circle',
 *     actionUrl: route('martis.spa').'/resources/invoices/42',
 *     actionLabel: 'View invoice',
 * ));
 * ```
 */
class MartisNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $title,
        public readonly string $message = '',
        public readonly string $level = 'info',
        public readonly ?string $icon = null,
        public readonly ?string $actionUrl = null,
        public readonly ?string $actionLabel = null,
    ) {}

    /**
     * Fluent factory mirroring the constructor — preferred by docs and
     * stubs because named arguments make the call site self-explanatory.
     *
     * Only `title` is required; every other field has a sensible default
     * so a one-liner like `MartisNotification::make(title: 'Saved')` is
     * a valid call.
     */
    public static function make(
        string $title,
        string $message = '',
        string $level = 'info',
        ?string $icon = null,
        ?string $actionUrl = null,
        ?string $actionLabel = null,
    ): static {
        return new static($title, $message, $level, $icon, $actionUrl, $actionLabel);
    }

    /**
     * Channels Laravel delivers this notification through. Database is
     * the only one Martis cares about; subclasses may add `mail`,
     * `slack`, `broadcast`, etc. without affecting the bell dropdown.
     *
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return ['database'];
    }

    /**
     * Database channel payload. Keys are stable — the React renderer
     * (`NotificationDropdown.tsx`) reads them directly.
     *
     * @return array<string, mixed>
     */
    public function toArray(mixed $notifiable): array
    {
        return [
            'title' => $this->title,
            'message' => $this->message,
            'level' => $this->level,
            'icon' => $this->icon,
            'action_url' => $this->actionUrl,
            'action_label' => $this->actionLabel,
        ];
    }

    /**
     * Optional broadcast payload for apps that wire Laravel's broadcast
     * channel on top of the database channel (e.g. Pusher / Reverb for
     * real-time bell badge updates without polling). Disabled by
     * default — apps add `'broadcast'` to {@see via()} to enable.
     */
    public function toBroadcast(mixed $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
