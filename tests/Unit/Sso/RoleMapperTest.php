<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Martis\Sso\RoleMapper;

uses(\Martis\Tests\TestCase::class);

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

it('global resolver override beats every config strategy', function () {
    RoleMapper::resolveUsing(fn (array $roles, $user, string $provider) => SsoTestRole::query()->whereIn('name', ['admin'])->get());

    $mapper = new RoleMapper;
    $result = $mapper->map(['anything'], null, 'testdriver');

    expect($result->pluck('name')->all())->toBe(['admin']);
});
