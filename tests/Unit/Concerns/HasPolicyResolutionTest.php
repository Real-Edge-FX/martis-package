<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Martis\Concerns\HasPolicy;
use Martis\Dashboards\Dashboard;
use Martis\Tests\Fixtures\HasPolicy\Policies\ReportsPolicy;
use Martis\Tests\TestCase;
use Martis\Tools\Tool;

uses(TestCase::class)->afterEach(function () {
    HasPolicy::flushPolicyCache();
});

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

class HasPolicyTestContext
{
    public function __construct(public string $level = 'none') {}
}

class HasPolicyTestPolicy
{
    public function __construct(private HasPolicyTestContext $context) {}

    public function view($user): bool
    {
        return $this->context->level === 'verified';
    }
}

class HasPolicyTestOtherPolicy
{
    public function view($user): bool
    {
        return true;
    }
}

class HasPolicyTestTool extends Tool
{
    public static ?string $policy = HasPolicyTestPolicy::class;

    public function __construct()
    {
        parent::__construct(name: 'Has Policy', uriKey: 'has-policy');
    }
}

class HasPolicyAllowPolicy
{
    public function view($user): bool
    {
        return true;
    }
}

class HasPolicyDenyPolicy
{
    public function view($user): bool
    {
        return false;
    }
}

class HasPolicyAllowedTool extends Tool
{
    public static ?string $policy = HasPolicyAllowPolicy::class;

    public function __construct()
    {
        parent::__construct(name: 'Allowed', uriKey: 'allowed');
    }
}

class HasPolicyDeniedTool extends Tool
{
    public static ?string $policy = HasPolicyDenyPolicy::class;

    public function __construct()
    {
        parent::__construct(name: 'Denied', uriKey: 'denied');
    }
}

/** No `$policy`: relies on the `{policy_namespace}\ReportsPolicy` convention. */
class ReportsDashboard extends Dashboard {}

/** No `$policy`, no convention match: only a host `Gate::policy()` registration can apply. */
class HasPolicyGateOnlyTool extends Tool
{
    public function __construct()
    {
        parent::__construct(name: 'Gate Only', uriKey: 'gate-only');
    }
}

function hasPolicyRequest(): Request
{
    $request = Request::create('/');
    $request->setUserResolver(fn () => (new Authenticatable)->forceFill(['id' => 1, 'name' => 'Test']));

    return $request;
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

it('resolves the instance from the container on every call', function () {
    app()->instance(HasPolicyTestContext::class, new HasPolicyTestContext);

    $first = HasPolicyTestTool::resolvePolicy();
    $second = HasPolicyTestTool::resolvePolicy();

    expect($first)->toBeInstanceOf(HasPolicyTestPolicy::class)
        ->and($second)->toBeInstanceOf(HasPolicyTestPolicy::class)
        ->and($second)->not->toBe($first);
});

it('keeps the memoised resolution until flushPolicyCache()', function () {
    app()->instance(HasPolicyTestContext::class, new HasPolicyTestContext);

    expect(HasPolicyTestTool::resolvePolicy())->toBeInstanceOf(HasPolicyTestPolicy::class);

    HasPolicyTestTool::$policy = HasPolicyTestOtherPolicy::class;

    try {
        expect(HasPolicyTestTool::resolvePolicy())->toBeInstanceOf(HasPolicyTestPolicy::class);

        HasPolicy::flushPolicyCache();

        expect(HasPolicyTestTool::resolvePolicy())->toBeInstanceOf(HasPolicyTestOtherPolicy::class);
    } finally {
        HasPolicyTestTool::$policy = HasPolicyTestPolicy::class;
    }
});

it('sees request-scoped constructor state rebound between two visibility checks', function () {
    app()->instance(HasPolicyTestContext::class, new HasPolicyTestContext('none'));
    $tool = new HasPolicyTestTool;

    expect($tool->authorizedToSee(hasPolicyRequest()))->toBeFalse();

    app()->instance(HasPolicyTestContext::class, new HasPolicyTestContext('verified'));

    expect($tool->authorizedToSee(hasPolicyRequest()))->toBeTrue();
});

it('falls back to the convention namespace and memoises a null outcome', function () {
    $tool = new class('Anonymous', 'anonymous') extends Tool {};

    expect($tool::resolvePolicy())->toBeNull()
        ->and($tool::resolvePolicy())->toBeNull();
});

// ---------------------------------------------------------------------------
// Gate registration (v1.36.0): the resolved policy must reach the Gate
// without the host registering the entity class by hand
// ---------------------------------------------------------------------------

it('lets a Tool with $policy and no Gate registration be seen when the policy allows', function () {
    expect((new HasPolicyAllowedTool)->authorizedToSee(hasPolicyRequest()))->toBeTrue();
});

it('hides a Tool with $policy and no Gate registration when the policy denies', function () {
    expect((new HasPolicyDeniedTool)->authorizedToSee(hasPolicyRequest()))->toBeFalse();
});

it('registers the resolved policy with the Gate for the entity class on the first check', function () {
    expect(Gate::policies())->not->toHaveKey(HasPolicyAllowedTool::class);

    (new HasPolicyAllowedTool)->authorizedToSee(hasPolicyRequest());

    expect(Gate::policies()[HasPolicyAllowedTool::class] ?? null)->toBe(HasPolicyAllowPolicy::class);
});

it('resolves a convention policy for a Dashboard without any registration', function () {
    config()->set('martis.policy_namespace', 'Martis\\Tests\\Fixtures\\HasPolicy\\Policies');

    $dashboard = new ReportsDashboard('Reports');

    expect(ReportsDashboard::resolvePolicy())->toBeInstanceOf(ReportsPolicy::class)
        ->and($dashboard->authorizedToSee(hasPolicyRequest()))->toBeTrue();
});

it('falls back to a policy the host registered with Gate::policy() when neither $policy nor the convention apply', function () {
    Gate::policy(HasPolicyGateOnlyTool::class, HasPolicyDenyPolicy::class);

    expect(HasPolicyGateOnlyTool::resolvePolicy())->toBeInstanceOf(HasPolicyDenyPolicy::class)
        ->and((new HasPolicyGateOnlyTool)->authorizedToSee(hasPolicyRequest()))->toBeFalse();
});

it('lets the resolved policy win over a conflicting host registration, like Resources do', function () {
    Gate::policy(HasPolicyAllowedTool::class, HasPolicyDenyPolicy::class);

    expect((new HasPolicyAllowedTool)->authorizedToSee(hasPolicyRequest()))->toBeTrue()
        ->and(Gate::policies()[HasPolicyAllowedTool::class] ?? null)->toBe(HasPolicyAllowPolicy::class);
});

it('keeps Gate::before() in the pipeline', function () {
    Gate::before(fn () => true);

    expect((new HasPolicyDeniedTool)->authorizedToSee(hasPolicyRequest()))->toBeTrue();
});
