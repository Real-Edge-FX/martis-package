<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Martis\Fields\Text;
use Martis\Resource;

/**
 * Regression guard for the static policy cache removed in v1.36.0.
 *
 * Every test boots its own application, but the PHP process is shared.
 * A policy instance memoised in a static array by the first test kept the
 * first application's `LifecycleTenantContext` (level "verified") and the
 * second test, whose own context says "none", was still authorised.
 * The two tests below must run in this order and in one process, without
 * any `flushPolicyCache()` call, and the second one must be denied.
 */
class LifecycleTenantContext
{
    public function __construct(public string $level = 'none') {}
}

class LifecycleTenantPolicy
{
    public function __construct(private LifecycleTenantContext $tenant) {}

    public function viewAny($user): bool
    {
        return $this->tenant->level === 'verified';
    }
}

class LifecycleModel extends Model
{
    protected $table = 'users';

    protected $guarded = [];
}

class LifecycleResource extends Resource
{
    public static ?string $policy = LifecycleTenantPolicy::class;

    public static function model(): string
    {
        return LifecycleModel::class;
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

function lifecycleRequest(): Request
{
    $request = Request::create('/');
    $request->setUserResolver(fn () => (new Authenticatable)->forceFill(['id' => 1, 'name' => 'Test']));

    return $request;
}

it('authorises a request whose tenant context is verified', function () {
    app()->instance(LifecycleTenantContext::class, new LifecycleTenantContext('verified'));

    expect((new LifecycleResource)->authorizedToViewAny(lifecycleRequest()))->toBeTrue();
});

it('denies the next application instance whose tenant context is not verified', function () {
    app()->instance(LifecycleTenantContext::class, new LifecycleTenantContext('none'));

    expect((new LifecycleResource)->authorizedToViewAny(lifecycleRequest()))->toBeFalse();
});
