# In-app Notifications

> Persistent notifications surfaced in the topbar bell dropdown — distinct from toasts (transient feedback). Backed by Laravel's standard `notifications` table.

## What it is, what it isn't

- **Toasts** (`useToast`) — transient, auto-dismiss, reactive feedback for the action a user just took ("Record saved", "Failed to send"). They're gone when the page reloads.
- **Notifications** — persistent, per-user, bell + badge. The user opens the dropdown, reads them, marks as read, clicks through to a link, and they survive page reloads / sessions.

Pre-v0.8 Martis only had toasts. v0.8 ships the proper notifications subsystem on top of Laravel's standard `notifications` table — so anything you already deliver via the `database` channel surfaces in the bell with zero glue.

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

## Sending a notification — step by step

> Goal: deliver a notification to a user and have it show up in the bell dropdown.

### Step 1 — make sure the receiving model uses `Notifiable`

Laravel's standard `Notifiable` trait powers the whole flow. Apps that started from `laravel/laravel` already have it on `App\Models\User`:

```php
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;
    // ...
}
```

If you need to deliver to other models (workspaces, teams, on-call rotations, ...) add the trait there too. The Martis bell only renders for the **authenticated user**, but the same database table backs everyone — so a future "team inbox" surface is a configuration change, not a schema migration.

### Step 2 — pick a sender style

Two styles depending on how often you'll send the same shape.

**Style A — one-off, inline (`MartisNotification::make`)**

Ideal when the notification is built ad-hoc inside a controller / job / model event:

```php
use Martis\Notifications\MartisNotification;

$user->notify(MartisNotification::make(
    title: 'Invoice paid',
    message: 'INV-2026-001 has been paid.',
    level: 'success',                                 // info | success | warning | danger
    icon: 'check-circle',                             // any Phosphor icon name (kebab-case)
    actionUrl: '/martis/resources/invoices/42',       // click target
    actionLabel: 'View invoice',                      // CTA label rendered next to the timestamp
));
```

Only `title` is required. `message` defaults to `''`, `level` defaults to `info`, the icon falls back to a level-default (info / check-circle / warning / warning-circle), and `actionUrl` / `actionLabel` are optional.

**Style B — reusable, dedicated class**

Pick this when the same notification fires from multiple places, needs to also send mail / Slack, or is queueable:

```php
namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\Invoice;

class InvoicePaid extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Invoice $invoice) {}

    public function via(mixed $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Invoice {$this->invoice->code} paid")
            ->line("Your invoice {$this->invoice->code} has been paid.")
            ->action('View invoice', url("/martis/resources/invoices/{$this->invoice->id}"));
    }

    public function toArray(mixed $notifiable): array
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

The `database` channel reads `toArray()` — the keys above are exactly what the React renderer looks for.

### Step 3 — fire it

From any controller, job, model event, command, ...:

```php
use App\Notifications\InvoicePaid;

$invoice->payer->notify(new InvoicePaid($invoice));
```

Or send to many recipients at once:

```php
use Illuminate\Support\Facades\Notification;

Notification::send($team->members, new InvoicePaid($invoice));
```

### Step 4 — verify

Recipients see the notification inside 90 seconds (default poll interval, configurable) — the bell badge updates, then the dropdown shows the new entry on next open. To confirm during local development:

```bash
# count pending notifications for a user
php artisan tinker --execute='
$u = App\Models\User::where("email", "you@example.com")->first();
echo $u->unreadNotifications()->count(), "\n";
'
```

To deliver instantly (no polling delay), see "Real-time delivery" below — it requires standing up your own broadcaster and Echo client (Martis ships neither), or wiring the pluggable `martisEventBus` feed, which needs no broadcaster at all.

### Reference: data shape

The React renderer reads these keys from the notification's `toArray()` / `MartisNotification::toArray()`:

| Key | Required | Default | Effect |
|-----|----------|---------|--------|
| `title` | yes | class basename | Bold first line. |
| `message` | no | — | Muted second line. |
| `level` | no | `info` | Drives the icon bubble colour. One of `info` / `success` / `warning` / `danger`. |
| `icon` | no | level default | Phosphor icon name (kebab-case). Resolves through `iconRegistry` so any of the 1500+ icons works. |
| `action_url` | no | — | Click target. Path starting with `/` does an in-app navigation; full URLs open in a new tab. |
| `action_label` | no | — | CTA text rendered next to the timestamp. Only shown when `action_url` is set. |

In addition to the keys above, every row carries Laravel's standard notification envelope: `id` (UUID), `type` (notification class), `read_at` (ISO timestamp or `null`), `created_at`. The renderer uses `id` for mark-as-read / delete calls, `type` for grouping hooks, `read_at` to grey out read entries, and `created_at` for the relative timestamp.

## Configuration

```php
// config/martis.php
'notifications' => [
    'enabled' => env('MARTIS_NOTIFICATIONS_ENABLED', true),
    'poll_interval' => env('MARTIS_NOTIFICATIONS_POLL_INTERVAL', 90000),
    'max_in_dropdown' => env('MARTIS_NOTIFICATIONS_MAX_DROPDOWN', 10),
],
```

| Key | Effect |
|-----|--------|
| `enabled` | Master switch. When false, the bell never renders and the API returns empty payloads. |
| `poll_interval` | How often the bell badge polls `/api/notifications/unread-count` (ms). **Default 90000 (90 s)** — bumped from 60 s in v1.8.8 to halve idle traffic without a UX regression for typical workflows. Set to `0` to disable polling — refresh manually via React Query / broadcast events. |
| `max_in_dropdown` | Maximum entries shown in the dropdown. Older entries live behind a future "View all" link. Capped at 50 server-side. |

**Hiding notifications + the bell.** `enabled` is the single switch for "do I want system notifications in this panel?" Set it to `false` (or `MARTIS_NOTIFICATIONS_ENABLED=false`) and the bell icon next to the user profile disappears, polling stops, and the REST endpoints return empty payloads. There is no separate `user_menu` toggle for the bell — this is it.

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

Polling defaults to 90 s — fine for most apps. Two honest facts up front:

- `MartisNotification::toBroadcast()` only emits Laravel's own `Illuminate\Notifications\Events\BroadcastNotificationCreated` event on the server side. It does **not** deliver anything to the browser by itself.
- **Martis ships no broadcaster, no `routes/channels.php`, and no Echo client.** The bell has no built-in Echo consumer. Getting an actual instant push to the browser via Laravel broadcasting is entirely on the host app to build — see option 1 below. If you want instant delivery without any of that infrastructure, use the pluggable feed in option 2 instead.

### Option 1 — Laravel broadcasting (Reverb/Pusher), wired by you

To get real Slack-like push delivery through Laravel's broadcasting layer, the host app must:

1. Stand up a broadcaster (Reverb, Pusher, or another `BROADCAST_CONNECTION` driver) and configure it in `config/broadcasting.php` / `.env`.
2. Publish `routes/channels.php` with a `Broadcast::channel('App.Models.User.{id}', function ($user, $id) { return (int) $user->id === (int) $id; })` authorization callback, since notifications broadcast on the notifiable's private channel.
3. Add the `broadcast` channel to your notification's `via()`:

   ```php
   class MyNotification extends MartisNotification
   {
       public function via(mixed $notifiable): array
       {
           return ['database', 'broadcast'];
       }
   }
   ```

   The `MartisNotification` base provides a default `toBroadcast()` payload (the same array as `toArray()`), so you don't need to implement that method yourself — but everything in steps 1-2, plus step 4 below, is still on you.
4. Install and bootstrap an Echo client (`laravel-echo` + a driver package such as `pusher-js`) in your own extension bundle, and subscribe: `echo.private('App.Models.User.{id}').notification(n => martisEventBus.emit('martis:notification-received', n))`. This is exactly the seam described in option 2 — Echo just becomes one more transport feeding it.

Martis intentionally ships none of this (no bundled Echo/Pusher/websocket client, no channel routes) to stay broadcaster-agnostic and avoid forcing a dependency on apps that don't need real-time delivery.

### Option 2 — Pluggable real-time feed (no Reverb required)

Any transport — your own WebSocket gateway, Server-Sent Events, or an Echo listener you write per option 1 — can push a notification into the bell instantly, with zero broadcaster setup, by emitting on the shared Martis event bus (see `docs/components.md` "Event Bus"):

```ts
import { martisEventBus } from '@martis/runtime'

// e.g. inside your own WebSocket `onmessage` handler, SSE `onmessage`,
// or an Echo `.notification()` callback:
martisEventBus.emit('martis:notification-received', {
  id: payload.id,
  title: payload.title,
  message: payload.message,
})
```

The notification bell (`NotificationBell.tsx`) is poll-only by default, but it also subscribes to this event: emitting `martis:notification-received` bumps the unread badge and, if the dropdown is already open, prepends the item to the visible list — immediately, no waiting for the next poll.

Polling (`MARTIS_NOTIFICATIONS_POLL_INTERVAL`, default 90 s) keeps running underneath as the reconciliation fallback, so a missed or dropped push self-heals on the next poll tick instead of leaving the badge permanently stale. The real-time feed is additive, not a replacement — Martis ships no transport of its own, only the event contract the bell listens for.

#### Reconciling reads across sessions

`martis:notification-received` only moves the badge **up**. When a notification is marked read (or read-all / deleted) in *another* session, that session's own count goes down, but a second open session keeps showing the inflated count until its next poll. To converge immediately, emit the payload-less companion event:

```ts
import { martisEventBus } from '@martis/runtime'

// e.g. when your socket/SSE tells this tab a read happened elsewhere:
martisEventBus.emit('martis:notifications-changed', {})
```

The bell responds by re-fetching `/api/notifications/unread-count` (and the open list) right away, so the badge reconciles in whichever direction the server now reports — the down-direction mirror of `martis:notification-received`. Bridge it from the same transport that already feeds the received event. As always, the poll remains the fallback if no transport fires it.

## Migration

The `martis:install --force` command publishes a migration that creates the standard Laravel `notifications` table (idempotent — skipped when the table already exists). Apps that already ran `php artisan notifications:table` are compatible with no migration needed.

## Tests

- 9 feature tests in `tests/Feature/NotificationControllerTest.php` covering the six endpoints, the data shape, ownership scoping, and the disabled-config case.
