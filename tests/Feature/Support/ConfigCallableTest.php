<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Martis\Support\ConfigCallable;
use Martis\Tests\Fixtures\ConfigCallables\AvatarUrl;
use Martis\Tests\Fixtures\ConfigCallables\AzureRoleSource;
use Martis\Tests\Fixtures\ConfigCallables\PageTitle;
use Martis\Tests\Fixtures\ConfigCallables\PlanResolver;
use Martis\Tests\Fixtures\ConfigCallables\SsoRoles;

/**
 * Round-trips a config value the way `php artisan config:cache` does:
 * `ConfigCacheCommand` writes the merged config with `var_export()` and
 * `require`s the file back to prove it loads.
 */
function throughConfigCache(mixed $value): mixed
{
    $path = (string) tempnam(sys_get_temp_dir(), 'martis-config-cache-');
    file_put_contents($path, '<?php return '.var_export($value, true).';'.PHP_EOL);

    try {
        return require $path;
    } finally {
        unlink($path);
    }
}

it('resolves an unset knob to null', function (mixed $value) {
    expect(ConfigCallable::resolve($value, 'martis.test.resolver'))->toBeNull();
})->with([
    'null' => [null],
    'empty string' => [''],
    'false' => [false],
]);

it('returns a PHP callable as it is', function (mixed $value) {
    expect(ConfigCallable::resolve($value, 'martis.test.resolver'))->toBe($value);
})->with([
    'static method array' => [[AvatarUrl::class, 'resolve']],
    'static method string' => [AvatarUrl::class.'::resolve'],
    'function name' => ['strtoupper'],
]);

it('returns a closure or an invokable instance as it is', function () {
    $closure = fn (string $path): string => 'closure/'.$path;
    $instance = new AvatarUrl;

    expect(ConfigCallable::resolve($closure, 'martis.test.resolver'))->toBe($closure)
        ->and(ConfigCallable::resolve($instance, 'martis.test.resolver'))->toBe($instance);
});

it('builds an invokable class name through the container', function () {
    config()->set('martis.brand.name', 'Acme');

    $resolver = ConfigCallable::resolve(PageTitle::class, 'martis.test.resolver');

    // PageTitle takes the config repository in its constructor: only a
    // container build injects it.
    expect($resolver)->toBeInstanceOf(PageTitle::class)
        ->and($resolver(Request::create('/martis')))->toBe('Acme · invokable · martis');
});

it('honours a container binding of an invokable class', function () {
    $bound = new AvatarUrl;
    app()->instance(AvatarUrl::class, $bound);

    expect(ConfigCallable::resolve(AvatarUrl::class, 'martis.test.resolver'))->toBe($bound);
});

it('rejects a value that does not resolve to a callable', function (mixed $value, string $reason) {
    expect(fn () => ConfigCallable::resolve($value, 'martis.test.resolver'))
        ->toThrow(InvalidArgumentException::class, "The [martis.test.resolver] config value is not a callable: {$reason}.");
})->with([
    'non-static method array' => [[AvatarUrl::class, 'handle'], AvatarUrl::class.'::handle() is not a public static method'],
    'non-static method string' => [AvatarUrl::class.'::handle', AvatarUrl::class.'::handle() is not a public static method'],
    'missing method' => [[AvatarUrl::class, 'missing'], AvatarUrl::class.'::missing() does not exist'],
    'missing class in an array' => [['App\\Gates\\MissingResolver', 'resolve'], 'class App\\Gates\\MissingResolver does not exist'],
    'missing class name' => ['App\\Gates\\MissingResolver', 'no class or function is named "App\\Gates\\MissingResolver"'],
    'Class@method string' => [AvatarUrl::class.'@handle', 'no class or function is named "'.AvatarUrl::class.'@handle"'],
    'class without __invoke' => [stdClass::class, 'stdClass has no public __invoke() method'],
    'integer' => [42, 'got int'],
    'plain array' => [['en' => 'Admin'], 'got array'],
]);

it('tells the name of an invokable class from any other string', function (mixed $value, bool $invokable) {
    expect(ConfigCallable::isInvokableClass($value))->toBe($invokable);
})->with([
    'invokable class' => [PageTitle::class, true],
    'class without __invoke' => [stdClass::class, false],
    'missing class' => ['App\\Gates\\MissingResolver', false],
    'literal title' => ['Acme Back Office', false],
    'PHP function name' => ['Mail', false],
    'static method array' => [[PageTitle::class, 'resolve'], false],
]);

it('keeps every documented form callable through the config cache', function (string $key, mixed $value) {
    $cached = throughConfigCache($value);

    expect($cached)->toBe($value)
        ->and(ConfigCallable::resolve($cached, $key))->toBeCallable();
})->with([
    'page_title, invokable class' => ['martis.brand.page_title', PageTitle::class],
    'page_title, static method' => ['martis.brand.page_title', [PageTitle::class, 'resolve']],
    'plan_resolver, invokable class' => ['martis.gates.plan_resolver', PlanResolver::class],
    'plan_resolver, static method' => ['martis.gates.plan_resolver', [PlanResolver::class, 'resolve']],
    'url_resolver, invokable class' => ['martis.profile.avatar.url_resolver', AvatarUrl::class],
    'url_resolver, static method' => ['martis.profile.avatar.url_resolver', [AvatarUrl::class, 'resolve']],
    'role_source_callable, invokable class' => ['martis.auth.sso.providers.azure.role_source_callable', AzureRoleSource::class],
    'role_source_callable, static method' => ['martis.auth.sso.providers.azure.role_source_callable', [AzureRoleSource::class, 'resolve']],
    'role_callable, invokable class' => ['martis.auth.sso.providers.azure.role_callable', SsoRoles::class],
    'role_callable, static method' => ['martis.auth.sso.providers.azure.role_callable', [SsoRoles::class, 'resolve']],
]);

it('cannot carry a closure or an object through the config cache', function (mixed $value, string $class) {
    expect(fn () => throughConfigCache($value))
        ->toThrow(Error::class, "Call to undefined method {$class}::__set_state()");
})->with([
    'closure' => [fn (string $path): string => $path, Closure::class],
    'invokable instance' => [new AvatarUrl, AvatarUrl::class],
]);

it('ships a default config that survives the config cache', function () {
    expect(throughConfigCache(config('martis')))->toBe(config('martis'));
});
