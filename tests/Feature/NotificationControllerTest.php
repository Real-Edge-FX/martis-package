<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Schema;
use Martis\Enums\NotificationLevel;
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
