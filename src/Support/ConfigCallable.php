<?php

declare(strict_types=1);

namespace Martis\Support;

use InvalidArgumentException;

/**
 * Resolves the config knobs that take a callable: `brand.page_title`,
 * `gates.plan_resolver`, `profile.avatar.url_resolver` and an SSO
 * provider's `role_source_callable` and `role_callable`.
 *
 * `php artisan config:cache` writes the merged config with `var_export()`,
 * so only scalars and arrays survive it. A closure or an object breaks the
 * cache (`Call to undefined method ...::__set_state()`), even one set from
 * a service provider's `boot()`: the command boots every provider before
 * it exports the config. Two cache-safe forms remain, a
 * `[Class::class, 'staticMethod']` array and the name of an invokable
 * class. PHP does not count a class name as a callable (`is_callable()` is
 * false for it), so the invokable class is built here through the
 * container, which also injects its constructor dependencies.
 *
 * A knob set to anything that does not resolve to a callable throws
 * instead of falling back: the fallbacks (a locked plan gate, the disk
 * URL, no SSO roles) hide the mistake.
 */
final class ConfigCallable
{
    /**
     * The callable `$value` holds, or null when the knob is unset (null,
     * an empty string or false).
     *
     * @param  string  $key  the config key `$value` was read from, named in the exception
     *
     * @throws InvalidArgumentException when `$value` is set but resolves to no callable
     */
    public static function resolve(mixed $value, string $key): ?callable
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        $callable = self::isInvokableClass($value) ? app($value) : $value;

        if (is_callable($callable)) {
            return $callable;
        }

        throw new InvalidArgumentException(sprintf(
            'The [%s] config value is not a callable: %s. Use a [Class::class, \'staticMethod\'] array or the name of a class with an __invoke() method.',
            $key,
            self::describe($value),
        ));
    }

    /**
     * Whether `$value` names a class with an `__invoke()` method.
     *
     * @phpstan-assert-if-true class-string $value
     */
    public static function isInvokableClass(mixed $value): bool
    {
        return is_string($value) && class_exists($value) && method_exists($value, '__invoke');
    }

    /**
     * Why `$value` is not a callable, for the exception message.
     */
    private static function describe(mixed $value): string
    {
        if (is_string($value) && str_contains($value, '::')) {
            $value = explode('::', $value, 2);
        }

        if (is_array($value) && array_is_list($value) && count($value) === 2 && is_string($value[0]) && is_string($value[1])) {
            [$class, $method] = $value;

            return match (true) {
                ! class_exists($class) => "class {$class} does not exist",
                ! method_exists($class, $method) => "{$class}::{$method}() does not exist",
                default => "{$class}::{$method}() is not a public static method",
            };
        }

        if (is_string($value)) {
            return class_exists($value)
                ? "{$value} has no public __invoke() method"
                : "no class or function is named \"{$value}\"";
        }

        return 'got '.get_debug_type($value);
    }
}
