<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Martis\Sso\RoleMapper;
use Martis\Tests\Fixtures\ConfigCallables\SsoRoles;
use Martis\Tests\TestCase;

uses(TestCase::class);

class SsoTestRole extends Model
{
    protected $table = 'sso_test_roles';

    protected $guarded = [];

    public $timestamps = false;
}

beforeEach(function () {
    Schema::dropIfExists('sso_test_roles');
    Schema::create('sso_test_roles', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('azure_group_name')->nullable();
    });

    SsoTestRole::create(['name' => 'admin', 'azure_group_name' => 'PMI ADMIN']);
    SsoTestRole::create(['name' => 'editor', 'azure_group_name' => 'PMI EDITOR']);
    SsoTestRole::create(['name' => 'guest', 'azure_group_name' => 'PMI GUEST']);

    config()->set('martis.auth.sso.providers.testdriver', [
        'role_strategy' => 'column',
        'role_column' => 'azure_group_name',
        'role_model' => SsoTestRole::class,
    ]);

    RoleMapper::forgetResolver();
});

afterEach(function () {
    Schema::dropIfExists('sso_test_roles');
    RoleMapper::forgetResolver();
});

it('column strategy resolves roles whose azure_group_name matches the external list', function () {
    $mapper = new RoleMapper;
    $result = $mapper->map(['PMI ADMIN', 'PMI EDITOR'], null, 'testdriver');

    expect($result)->toHaveCount(2);
    expect($result->pluck('name')->all())->toContain('admin', 'editor');
    expect($result->pluck('name')->all())->not->toContain('guest');
});

it('column strategy returns an empty collection when no external roles match', function () {
    $mapper = new RoleMapper;
    $result = $mapper->map(['UNKNOWN'], null, 'testdriver');

    expect($result)->toHaveCount(0);
});

it('column strategy returns an empty collection on empty input', function () {
    $mapper = new RoleMapper;
    expect($mapper->map([], null, 'testdriver'))->toHaveCount(0);
});

it('config strategy maps env-resolved values to local role slugs', function () {
    config()->set('martis.auth.sso.providers.testdriver', [
        'role_strategy' => 'config',
        'role_map' => [
            'admin' => 'PMI ADMIN',
            'editor' => 'PMI EDITOR',
            'unused' => null,
        ],
        'role_lookup_column' => 'name',
        'role_model' => SsoTestRole::class,
    ]);

    $mapper = new RoleMapper;
    $result = $mapper->map(['PMI ADMIN'], null, 'testdriver');

    expect($result->pluck('name')->all())->toContain('admin');
    expect($result->pluck('name')->all())->not->toContain('editor', 'unused');
});

it('callable strategy delegates to the host-app closure', function () {
    config()->set('martis.auth.sso.providers.testdriver', [
        'role_strategy' => 'callable',
        'role_callable' => fn ($externalRoles) => SsoTestRole::query()->whereIn('name', ['guest'])->get(),
    ]);

    $mapper = new RoleMapper;
    $result = $mapper->map(['anything'], null, 'testdriver');

    expect($result->pluck('name')->all())->toBe(['guest']);
});

it('callable strategy maps through role_callable in each callable form', function (mixed $callable, array $expected) {
    config()->set('martis.auth.sso.providers.testdriver', [
        'role_strategy' => 'callable',
        'role_callable' => $callable,
    ]);

    $result = (new RoleMapper)->map(['PMI ADMIN'], null, 'testdriver');

    expect($result->all())->toBe($expected);
})->with([
    'invokable class name' => [SsoRoles::class, ['invokable:testdriver:PMI ADMIN']],
    'static method array' => [[SsoRoles::class, 'resolve'], ['static:testdriver:PMI ADMIN']],
    'closure' => [
        fn (array $externalRoles, $user, string $provider) => new Collection(array_map(fn (string $role) => "closure:{$provider}:{$role}", $externalRoles)),
        ['closure:testdriver:PMI ADMIN'],
    ],
]);

it('callable strategy maps no roles when role_callable is unset', function () {
    config()->set('martis.auth.sso.providers.testdriver', ['role_strategy' => 'callable']);

    expect((new RoleMapper)->map(['PMI ADMIN'], null, 'testdriver')->all())->toBe([]);
});

it('callable strategy rejects a role_callable that is not a callable', function () {
    config()->set('martis.auth.sso.providers.testdriver', [
        'role_strategy' => 'callable',
        'role_callable' => SsoRoles::class.'::handle',
    ]);

    expect(fn () => (new RoleMapper)->map(['PMI ADMIN'], null, 'testdriver'))->toThrow(
        InvalidArgumentException::class,
        'The [martis.auth.sso.providers.testdriver.role_callable] config value is not a callable: '.SsoRoles::class.'::handle() is not a public static method.',
    );
});

it('global resolver override beats every config strategy', function () {
    RoleMapper::resolveUsing(fn (array $roles, $user, string $provider) => SsoTestRole::query()->whereIn('name', ['admin'])->get());

    $mapper = new RoleMapper;
    $result = $mapper->map(['anything'], null, 'testdriver');

    expect($result->pluck('name')->all())->toBe(['admin']);
});
