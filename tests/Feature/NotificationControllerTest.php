<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Martis\Enums\NotificationLevel;
use Martis\Facades\Martis;
use Martis\Notifications\MartisNotification;

class NotificationTestUser extends User
{
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];
}

beforeEach(function () {
    // Bootstrap the minimal schema this suite needs. The shared
    // RefreshDatabase + custom `migrateFreshUsing` setup intentionally
    // skips the testbench `database/migrations/` folder (parallel-safe
    // workaround), so we can't rely on a `users` table being present
    // by default — bootstrap one here and add the standard Laravel
    // notifications table on top.
    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
    }
    if (! Schema::hasTable('notifications')) {
        Schema::create('notifications', function ($table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    $this->user = NotificationTestUser::create([
        'name' => 'Test User',
        'email' => 'notif-tester@martis.test',
        'password' => bcrypt('secret'),
    ]);

    $this->actingAs($this->user);
});

afterEach(function () {
    Martis::forgetNotificationScope();
});

/** A notification of the test user tagged with a tenant, as an app would store it. */
function tenantNotification(NotificationTestUser $user, string $title, int $tenant): DatabaseNotification
{
    /** @var DatabaseNotification $notification */
    $notification = $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => MartisNotification::class,
        'data' => ['title' => $title, 'message' => $title, 'level' => 'info', 'tenant_id' => $tenant],
    ]);

    return $notification;
}

function scopeNotificationsToTenant(int $tenant): void
{
    Martis::scopeNotificationsUsing(fn ($query, Request $request) => $query->where('data->tenant_id', $tenant));
}

it('returns an empty list when the user has no notifications', function () {
    $response = $this->getJson('/martis/api/notifications');

    $response->assertOk();
    expect($response->json('data'))->toBe([]);
    expect($response->json('meta.total'))->toBe(0);
    expect($response->json('meta.unread'))->toBe(0);
});

it('returns the unread count', function () {
    $this->user->notify(MartisNotification::make(
        title: 'Hello',
        message: 'You have a new message.',
        level: NotificationLevel::Info,
    ));

    $response = $this->getJson('/martis/api/notifications/unread-count');

    $response->assertOk();
    expect($response->json('unread'))->toBe(1);
});

it('serialises a notification with the standard Martis data shape', function () {
    $this->user->notify(MartisNotification::make(
        title: 'Invoice paid',
        message: 'INV-2026-001 has been paid.',
        level: NotificationLevel::Success,
        icon: 'check-circle',
        actionUrl: '/martis/resources/invoices/42',
        actionLabel: 'View invoice',
    ));

    $response = $this->getJson('/martis/api/notifications');
    $response->assertOk();

    $first = $response->json('data.0');
    expect($first['title'])->toBe('Invoice paid');
    expect($first['message'])->toBe('INV-2026-001 has been paid.');
    expect($first['level'])->toBe('success');
    expect($first['icon'])->toBe('check-circle');
    expect($first['action_url'])->toBe('/martis/resources/invoices/42');
    expect($first['action_label'])->toBe('View invoice');
    expect($first['read_at'])->toBeNull();
});

it('marks a single notification as read', function () {
    $this->user->notify(MartisNotification::make('Test', 'Body'));
    $notification = DatabaseNotification::query()->first();

    $response = $this->postJson("/martis/api/notifications/{$notification->id}/read");

    $response->assertOk();
    expect($this->user->fresh()->unreadNotifications()->count())->toBe(0);
});

it('marks every notification as read at once', function () {
    $this->user->notify(MartisNotification::make('A', 'a'));
    $this->user->notify(MartisNotification::make('B', 'b'));
    $this->user->notify(MartisNotification::make('C', 'c'));

    $this->postJson('/martis/api/notifications/read-all')->assertOk();

    expect($this->user->fresh()->unreadNotifications()->count())->toBe(0);
});

it('deletes a single notification', function () {
    $this->user->notify(MartisNotification::make('Test', 'Body'));
    $notification = DatabaseNotification::query()->first();

    $this->deleteJson("/martis/api/notifications/{$notification->id}")->assertOk();

    expect($this->user->fresh()->notifications()->count())->toBe(0);
});

it('clears every notification at once', function () {
    $this->user->notify(MartisNotification::make('A', 'a'));
    $this->user->notify(MartisNotification::make('B', 'b'));

    $this->deleteJson('/martis/api/notifications')->assertOk();

    expect($this->user->fresh()->notifications()->count())->toBe(0);
});

it('returns an empty payload when the feature is disabled in config', function () {
    config()->set('martis.notifications.enabled', false);

    $response = $this->getJson('/martis/api/notifications');

    $response->assertOk();
    expect($response->json('data'))->toBe([]);
    expect($response->json('meta.enabled'))->toBe(false);
});

it('returns 404 when marking a notification that does not belong to the user', function () {
    $other = NotificationTestUser::create([
        'name' => 'Other',
        'email' => 'other@martis.test',
        'password' => bcrypt('secret'),
    ]);
    $other->notify(MartisNotification::make('Stranger', 'Not yours.'));
    $stranger = DatabaseNotification::query()->first();

    $this->postJson("/martis/api/notifications/{$stranger->id}/read")->assertNotFound();
});

// ---------------------------------------------------------------------------
// Martis::scopeNotificationsUsing() (v2.1.0)
// ---------------------------------------------------------------------------

it('narrows the list and both unread counts to the registered scope', function () {
    tenantNotification($this->user, 'Tenant 1', 1);
    tenantNotification($this->user, 'Tenant 2', 2);
    scopeNotificationsToTenant(1);

    $index = $this->getJson('/martis/api/notifications')->assertOk();

    expect(collect($index->json('data'))->pluck('title')->all())->toBe(['Tenant 1'])
        ->and($index->json('meta.total'))->toBe(1)
        ->and($index->json('meta.unread'))->toBe(1)
        ->and($this->getJson('/martis/api/notifications/unread-count')->json('unread'))->toBe(1);
});

it('answers 404 to mark-read and delete on a notification outside the scope', function () {
    $other = tenantNotification($this->user, 'Tenant 2', 2);
    scopeNotificationsToTenant(1);

    $this->postJson("/martis/api/notifications/{$other->id}/read")->assertNotFound();
    $this->deleteJson("/martis/api/notifications/{$other->id}")->assertNotFound();

    expect($other->fresh()->read_at)->toBeNull();
});

it('leaves a notification outside the scope alone on mark-all-read and clear-all', function () {
    tenantNotification($this->user, 'Tenant 1', 1);
    $other = tenantNotification($this->user, 'Tenant 2', 2);
    scopeNotificationsToTenant(1);

    $this->postJson('/martis/api/notifications/read-all')->assertOk();
    expect($other->fresh()->read_at)->toBeNull();

    $this->deleteJson('/martis/api/notifications')->assertOk();
    expect($other->fresh())->not->toBeNull()
        ->and($this->user->notifications()->count())->toBe(1);
});

it('keeps every notification of the user without a scope', function () {
    tenantNotification($this->user, 'Tenant 1', 1);
    tenantNotification($this->user, 'Tenant 2', 2);

    expect($this->getJson('/martis/api/notifications')->json('meta.total'))->toBe(2);
});

// Adversarial: a scope with orWhere must never reach another user's rows.
// The scope used to join the relation's `notifiable_type = ? and
// notifiable_id = ?` at top level, so `where(a)->orWhere(b)` matched
// every user's notification tagged `b` on all six endpoints.

function scopeNotificationsWithOrWhere(): void
{
    Martis::scopeNotificationsUsing(fn ($query, Request $request) => $query
        ->where('data->tenant_id', 1)
        ->orWhere('data->tenant_id', 2));
}

function strangerTenantNotification(): DatabaseNotification
{
    $stranger = NotificationTestUser::create([
        'name' => 'Stranger',
        'email' => 'stranger@martis.test',
        'password' => bcrypt('secret'),
    ]);

    return tenantNotification($stranger, 'Stranger tenant 2', 2);
}

it('keeps an orWhere scope inside the user on the list', function () {
    tenantNotification($this->user, 'Mine tenant 1', 1);
    strangerTenantNotification();
    scopeNotificationsWithOrWhere();

    $index = $this->getJson('/martis/api/notifications')->assertOk();

    expect(collect($index->json('data'))->pluck('title')->all())->toBe(['Mine tenant 1'])
        ->and($index->json('meta.total'))->toBe(1)
        ->and($index->json('meta.unread'))->toBe(1);
});

it('keeps an orWhere scope inside the user on the unread count', function () {
    tenantNotification($this->user, 'Mine tenant 1', 1);
    strangerTenantNotification();
    scopeNotificationsWithOrWhere();

    expect($this->getJson('/martis/api/notifications/unread-count')->json('unread'))->toBe(1);
});

it('answers 404 to mark-read on another user\'s notification under an orWhere scope', function () {
    $stranger = strangerTenantNotification();
    scopeNotificationsWithOrWhere();

    $this->postJson("/martis/api/notifications/{$stranger->id}/read")->assertNotFound();

    expect($stranger->fresh()->read_at)->toBeNull();
});

it('answers 404 to delete on another user\'s notification under an orWhere scope', function () {
    $stranger = strangerTenantNotification();
    scopeNotificationsWithOrWhere();

    $this->deleteJson("/martis/api/notifications/{$stranger->id}")->assertNotFound();

    expect($stranger->fresh())->not->toBeNull();
});

it('leaves another user\'s notification unread on mark-all-read under an orWhere scope', function () {
    $mine = tenantNotification($this->user, 'Mine tenant 2', 2);
    $stranger = strangerTenantNotification();
    scopeNotificationsWithOrWhere();

    $this->postJson('/martis/api/notifications/read-all')->assertOk();

    expect($stranger->fresh()->read_at)->toBeNull()
        ->and($mine->fresh()->read_at)->not->toBeNull();
});

it('leaves another user\'s notification in the table on clear-all under an orWhere scope', function () {
    $mine = tenantNotification($this->user, 'Mine tenant 2', 2);
    $stranger = strangerTenantNotification();
    scopeNotificationsWithOrWhere();

    $this->deleteJson('/martis/api/notifications')->assertOk();

    expect($stranger->fresh())->not->toBeNull()
        ->and($mine->fresh())->toBeNull();
});
