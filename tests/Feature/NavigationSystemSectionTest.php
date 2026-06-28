<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\MartisManager;
use Martis\ResourceRegistry;

class NavSystemSectionTestUser extends User
{
    protected $table = 'users';

    protected $guarded = [];
}

/**
 * SYSTEM nav section — guard against the gear icon re-introduction.
 *
 * The SYSTEM section appears in the sidebar when at least one item is
 * visible to the user (cache admin link + any system-grouped resources).
 * In v1.15.0, a decorative gear icon was added to the section header.
 * This test ensures that icon is removed in v1.15.1 — peer groups like
 * GOVERNANCE and custom user groups never render a group-level icon.
 */
beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
    }

    $this->user = NavSystemSectionTestUser::query()->create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => bcrypt('password'),
    ]);

    app(MartisManager::class)->forgetMainMenu();

    $registry = app(ResourceRegistry::class);
    $registry->flush();

    config()->set('martis.cache.admin_ui', true);

    Gate::define('manage-martis-cache', fn () => true);

    $this->actingAs($this->user, 'web');
});

afterEach(function () {
    app(MartisManager::class)->forgetMainMenu();
});

it('SYSTEM nav section is rendered without an icon (v1.15.1+ — was gear)', function () {
    $response = $this->getJson('/martis/api/navigation');

    $response->assertOk();

    $sections = collect($response->json());
    $system = $sections->firstWhere('label', __('martis::messages.system'));

    expect($system)->not->toBeNull('Expected the SYSTEM section to appear in the navigation payload');
    expect($system)->not->toHaveKey('icon', 'SYSTEM section must not carry an icon (the historical gear was visually inconsistent with peer groups like GOVERNANCE).');
});
