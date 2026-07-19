<?php

declare(strict_types=1);

use Martis\Console\RolesScaffoldCommand;

/*
 * Pure-function coverage for the HasRoles-trait patcher extracted from
 * `RolesScaffoldCommand::patchUserModel()`.
 *
 * These exercise `applyHasRolesTrait()` / `classBodyUsesHasRolesTrait()`
 * directly against several real User-model shapes — no filesystem, no
 * Spatie, no full command boot. They pin the regression that shipped
 * the trait IMPORT without the class-body `use …HasRoles…;` on the
 * Laravel-12 default model (a docblock between the class brace and the
 * first trait-use line silently defeated the old class-body regex).
 */

/**
 * Return the substring after the class opening brace — the "class body"
 * that must carry the trait-use statement (imports live above the brace
 * and must NOT count as applying the trait).
 */
function classBodyOf(string $contents): string
{
    if (! preg_match('/class\s+\w+[^{]*\{(.*)$/s', $contents, $m)) {
        throw new RuntimeException('no class body found in: '.$contents);
    }

    return $m[1];
}

/** Assert the class body carries a `use …HasRoles…;` trait statement. */
function assertBodyAppliesHasRoles(string $contents): void
{
    $body = classBodyOf($contents);
    expect(preg_match('/\buse\s+[^;]*HasRoles[^;]*;/', $body))->toBe(1);
    expect(RolesScaffoldCommand::classBodyUsesHasRolesTrait($contents))->toBeTrue();
}

it('applies HasRoles into the class body on the Laravel 12 default shape (docblock before the trait-use)', function () {
    $contents = <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password'];
}
PHP;

    // The docblock defeats the OLD class-body regex; before the fix the
    // class body kept only `use HasFactory, Notifiable;` (import-only).
    expect(RolesScaffoldCommand::classBodyUsesHasRolesTrait($contents))->toBeFalse();

    $result = RolesScaffoldCommand::applyHasRolesTrait($contents);
    expect($result)->not->toBeNull();
    /** @var string $result */

    // The trait is genuinely in the CLASS BODY, not merely imported.
    assertBodyAppliesHasRoles($result);

    // And the import was added at the top.
    expect($result)->toContain('use Spatie\\Permission\\Traits\\HasRoles;');

    // Guard against the exact silent-failure: HasRoles must NOT appear
    // only in the import line — the class body must reference it too.
    $body = classBodyOf($result);
    expect($body)->toContain('HasRoles');
});

it('applies HasRoles into a single-trait class body without a docblock', function () {
    $contents = <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;
}
PHP;

    $result = RolesScaffoldCommand::applyHasRolesTrait($contents);
    expect($result)->not->toBeNull();
    /** @var string $result */
    assertBodyAppliesHasRoles($result);

    // Existing trait preserved in the same trait-use statement.
    $body = classBodyOf($result);
    expect(preg_match('/\buse\s+[^;]*Notifiable[^;]*HasRoles[^;]*;|\buse\s+[^;]*HasRoles[^;]*Notifiable[^;]*;/', $body))->toBe(1);
});

it('adds a fresh use HasRoles; when the class has no trait-use at all', function () {
    $contents = <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
}
PHP;

    expect(RolesScaffoldCommand::classBodyUsesHasRolesTrait($contents))->toBeFalse();

    $result = RolesScaffoldCommand::applyHasRolesTrait($contents);
    expect($result)->not->toBeNull();
    /** @var string $result */
    assertBodyAppliesHasRoles($result);
    expect(classBodyOf($result))->toContain('use HasRoles;');
});

it('treats an already-applied class body as satisfied and stays idempotent', function () {
    $contents = <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, HasRoles, Notifiable;
}
PHP;

    // Already applied — patchUserModel would skip on this.
    expect(RolesScaffoldCommand::classBodyUsesHasRolesTrait($contents))->toBeTrue();

    // Re-applying must not double-add HasRoles in the trait list.
    $result = RolesScaffoldCommand::applyHasRolesTrait($contents);
    expect($result)->not->toBeNull();
    /** @var string $result */
    assertBodyAppliesHasRoles($result);

    $body = classBodyOf($result);
    expect(substr_count($body, 'HasRoles'))->toBe(1);

    // Idempotent: applying twice yields an identical result.
    expect(RolesScaffoldCommand::applyHasRolesTrait($result))->toBe($result);
});

it('does not count a bare import as applying the trait, and patches without duplicating the import', function () {
    // The exact silent-failure state: HasRoles is IMPORTED but the class
    // body trait-use does NOT include it.
    $contents = <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, Notifiable;
}
PHP;

    // Import present, but the trait is NOT applied in the body.
    expect(RolesScaffoldCommand::classBodyUsesHasRolesTrait($contents))->toBeFalse();

    $result = RolesScaffoldCommand::applyHasRolesTrait($contents);
    expect($result)->not->toBeNull();
    /** @var string $result */
    assertBodyAppliesHasRoles($result);

    // The pre-existing import must not be duplicated.
    expect(substr_count($result, 'use Spatie\\Permission\\Traits\\HasRoles;'))->toBe(1);
});
