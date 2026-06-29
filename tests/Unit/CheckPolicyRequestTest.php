<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Martis\Resource;

/**
 * Regression guard: checkPolicy()/checkRelationalPolicy() must authorize
 * against the Request passed into authorizedTo*(), not the global request()
 * helper. The old code read request()->user(), which is the wrong (or null)
 * user in queue/job/sub-request contexts.
 */
class CheckPolicyUser extends Authenticatable
{
    protected $guarded = [];

    protected $table = 'users';
}

class CheckPolicyModel extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected $table = 'cp_models';
}

class CheckPolicyPolicy
{
    public function view($user, $model): bool
    {
        return (int) ($user->id ?? 0) === 42;
    }
}

class CheckPolicyResource extends Resource
{
    public static ?string $policy = CheckPolicyPolicy::class;

    public static function model(): string
    {
        return CheckPolicyModel::class;
    }

    public function fields(Request $request): array
    {
        return [];
    }
}

function checkPolicyRequestFor(int $userId): Request
{
    $user = new CheckPolicyUser;
    $user->id = $userId;

    $request = Request::create('/');
    $request->setUserResolver(fn () => $user);

    return $request;
}

it('authorizes against the passed request user, not the global helper', function () {
    // No global request bound → the old request()->user() would be null and
    // checkPolicy would deny regardless of the passed request.
    $resource = new CheckPolicyResource(new CheckPolicyModel(['id' => 1]));

    expect($resource->authorizedToView(checkPolicyRequestFor(42)))->toBeTrue()
        ->and($resource->authorizedToView(checkPolicyRequestFor(7)))->toBeFalse();
});
