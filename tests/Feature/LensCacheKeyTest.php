<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Martis\Http\Controllers\LensController;
use Martis\Http\Requests\LensRequest;
use Martis\Lenses\Lens;

/*
 * Security regression: LensController::buildCacheKey() omitted the
 * authenticated user's identity. A lens whose query() scopes by the
 * current user (e.g. "my orders") would serve user A's cached result
 * set to user B, because both produced the same key for identical
 * search/sort/filter/page params. The key now includes the user id.
 */

class LensCacheKeyTestLens extends Lens
{
    public function query(LensRequest $request, Builder $query): Builder
    {
        return $query;
    }

    public function fields(Request $request): array
    {
        return [];
    }
}

function lensCacheUser(int|string $id): Authenticatable
{
    return new class($id) implements Authenticatable
    {
        public function __construct(private int|string $id) {}

        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): int|string
        {
            return $this->id;
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }

        public function getAuthPassword(): string
        {
            return '';
        }

        public function getRememberToken(): string
        {
            return '';
        }

        public function setRememberToken($value): void {}

        public function getRememberTokenName(): string
        {
            return '';
        }
    };
}

function buildLensCacheKey(Authenticatable $user): string
{
    $controller = app(LensController::class);
    $method = new ReflectionMethod($controller, 'buildCacheKey');
    $method->setAccessible(true);

    $lens = LensCacheKeyTestLens::make();
    $request = new LensRequest;
    $request->setUserResolver(fn () => $user);

    return (string) $method->invoke($controller, $lens, $request, 25, 1, '', 'v1');
}

it('scopes the lens cache key by the authenticated user', function () {
    $keyA = buildLensCacheKey(lensCacheUser(1));
    $keyB = buildLensCacheKey(lensCacheUser(2));

    expect($keyA)->not->toBe($keyB);
});

it('produces a stable key for the same user and params', function () {
    expect(buildLensCacheKey(lensCacheUser(1)))->toBe(buildLensCacheKey(lensCacheUser(1)));
});
