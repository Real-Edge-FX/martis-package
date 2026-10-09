<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Martis\Contracts\FieldContract;
use Martis\Fields\BelongsTo;
use Martis\Fields\MorphTo;
use Martis\Fields\Text;
use Martis\Http\Resources\DatabaseErrorResponse;

// ===========================================================================
// DatabaseErrorResponse maps a refused write to a sanitized JSON answer
// (v2.9.0). A unique violation is a 422 naming the written fields the index
// covers, read from each driver's message:
//
//   - PostgreSQL printed an expression index's key as the expression
//     (`Key (lower(custom_domain::text))=(…)`), and the old regex stopped at
//     its first `)`: the 422 named no field (consumer report 2026-10-09).
//   - MySQL's index name was cut on its last `_` word (`first_name` became
//     `name`), and a composite key became the string `a, b`.
//   - SQLite reports every constraint as 23000 / 19, so a NOT NULL or foreign
//     key failure answered "already exists".
//   - The relationship controllers answered a unique violation with a 500.
// ===========================================================================

class DerSiteModel extends Model
{
    protected $table = 'agency_sites';
}

/**
 * A QueryException as the driver raises it: `$unique` as Laravel's
 * UniqueConstraintViolationException, `$errorInfo` as PDO fills it.
 *
 * @param  array{0: string, 1: int|string|null, 2: string}  $errorInfo
 */
function derException(array $errorInfo, bool $unique = false, string $driver = 'pgsql'): QueryException
{
    $pdo = new PDOException("SQLSTATE[{$errorInfo[0]}]: {$errorInfo[2]}");
    $pdo->errorInfo = $errorInfo;

    return $unique
        ? new UniqueConstraintViolationException($driver, 'insert into "agency_sites" ...', [], $pdo)
        : new QueryException($driver, 'insert into "agency_sites" ...', [], $pdo);
}

function derPostgresUnique(string $constraint, string $key, string $value = 'dup.example.com'): QueryException
{
    return derException([
        '23505',
        7,
        "ERROR:  duplicate key value violates unique constraint \"{$constraint}\"\nDETAIL:  Key ({$key})=({$value}) already exists.",
    ], unique: true);
}

function derMysqlUnique(string $key): QueryException
{
    return derException(['23000', 1062, "Duplicate entry 'dup.example.com' for key '{$key}'"], unique: true, driver: 'mysql');
}

function derSqliteUnique(string $failed): QueryException
{
    return derException(['23000', 19, "UNIQUE constraint failed: {$failed}"], unique: true, driver: 'sqlite');
}

/**
 * @param  list<FieldContract>  $fields
 * @return array{status: int, message: string, errors: array<string, string>}
 */
function derRespond(QueryException $e, array $fields = [], ?Model $model = null): array
{
    $response = DatabaseErrorResponse::from($e, $fields, $model);
    $body = $response->getData(true);

    $errors = [];
    foreach ($body['errors'] as $error) {
        $errors[$error['field']] = $error['message'];
    }

    return ['status' => $response->getStatusCode(), 'message' => $body['message'], 'errors' => $errors];
}

// ---------------------------------------------------------------------------
// PostgreSQL
// ---------------------------------------------------------------------------

it('maps a PostgreSQL expression index to the column inside the expression', function () {
    $result = derRespond(
        derPostgresUnique('agency_sites_custom_domain_lower_unique', 'lower(custom_domain::text)'),
        [Text::make('name'), Text::make('custom_domain')],
        new DerSiteModel,
    );

    expect($result['status'])->toBe(422)
        ->and($result['message'])->toBe(DatabaseErrorResponse::UNIQUE_MESSAGE)
        ->and($result['errors'])->toBe(['custom_domain' => 'The Custom Domain has already been taken.']);
});

it('maps a plain PostgreSQL column key', function () {
    $result = derRespond(derPostgresUnique('users_email_unique', 'email', 'a@b.c'), [Text::make('email')]);

    expect($result['errors'])->toBe(['email' => 'The Email has already been taken.']);
});

it('reports every written field of a composite PostgreSQL key, and only those', function () {
    $e = derPostgresUnique('agency_sites_tenant_id_slug_unique', 'tenant_id, slug', '1, home');

    expect(derRespond($e, [Text::make('slug')])['errors'])->toBe(['slug' => 'The Slug has already been taken.'])
        ->and(array_keys(derRespond($e, [Text::make('slug'), Text::make('tenant_id')])['errors']))->toBe(['tenant_id', 'slug']);
});

it('never reads a function name, a cast type or a string literal as a column', function () {
    $e = derPostgresUnique('agency_sites_x_unique', "COALESCE(lower(email::text), 'name'::character varying)");

    $result = derRespond($e, [Text::make('lower'), Text::make('text'), Text::make('name'), Text::make('email')]);

    expect($result['errors'])->toBe(['email' => 'The Email has already been taken.']);
});

it('reads a quoted PostgreSQL identifier', function () {
    $result = derRespond(derPostgresUnique('agency_sites_custom_domain_unique', '"customDomain"'), [Text::make('customDomain')]);

    expect(array_keys($result['errors']))->toBe(['customDomain']);
});

it('reads the key of a localised PostgreSQL message by its shape', function () {
    $e = derException([
        '23505',
        7,
        "ERRO:  duplicar valor da chave viola a restrição de unicidade \"users_email_unique\"\nDETALHE:  Chave (email)=(a@b.c) já existe.",
    ], unique: true);

    expect(array_keys(derRespond($e, [Text::make('email')])['errors']))->toBe(['email']);
});

it('falls back to the PostgreSQL constraint name when the key names no written field', function () {
    $e = derPostgresUnique('agency_sites_custom_domain_lower_unique', 'md5(payload)');

    $result = derRespond($e, [Text::make('custom_domain')], new DerSiteModel);

    expect(array_keys($result['errors']))->toBe(['custom_domain']);
});

it('answers a 422 without field errors when nothing maps to a written field', function () {
    $result = derRespond(derPostgresUnique('agency_sites_custom_domain_lower_unique', 'lower(custom_domain::text)'), [Text::make('name')]);

    expect($result['status'])->toBe(422)
        ->and($result['message'])->toBe(DatabaseErrorResponse::UNIQUE_MESSAGE)
        ->and($result['errors'])->toBe([]);
});

it('answers a 422 without field errors when there are no fields', function () {
    $result = derRespond(derPostgresUnique('users_email_unique', 'email'));

    expect($result['status'])->toBe(422)
        ->and($result['errors'])->toBe([]);
});

// ---------------------------------------------------------------------------
// MySQL / MariaDB
// ---------------------------------------------------------------------------

it('maps a MySQL index name qualified with its table', function () {
    $result = derRespond(
        derMysqlUnique('agency_sites.agency_sites_custom_domain_lower_unique'),
        [Text::make('custom_domain')],
        new DerSiteModel,
    );

    expect($result['errors'])->toBe(['custom_domain' => 'The Custom Domain has already been taken.']);
});

it('keeps a column with underscores whole', function () {
    $result = derRespond(derMysqlUnique('users_first_name_unique'), [Text::make('name'), Text::make('first_name')]);

    expect(array_keys($result['errors']))->toBe(['first_name']);
});

it('splits a composite MySQL index name on the longest known columns', function () {
    $result = derRespond(
        derMysqlUnique('agency_sites_user_id_slug_unique'),
        [Text::make('id'), BelongsTo::make('user'), Text::make('slug')],
        new DerSiteModel,
    );

    expect(array_keys($result['errors']))->toBe(['user_id', 'slug']);
});

it('maps MySQL PRIMARY to the record key', function () {
    $result = derRespond(derMysqlUnique('agency_sites.PRIMARY'), [Text::make('id')], new DerSiteModel);

    expect(array_keys($result['errors']))->toBe(['id']);
});

it('answers a 422 without field errors for an index name that names no written field', function () {
    $result = derRespond(derMysqlUnique('uniq_domain'), [Text::make('custom_domain')], new DerSiteModel);

    expect($result['status'])->toBe(422)
        ->and($result['errors'])->toBe([]);
});

// ---------------------------------------------------------------------------
// SQLite
// ---------------------------------------------------------------------------

it('maps the qualified columns SQLite names', function () {
    $result = derRespond(derSqliteUnique('agency_sites.tenant_id, agency_sites.slug'), [Text::make('slug'), Text::make('tenant_id')]);

    expect(array_keys($result['errors']))->toBe(['tenant_id', 'slug']);
});

it('maps a SQLite expression index by its name', function () {
    $result = derRespond(
        derSqliteUnique("index 'agency_sites_custom_domain_lower_unique'"),
        [Text::make('custom_domain')],
        new DerSiteModel,
    );

    expect(array_keys($result['errors']))->toBe(['custom_domain']);
});

it('no longer reads a SQLite NOT NULL or foreign key failure as a unique violation', function () {
    $notNull = derRespond(derException(['23000', 19, 'NOT NULL constraint failed: agency_sites.name'], driver: 'sqlite'), [Text::make('name')]);
    $foreignKey = derRespond(derException(['23000', 19, 'FOREIGN KEY constraint failed'], driver: 'sqlite'));

    expect($notNull['status'])->toBe(500)
        ->and($notNull['message'])->toBe(DatabaseErrorResponse::NOT_NULL_MESSAGE)
        ->and($foreignKey['status'])->toBe(500)
        ->and($foreignKey['message'])->toBe(DatabaseErrorResponse::MISSING_REFERENCE_MESSAGE);
});

// ---------------------------------------------------------------------------
// Fields and messages
// ---------------------------------------------------------------------------

it('reports a MorphTo field under its attribute for either column', function () {
    $result = derRespond(derPostgresUnique('comments_commentable_type_commentable_id_unique', 'commentable_type, commentable_id', 'App\Post, 1'), [MorphTo::make('commentable')]);

    expect(array_keys($result['errors']))->toBe(['commentable']);
});

it('leaves a JSON path attribute out', function () {
    $result = derRespond(derPostgresUnique('agency_sites_meta_unique', "(meta ->> 'email'::text)"), [Text::make('meta->email')]);

    expect($result['errors'])->toBe([]);
});

it('uses the custom unique() message of the field', function () {
    $field = Text::make('custom_domain')->unique(['agency_sites'], 'That domain is taken.');

    $result = derRespond(derPostgresUnique('agency_sites_custom_domain_lower_unique', 'lower(custom_domain::text)'), [$field]);

    expect($result['errors'])->toBe(['custom_domain' => 'That domain is taken.']);
});

it('detects a unique violation by its code when the exception is a plain QueryException', function () {
    $postgres = derException(['23505', 7, 'DETAIL:  Key (email)=(a@b.c) already exists.']);
    $mysql = derException(['23000', 1062, "Duplicate entry 'a@b.c' for key 'users_email_unique'"], driver: 'mysql');

    expect(derRespond($postgres, [Text::make('email')])['errors'])->toHaveKey('email')
        ->and(derRespond($mysql, [Text::make('email')])['errors'])->toHaveKey('email');
});

// ---------------------------------------------------------------------------
// Other constraint errors
// ---------------------------------------------------------------------------

it('maps the other constraint errors to a sanitized 500', function (array $errorInfo, string $message) {
    $result = derRespond(derException($errorInfo), [Text::make('name')]);

    expect($result['status'])->toBe(500)
        ->and($result['message'])->toBe($message)
        ->and($result['errors'])->toBe([]);
})->with([
    'mysql row referenced' => [['23000', 1451, 'Cannot delete or update a parent row'], DatabaseErrorResponse::REFERENCED_MESSAGE],
    'mysql missing reference' => [['23000', 1452, 'Cannot add or update a child row'], DatabaseErrorResponse::MISSING_REFERENCE_MESSAGE],
    'postgres row referenced' => [['23503', 7, "ERROR:  update or delete on table \"users\" violates foreign key constraint\nDETAIL:  Key (id)=(1) is still referenced from table \"posts\"."], DatabaseErrorResponse::REFERENCED_MESSAGE],
    'postgres missing reference' => [['23503', 7, "ERROR:  insert or update on table \"posts\" violates foreign key constraint\nDETAIL:  Key (user_id)=(9) is not present in table \"users\"."], DatabaseErrorResponse::MISSING_REFERENCE_MESSAGE],
    'mysql not null' => [['23000', 1048, "Column 'name' cannot be null"], DatabaseErrorResponse::NOT_NULL_MESSAGE],
    'postgres not null' => [['23502', 7, 'ERROR:  null value in column "name" violates not-null constraint'], DatabaseErrorResponse::NOT_NULL_MESSAGE],
    'anything else' => [['42S02', 1146, "Table 'x' doesn't exist"], DatabaseErrorResponse::GENERIC_MESSAGE],
]);

it('never leaks the SQL or the driver message', function () {
    $response = DatabaseErrorResponse::from(derException(['42S02', 1146, "Table 'secret_table' doesn't exist"], driver: 'mysql'));

    expect((string) $response->getContent())->not->toContain('secret_table')
        ->and((string) $response->getContent())->not->toContain('insert into');
});
