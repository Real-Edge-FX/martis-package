<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Schema;
use Martis\Sso\PermissionAdapters\CallableAdapter;
use Martis\Sso\PermissionAdapters\NativeAdapter;
use Martis\Sso\PermissionAdapters\SpatieAdapter;

uses(\Martis\Tests\TestCase::class);

class AdapterTestUser extends User
{
    protected $table = 'users';
    protected $guarded = [];
}

class AdapterTestRoleStub extends Model
{
    protected $table = 'adapter_test_roles';
    protected $guarded = [];
    public $timestamps = false;
}

beforeEach(function () {
    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
    }

    Schema::dropIfExists('model_has_roles');
    Schema::create('model_has_roles', function ($table) {
        $table->unsignedBigInteger('role_id');
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
        $table->primary(['role_id', 'model_type', 'model_id']);
    });

    Schema::dropIfExists('adapter_test_roles');
    Schema::create('adapter_test_roles', function ($table) {
        $table->id();
        $table->string('name');
    });

    AdapterTestRoleStub::create(['name' => 'admin']);
    AdapterTestRoleStub::create(['name' => 'editor']);
    AdapterTestRoleStub::create(['name' => 'guest']);

    CallableAdapter::forgetCallback();
});

afterEach(function () {
    Schema::dropIfExists('model_has_roles');
    Schema::dropIfExists('adapter_test_roles');
    CallableAdapter::forgetCallback();
});

// -----------------------------------------------------------------------------
// SpatieAdapter
// -----------------------------------------------------------------------------

it('SpatieAdapter::isAvailable reports the correct state for the current install', function () {
    $expected = class_exists('Spatie\\Permission\\Models\\Role')
        && trait_exists('Spatie\\Permission\\Traits\\HasRoles');

    expect(SpatieAdapter::isAvailable())->toBe($expected);
});

it('SpatieAdapter silently no-ops on a user model that lacks the HasRoles trait', function () {
    $user = AdapterTestUser::query()->create([
        'name' => 'A', 'email' => 'a@example.com', 'password' => bcrypt('x'),
    ]);

    $adapter = new SpatieAdapter;
    $roles = AdapterTestRoleStub::query()->whereIn('name', ['admin'])->get();

    // Should not throw — even if Spatie is installed, our test User
    // doesn't `use HasRoles`. The adapter must guard with method_exists.
    $adapter->syncRoles($user, $roles);
    expect(true)->toBeTrue();
});

// -----------------------------------------------------------------------------
// NativeAdapter
// -----------------------------------------------------------------------------

it('NativeAdapter inserts pivot rows for the resolved roles', function () {
    $user = AdapterTestUser::query()->create([
        'name' => 'B', 'email' => 'b@example.com', 'password' => bcrypt('x'),
    ]);

    $roles = AdapterTestRoleStub::query()->whereIn('name', ['admin', 'editor'])->get();

    $adapter = new NativeAdapter;
    $adapter->syncRoles($user, $roles);

    $pivot = \DB::table('model_has_roles')
        ->where('model_type', AdapterTestUser::class)
        ->where('model_id', $user->id)
        ->pluck('role_id')
        ->toArray();

    expect(count($pivot))->toBe(2);
    expect($pivot)->toEqualCanonicalizing($roles->pluck('id')->all());
});

it('NativeAdapter detaches roles that are no longer in the resolved set', function () {
    $user = AdapterTestUser::query()->create([
        'name' => 'C', 'email' => 'c@example.com', 'password' => bcrypt('x'),
    ]);

    $allThree = AdapterTestRoleStub::query()->get();
    $adapter = new NativeAdapter;

    // Initial: assign all three.
    $adapter->syncRoles($user, $allThree);
    expect(\DB::table('model_has_roles')->where('model_id', $user->id)->count())->toBe(3);

    // Re-sync with only admin.
    $admin = AdapterTestRoleStub::query()->where('name', 'admin')->get();
    $adapter->syncRoles($user, $admin);

    $remaining = \DB::table('model_has_roles')->where('model_id', $user->id)->pluck('role_id')->toArray();
    expect($remaining)->toEqual($admin->pluck('id')->all());
});

it('NativeAdapter detaches everything when an empty collection is passed', function () {
    $user = AdapterTestUser::query()->create([
        'name' => 'D', 'email' => 'd@example.com', 'password' => bcrypt('x'),
    ]);

    $adapter = new NativeAdapter;
    $adapter->syncRoles($user, AdapterTestRoleStub::query()->get());
    expect(\DB::table('model_has_roles')->where('model_id', $user->id)->count())->toBeGreaterThan(0);

    $adapter->syncRoles($user, new Collection);
    expect(\DB::table('model_has_roles')->where('model_id', $user->id)->count())->toBe(0);
});

// -----------------------------------------------------------------------------
// CallableAdapter
// -----------------------------------------------------------------------------

it('CallableAdapter delegates to the registered closure', function () {
    $captured = null;

    CallableAdapter::setCallback(function ($user, $roles) use (&$captured) {
        $captured = ['user' => $user->email, 'roles' => $roles->pluck('name')->all()];
    });

    $adapter = new CallableAdapter;
    $user = AdapterTestUser::query()->create([
        'name' => 'E', 'email' => 'e@example.com', 'password' => bcrypt('x'),
    ]);

    $roles = AdapterTestRoleStub::query()->whereIn('name', ['editor'])->get();
    $adapter->syncRoles($user, $roles);

    expect($captured['user'])->toBe('e@example.com');
    expect($captured['roles'])->toEqual(['editor']);
});

it('CallableAdapter is a no-op when no closure is registered', function () {
    $adapter = new CallableAdapter;
    $user = AdapterTestUser::query()->create([
        'name' => 'F', 'email' => 'f@example.com', 'password' => bcrypt('x'),
    ]);

    // Must not throw.
    $adapter->syncRoles($user, AdapterTestRoleStub::query()->get());
    expect(true)->toBeTrue();
});

it('CallableAdapter::forgetCallback wipes the registered closure', function () {
    CallableAdapter::setCallback(fn () => throw new \RuntimeException('should not fire'));
    CallableAdapter::forgetCallback();

    $adapter = new CallableAdapter;
    $user = AdapterTestUser::query()->create([
        'name' => 'G', 'email' => 'g@example.com', 'password' => bcrypt('x'),
    ]);

    // Must not throw — callback was forgotten.
    $adapter->syncRoles($user, AdapterTestRoleStub::query()->get());
    expect(true)->toBeTrue();
});
