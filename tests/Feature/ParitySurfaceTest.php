<?php

declare(strict_types=1);

/**
 * Parity surface tripwire.
 *
 * Phase 2 of Task 18 — Parity Test Suite. The dedicated controller / unit
 * suites already cover behaviour in depth. This file is the single
 * tripwire that asserts the *public surface* of every v0.8 / v0.9
 * parity contract still exists with the documented signature.
 *
 * If a refactor accidentally removes a public method, renames it, or
 * narrows a `bool|Closure` parameter back to `bool`, this file fails
 * fast and points to the line in PARITY_MAP.md that promised it.
 *
 * The test is intentionally cheap — it relies on Reflection to inspect
 * signatures rather than booting the framework, except where a
 * round-trip to the cache / search subsystem is the cleanest way to
 * prove a hook is wired up. Adding a new feature to PARITY_MAP.md
 * should come with a matching entry here.
 */

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Martis\Cache\MartisCache;
use Martis\Fields\Field;
use Martis\Fields\Text;
use Martis\Notifications\MartisNotification;
use Martis\Resource;
use Martis\Sso\Facades\MartisSso;
use Martis\Sso\SsoManager;

class ParitySurfaceFakeModel extends Model
{
    protected $guarded = [];

    public $timestamps = false;
}

// ---------------------------------------------------------------------------
// Field — context-aware validation, immutable, reactive, closure-aware setters
// ---------------------------------------------------------------------------

it('Field exposes the v0.9 context-aware rules surface', function () {
    foreach (['creationRules', 'updateRules'] as $method) {
        $reflection = new ReflectionMethod(Field::class, $method);
        expect($reflection->isPublic())->toBeTrue("Field::{$method}() must remain public.");
        $params = $reflection->getParameters();
        expect($params)->toHaveCount(1);
        expect($params[0]->getName())->toBe('rules');
        expect((string) $params[0]->getType())->toBe('array');
    }
});

it('Field::immutable() exists and the schema serialises the flag', function () {
    $reflection = new ReflectionMethod(Field::class, 'immutable');
    expect($reflection->isPublic())->toBeTrue('Field::immutable() must remain public.');
    expect((string) $reflection->getParameters()[0]->getType())->toBe('bool');

    $field = Text::make('slug')->immutable();
    $schema = $field->toArray();

    expect($schema)
        ->toHaveKey('immutable')
        ->and($schema['immutable'])->toBeTrue();
});

it('Field::dependsOn() takes fields + nullable Closure and toggles isDependent()', function () {
    $reflection = new ReflectionMethod(Field::class, 'dependsOn');
    $params = $reflection->getParameters();

    expect($params)->toHaveCount(2);
    expect($params[0]->getName())->toBe('fields');
    expect((string) $params[0]->getType())->toBe('array');
    expect($params[1]->getName())->toBe('callback');
    expect((string) $params[1]->getType())->toBe('?Closure');

    $reactive = Text::make('a')->dependsOn(['b'], fn () => null);
    $passive = Text::make('a')->dependsOn(['b']);

    expect($reactive->isDependent())->toBeTrue('A field with watchers + closure must be dependent.');
    expect($passive->isDependent())->toBeFalse('A field with watchers but no closure is non-reactive.');
});

it('Field closure-aware setters all accept bool|Closure or string|Closure', function () {
    // PHP's ReflectionUnion normalises the union order alphabetically
    // (Closure|bool, Closure|null|string, ...). The PARITY_MAP wording
    // reads "bool|Closure" for human friendliness — what matters is the
    // *set* of accepted types. We check the set, not the ordering.
    $expectations = [
        'nullable' => ['bool', 'Closure'],
        'readonly' => ['bool', 'Closure'],
        'required' => ['bool', 'Closure'],
        'placeholder' => ['string', 'Closure'],
        'help' => ['string', 'Closure'],
        'tooltip' => ['string', 'Closure', 'null'],
        'withLabel' => ['string', 'Closure'],
    ];

    foreach ($expectations as $method => $accepted) {
        $reflection = new ReflectionMethod(Field::class, $method);
        $type = $reflection->getParameters()[0]->getType();
        expect($type)->toBeInstanceOf(ReflectionUnionType::class, "Field::{$method}() must accept a union type.");

        $names = array_map(static fn ($t) => $t->getName(), $type->getTypes());
        // Allow null to come either as a separate type or as nullable;
        // unions with Closure/bool/string are what we care about.
        sort($names);
        $expected = $accepted;
        sort($expected);
        expect($names)->toBe($expected, "Field::{$method}() should accept {".implode('|', $accepted).'}.');
    }
});

it('Field customisation hooks accept callable + the displayUsing pipeline form', function () {
    // resolveUsing / fillUsing keep the single-callable shape; displayUsing
    // accepts both a callable and an array pipeline (chainable transforms).
    $resolveUsing = new ReflectionMethod(Field::class, 'resolveUsing');
    expect((string) $resolveUsing->getParameters()[0]->getType())->toBe('callable');

    $fillUsing = new ReflectionMethod(Field::class, 'fillUsing');
    expect((string) $fillUsing->getParameters()[0]->getType())->toBe('callable');

    $displayUsing = new ReflectionMethod(Field::class, 'displayUsing');
    $displayType = $displayUsing->getParameters()[0]->getType();
    expect($displayType)->toBeInstanceOf(ReflectionUnionType::class);
    $names = array_map(static fn ($t) => $t->getName(), $displayType->getTypes());
    sort($names);
    expect($names)->toBe(['array', 'callable'], 'displayUsing() must accept callable|array.');

    // Pipeline smoke — chained transforms compose end-to-end through
    // resolveForDisplay() against a real Eloquent model.
    $field = Text::make('amount')
        ->displayUsing([
            fn ($v) => (string) ((int) $v * 2),
            fn ($v) => "$ {$v}",
        ]);

    $model = new ParitySurfaceFakeModel(['amount' => '5']);

    expect($field->resolveForDisplay($model))->toBe('$ 10');
});

// ---------------------------------------------------------------------------
// Resource — global search per-resource config + searchOrderBy
// ---------------------------------------------------------------------------

it('Resource::globallySearchable() is bool|array and Resource::searchOrderBy() returns Builder', function () {
    $reflection = new ReflectionMethod(Resource::class, 'globallySearchable');
    expect($reflection->isStatic())->toBeTrue('globallySearchable() must be static.');
    $return = $reflection->getReturnType();
    expect($return)->toBeInstanceOf(ReflectionUnionType::class);
    $names = array_map(static fn ($t) => $t->getName(), $return->getTypes());
    sort($names);
    expect($names)->toBe(['array', 'bool'], 'globallySearchable() must accept array|bool per-resource config.');

    $orderBy = new ReflectionMethod(Resource::class, 'searchOrderBy');
    expect($orderBy->isPublic())->toBeTrue();
    $orderByReturn = $orderBy->getReturnType();
    expect($orderByReturn)->not->toBeNull()
        ->and((string) $orderByReturn)->toBe(Builder::class);

    $params = $orderBy->getParameters();
    expect($params)->toHaveCount(2);
    expect($params[0]->getName())->toBe('query');
    expect((string) $params[0]->getType())->toBe(Builder::class);
    expect($params[1]->getName())->toBe('term');
    expect((string) $params[1]->getType())->toBe('string');
});

// ---------------------------------------------------------------------------
// MartisCache — extend() + types() + per-type runtime control
// ---------------------------------------------------------------------------

it('MartisCache::extend() registers a custom layer that surfaces in types()', function () {
    // Built-ins always present.
    $built = MartisCache::types();
    expect($built)->toContain('metrics', 'navigation', 'dashboards', 'schema');

    // Register a custom layer at runtime — admin page + Artisan must
    // pick it up without recompilation.
    MartisCache::extend('parity_surface_smoke', enabled: false, ttl: 60);

    try {
        $afterExtend = MartisCache::types();

        expect($afterExtend)->toContain('parity_surface_smoke');

        // Round-trip the runtime override — disable / enable / clearOverride.
        /** @var MartisCache $cache */
        $cache = app(MartisCache::class);
        $cache->disable('parity_surface_smoke');
        expect($cache->enabled('parity_surface_smoke'))->toBeFalse();

        $cache->enable('parity_surface_smoke');
        expect($cache->enabled('parity_surface_smoke'))->toBeTrue();

        $cache->clearOverride('parity_surface_smoke');
    } finally {
        // Static custom-layer registry leaks across tests — clean up
        // so adjacent suites (CacheControllerTest) don't see this
        // test's smoke layer in MartisCache::types().
        MartisCache::forgetExtension('parity_surface_smoke');
    }
});

// ---------------------------------------------------------------------------
// Notifications — MartisNotification::make + recommended payload shape
// ---------------------------------------------------------------------------

it('MartisNotification::make() builds a notification with the recommended payload shape', function () {
    $note = MartisNotification::make(
        title: 'Invoice paid',
        message: 'INV-2026-001 has been paid.',
        level: 'success',
    );

    // Notification classes carry a toArray($notifiable) the channel uses
    // to persist the payload — the bell consumes the same shape.
    $payload = $note->toArray((object) []);

    expect($payload)
        ->toHaveKey('title')
        ->and($payload['title'])->toBe('Invoice paid')
        ->and($payload['message'])->toBe('INV-2026-001 has been paid.')
        ->and($payload['level'])->toBe('success');
});

// ---------------------------------------------------------------------------
// SSO — pluggable provider contract + lifecycle hooks
// ---------------------------------------------------------------------------

it('SsoManager exposes the documented public API for providers + role mapping', function () {
    foreach (['extend', 'driver', 'isEnabled', 'enabledProviders', 'adapterFor'] as $method) {
        expect(method_exists(SsoManager::class, $method))
            ->toBeTrue("SsoManager::{$method}() is part of the parity surface.");
    }

    foreach ([
        'resolveUserUsing',
        'resolveRolesUsing',
        'syncRolesUsing',
        'afterLogin',
        'onNoRoleMatchUsing',
    ] as $hook) {
        $reflection = new ReflectionMethod(SsoManager::class, $hook);
        expect($reflection->isPublic())->toBeTrue("SsoManager::{$hook}() must be public.");
        $params = $reflection->getParameters();
        expect($params)->toHaveCount(1, "SsoManager::{$hook}() takes exactly one Closure.");
        expect($params[0]->getName())->toBe('callback');
        expect((string) $params[0]->getType())->toBe(Closure::class);
    }
});

it('MartisSso facade is registered and resolves to the SsoManager singleton', function () {
    $resolved = MartisSso::getFacadeRoot();
    expect($resolved)->toBeInstanceOf(SsoManager::class);
});

// ---------------------------------------------------------------------------
// Locale extensibility — config keys exist with the documented shape
// ---------------------------------------------------------------------------

it('martis.locales config exposes app_namespaces + fallback_chain', function () {
    // The deep-merge mechanic itself has its own coverage in
    // TranslationsControllerTest. This tripwire just guards the
    // config keys so that consumer publish overrides keep working.
    $defaults = require __DIR__.'/../../config/martis.php';

    expect($defaults)->toHaveKey('locales');
    expect($defaults['locales'])->toHaveKey('app_namespaces');
    expect($defaults['locales'])->toHaveKey('fallback_chain');
    expect($defaults['locales']['fallback_chain'])->toBeArray();
});
