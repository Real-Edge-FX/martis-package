# In-app Notifications (⭐ Martis differential)

> Persistent notifications surfaced in the topbar bell dropdown — distinct from toasts (transient feedback). Backed by Laravel's standard `notifications` table.

## What it is, what it isn't

- **Toasts** (`useToast`) — transient, auto-dismiss, reactive feedback for the action a user just took ("Record saved", "Failed to send"). They're gone when the page reloads.
- **Notifications** — persistent, per-user, bell + badge. The user opens the dropdown, reads them, marks as read, clicks through to a link, and they survive page reloads / sessions.

Pre-v0.8 Martis only had toasts. v0.8 ships the proper notifications subsystem.

## How it works

The system is a thin UI layer over Laravel's standard notifications table. Anything that delivers via the `database` channel automatically surfaces in the Martis bell. This means:

- Existing Laravel `Notification` classes work without changes.
- Queue + broadcast + mail channels keep working alongside.
- The data shape is just the JSON payload your notification's `toArray()` returns.

The Martis convention is a small set of recommended keys:

```json
{
  "title": "Invoice paid",
  "message": "INV-2026-001 has been paid.",
  "level": "success",            // info | success | warning | danger
  "icon": "check-circle",        // optional Phosphor icon name
  "action_url": "/martis/...",   // optional click target
  "action_label": "View invoice" // optional CTA label
}
```

`title` is the only one Martis truly needs — the others either default sensibly or are omitted from the rendering.

## Sending a notification

The `MartisNotification` class is a one-liner sender for the common case:

```php
use Martis\Notifications\MartisNotification;

$user->notify(MartisNotification::make(
    title: 'Invoice paid',
    message: 'INV-2026-001 has been paid.',
    level: 'success',
    icon: 'check-circle',
    actionUrl: '/martis/resources/invoices/42',
    actionLabel: 'View invoice',
));
```

For richer notifications (queueable, multi-channel, mail templates) write your own `Notification` class as usual:

```php
use Illuminate\Notifications\Notification;

class InvoicePaid extends Notification
{
    public function __construct(public Invoice $invoice) {}

    public function via(): array
    {
        return ['mail', 'database'];
    }

    public function toMail(): MailMessage { /* ... */ }

    public function toArray(): array
    {
        return [
            'title' => 'Invoice paid',
            'message' => "{$this->invoice->code} has been paid.",
            'level' => 'success',
            'icon' => 'check-circle',
            'action_url' => "/martis/resources/invoices/{$this->invoice->id}",
            'action_label' => 'View invoice',
        ];
    }
}
```

## Configuration

```php
// config/martis.php
'notifications' => [
    'enabled' => env('MARTIS_NOTIFICATIONS_ENABLED', true),
    'poll_interval' => env('MARTIS_NOTIFICATIONS_POLL_INTERVAL', 60000),
    'max_in_dropdown' => env('MARTIS_NOTIFICATIONS_MAX_DROPDOWN', 10),
],
```

| Key | Effect |
|-----|--------|
| `enabled` | Master switch. When false, the bell never renders and the API returns empty payloads. |
| `poll_interval` | How often the bell badge polls `/api/notifications/unread-count` (ms). Set to `0` to disable polling — refresh manually via React Query / broadcast events. |
| `max_in_dropdown` | Maximum entries shown in the dropdown. Older entries live behind a future "View all" link. Capped at 50 server-side. |

## REST API

All endpoints live under `/{martis-path}/api/notifications`, scope to the authenticated user, and silently no-op when there's no user (keeps polling cheap on the login screen).

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/notifications` | Paginated list. Query: `per_page` (max 50), `unread_only` (bool). |
| `GET` | `/notifications/unread-count` | Cheap COUNT query for the badge. |
| `POST` | `/notifications/{id}/read` | Mark single as read. |
| `POST` | `/notifications/read-all` | Mark every unread as read. |
| `DELETE` | `/notifications/{id}` | Delete single. |
| `DELETE` | `/notifications` | Clear all. |

## Real-time delivery

Polling defaults to 60s — fine for most apps. For instant updates (Slack-like), add the `broadcast` channel to your notification's `via()` and listen for the event on the React side. The `MartisNotification` base ships a default `toBroadcast()` payload so this works without extra work:

```php
class MyNotification extends MartisNotification
{
    public function via(mixed $notifiable): array
    {
        return ['database', 'broadcast'];
    }
}
```

## Migration

The `martis:install --force` command publishes a migration that creates the standard Laravel `notifications` table (idempotent — skipped when the table already exists). Apps that already ran `php artisan notifications:table` are compatible with no migration needed.

## Tests

- 9 feature tests in `tests/Feature/NotificationControllerTest.php` covering the six endpoints, the data shape, ownership scoping, and the disabled-config case.
