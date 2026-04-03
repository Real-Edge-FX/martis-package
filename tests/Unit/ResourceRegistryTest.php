<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Martis\Resource;
use Martis\ResourceRegistry;

// ---------------------------------------------------------------------------
// Test fixtures
// ---------------------------------------------------------------------------

class RegistryTestModel extends Model
{
    protected $table = 'users';
}

class AnotherRegistryModel extends Model
{
    protected $table = 'posts';
}

class RegistryTestResource extends Resource
{
    public static function model(): string
    {
        return RegistryTestModel::class;
    }

    public function fields(Request $request): array
    {
        return [];
    }
}

class AnotherResource extends Resource
{
    public static function model(): string
    {
        return AnotherRegistryModel::class;
    }

    public function fields(Request $request): array
    {
        return [];
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function freshRegistry(): ResourceRegistry
{
    return new ResourceRegistry;
}

// ---------------------------------------------------------------------------
// register
// ---------------------------------------------------------------------------

it('registers a resource class', function () {
    $registry = freshRegistry();
    $registry->register(RegistryTestResource::class);

    expect($registry->count())->toBe(1);
});

it('indexes resources by uri key', function () {
    $registry = freshRegistry();
    $registry->register(RegistryTestResource::class);

    $uriKey = RegistryTestResource::uriKey();
    expect($registry->has($uriKey))->toBeTrue();
});

it('throws InvalidArgumentException when class does not extend Resource', function () {
    $registry = freshRegistry();

    expect(fn () => $registry->register(stdClass::class))
        ->toThrow(InvalidArgumentException::class);
});

// ---------------------------------------------------------------------------
// registerMany
// ---------------------------------------------------------------------------

it('registers multiple resources at once', function () {
    $registry = freshRegistry();
    $registry->registerMany([RegistryTestResource::class, AnotherResource::class]);

    expect($registry->count())->toBe(2);
});

it('registerMany throws for invalid class in the list', function () {
    $registry = freshRegistry();

    expect(fn () => $registry->registerMany([stdClass::class]))
        ->toThrow(InvalidArgumentException::class);
});

// ---------------------------------------------------------------------------
// get
// ---------------------------------------------------------------------------

it('returns registered resource class by uri key', function () {
    $registry = freshRegistry();
    $registry->register(RegistryTestResource::class);

    $uriKey = RegistryTestResource::uriKey();
    expect($registry->get($uriKey))->toBe(RegistryTestResource::class);
});

it('throws RuntimeException when uri key is not found', function () {
    $registry = freshRegistry();

    expect(fn () => $registry->get('nonexistent'))
        ->toThrow(RuntimeException::class);
});

// ---------------------------------------------------------------------------
// has
// ---------------------------------------------------------------------------

it('returns true for registered uri key', function () {
    $registry = freshRegistry();
    $registry->register(RegistryTestResource::class);

    expect($registry->has(RegistryTestResource::uriKey()))->toBeTrue();
});

it('returns false for unknown uri key', function () {
    $registry = freshRegistry();

    expect($registry->has('not-registered'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// all / list / count
// ---------------------------------------------------------------------------

it('all returns array indexed by uri key', function () {
    $registry = freshRegistry();
    $registry->registerMany([RegistryTestResource::class, AnotherResource::class]);

    $all = $registry->all();
    expect($all)->toBeArray();
    expect($all)->toHaveKey(RegistryTestResource::uriKey());
    expect($all)->toHaveKey(AnotherResource::uriKey());
});

it('list returns a flat list of class names', function () {
    $registry = freshRegistry();
    $registry->registerMany([RegistryTestResource::class, AnotherResource::class]);

    $list = $registry->list();
    expect($list)->toBeArray();
    expect(array_is_list($list))->toBeTrue();
    expect($list)->toContain(RegistryTestResource::class);
    expect($list)->toContain(AnotherResource::class);
});

it('count returns number of registered resources', function () {
    $registry = freshRegistry();
    expect($registry->count())->toBe(0);

    $registry->register(RegistryTestResource::class);
    expect($registry->count())->toBe(1);

    $registry->register(AnotherResource::class);
    expect($registry->count())->toBe(2);
});

// ---------------------------------------------------------------------------
// flush
// ---------------------------------------------------------------------------

it('flush removes all registered resources', function () {
    $registry = freshRegistry();
    $registry->registerMany([RegistryTestResource::class, AnotherResource::class]);
    expect($registry->count())->toBe(2);

    $registry->flush();
    expect($registry->count())->toBe(0);
    expect($registry->all())->toBe([]);
});

// ---------------------------------------------------------------------------
// Overwrite
// ---------------------------------------------------------------------------

it('registering the same uri key twice overwrites the previous class', function () {
    $registry = freshRegistry();
    $registry->register(RegistryTestResource::class);

    // Override with a different class that happens to produce the same uriKey.
    $override = new class extends Resource
    {
        public static function model(): string
        {
            return RegistryTestModel::class;
        }

        public function fields(Request $request): array
        {
            return [];
        }
    };

    $overrideClass = $override::class;
    $registry->register($overrideClass);

    expect($registry->count())->toBe(1);
    expect($registry->get(RegistryTestResource::uriKey()))->toBe($overrideClass);
});
