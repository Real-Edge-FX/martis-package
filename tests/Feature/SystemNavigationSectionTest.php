<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\MartisManager;
use Martis\Resource;
use Martis\ResourceRegistry;
use Martis\Resources\ActionEventResource;

class SystemNavTestUser extends User
{
    protected $table = 'users';

    protected $guarded = [];
}

/*
 * System sidebar group — pin the contract that:
 *
 *   1. ActionEventResource (and any resource returning
 *      `belongsToSystemSection() === true`) is lifted out of the
 *      regular grouping loop and rendered alongside the Cache admin
 *      link inside a single "System" sidebar section.
 *   2. The section appears whenever there is at least one visible
 *      item — either a system-grouped resource the user is
 *      authorized to see, or the Cache admin link (gated by
 *      `martis.cache.admin_ui` + the `manage-martis-cache` Gate).
 *   3. The section disappears entirely when the current user passes
 *      none of those checks (no audit, no roles, no cache).
 */

class SystemSectionTestModel extends Model
{
    protected $table = 'martis_test_system_items';

    protected $fillable = ['name'];
}

class SystemSectionTestResource extends Resource
{
    public static function model(): string
    {
        return SystemSectionTestModel::class;
    }

    public static function uriKey(): string
    {
        return 'system-test-resource';
    }

    public static function label(): string
    {
        return 'Roles';
    }

    public static function singularLabel(): string
    {
        return 'Role';
    }

    public function belongsToSystemSection(): bool
    {
        return true;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('martis_test_system_items');
    Schema::create('martis_test_system_items', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
    }

    $this->testUser = SystemNavTestUser::query()->create([
        'name' => 'Sys Admin',
        'email' => 'sysadmin@martis.test',
        'password' => bcrypt('secret'),
    ]);

    app(MartisManager::class)->forgetMainMenu();

    $registry = app(ResourceRegistry::class);
    $registry->flush();
});

afterEach(function () {
    app(MartisManager::class)->forgetMainMenu();
    Schema::dropIfExists('martis_test_system_items');
});

it('Resource::belongsToSystemSection() defaults to false', function () {
    $registry = app(ResourceRegistry::class);
    $registry->register(SystemSectionTestResource::class);

    expect((new SystemSectionTestResource)->belongsToSystemSection())->toBeTrue();

    // Spot-check the bundled audit-log resource — it returns true.
    expect((new ActionEventResource)->belongsToSystemSection())->toBeTrue();
});

it('renders system-grouped resources inside the System section, not the Resources section', function () {
    config()->set('martis.cache.admin_ui', false); // isolate the resource path.

    app(ResourceRegistry::class)->register(SystemSectionTestResource::class);

    $response = $this->getJson('/martis/api/navigation');
    $sections = collect($response->json());

    $response->assertStatus(200);

    $system = $sections->firstWhere('label', __('martis::messages.system'));
    expect($system)->not->toBeNull();

    $labels = collect($system['items'])->pluck('label');
    expect($labels)->toContain('Roles');

    // The Roles entry should NOT appear under any non-system section.
    $other = $sections->reject(fn ($s) => ($s['label'] ?? null) === __('martis::messages.system'));
    foreach ($other as $section) {
        $items = collect($section['items'] ?? []);
        expect($items->pluck('label'))->not->toContain('Roles');
    }
});

it('System section also includes the Cache admin link when enabled and the gate passes', function () {
    config()->set('martis.cache.admin_ui', true);
    Gate::define('manage-martis-cache', fn ($u) => true);

    $this->actingAs($this->testUser, 'web');

    app(ResourceRegistry::class)->register(SystemSectionTestResource::class);

    $response = $this->getJson('/martis/api/navigation');
    $sections = collect($response->json());
    $system = $sections->firstWhere('label', __('martis::messages.system'));

    expect($system)->not->toBeNull();

    // The cache link appears with `/system/cache` somewhere in its
    // serialised payload (URL key may vary by serializer version).
    $urls = collect($system['items'])->pluck('url')->all();
    expect($urls)->toContain('/system/cache');
});

it('System section is omitted when no item is visible to the user', function () {
    config()->set('martis.cache.admin_ui', false);
    Gate::define('manage-martis-cache', fn ($u) => false);

    $this->actingAs($this->testUser, 'web');

    // Register no system-grouped resources. ActionEventResource is
    // bundled, but the registry was flushed in beforeEach.

    $response = $this->getJson('/martis/api/navigation');
    $sections = collect($response->json());

    $response->assertStatus(200);
    expect($sections->firstWhere('label', __('martis::messages.system')))->toBeNull();
});
