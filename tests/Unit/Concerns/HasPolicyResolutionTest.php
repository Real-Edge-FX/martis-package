<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Martis\Concerns\HasPolicy;
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
    // HasPolicy routes the ability through the Gate, which needs the
    // policy registered for the entity class.
    Gate::policy(HasPolicyTestTool::class, HasPolicyTestPolicy::class);
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
