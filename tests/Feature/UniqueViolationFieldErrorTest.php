<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Martis\Fields\HasMany;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Http\Resources\DatabaseErrorResponse;
use Martis\Resource;
use Martis\ResourceRegistry;

// ===========================================================================
// A unique index the field rules do not cover (a race past `unique()`, or a
// case-insensitive expression index) answers the write with a 422 on the
// fields it covers, through the resource endpoints and the relationship
// panels alike (v2.9.0, consumer report 2026-10-09). Before, an expression
// index left the 422 without field errors and a relationship panel answered
// a 500.
// ===========================================================================

class UvfAgencyModel extends Model
{
    protected $table = 'uvf_agencies';

    protected $guarded = [];

    public $timestamps = false;

    public function sites(): EloquentHasMany
    {
        return $this->hasMany(UvfSiteModel::class, 'agency_id');
    }
}

class UvfSiteModel extends Model
{
    protected $table = 'uvf_sites';

    protected $guarded = [];

    public $timestamps = false;
}

class UvfSiteResource extends Resource
{
    public static function model(): string
    {
        return UvfSiteModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name')->nullable(),
            // No unique() rule: the database index is the only guard.
            Text::make('custom_domain')->nullable(),
            Text::make('slug')->nullable(),
        ];
    }
}

class UvfAgencyResource extends Resource
{
    public static function model(): string
    {
        return UvfAgencyModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name')->nullable(),
            HasMany::make('Sites', 'sites')->relatedResource('uvf-site-models'),
        ];
    }
}

/**
 * @return array<string, string>
 */
function uvfFieldErrors(TestResponse $response): array
{
    $errors = [];
    foreach ($response->json('errors') as $error) {
        $errors[$error['field']] = $error['message'];
    }

    return $errors;
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('uvf_sites');
    Schema::dropIfExists('uvf_agencies');

    Schema::create('uvf_agencies', function ($table) {
        $table->id();
        $table->string('name')->nullable();
    });
    Schema::create('uvf_sites', function ($table) {
        $table->id();
        $table->unsignedBigInteger('agency_id')->nullable();
        $table->string('name');
        $table->string('custom_domain')->nullable();
        $table->string('slug')->nullable()->unique();
    });
    // The consumer's index: case-insensitive, partial.
    DB::statement('CREATE UNIQUE INDEX uvf_sites_custom_domain_lower_unique ON uvf_sites (lower(custom_domain)) WHERE custom_domain IS NOT NULL');

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(UvfSiteResource::class);
    $registry->register(UvfAgencyResource::class);
});

afterEach(function () {
    Schema::dropIfExists('uvf_sites');
    Schema::dropIfExists('uvf_agencies');
});

it('answers a create that hits an expression index with a 422 on the field', function () {
    UvfSiteModel::create(['name' => 'First', 'custom_domain' => 'dup.example.com']);

    $response = $this->postJson('/martis/api/resources/uvf-site-models', [
        'name' => 'Second',
        'custom_domain' => 'DUP.example.com',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('message', DatabaseErrorResponse::UNIQUE_MESSAGE);
    expect(uvfFieldErrors($response))->toBe(['custom_domain' => 'The Custom Domain has already been taken.'])
        ->and(UvfSiteModel::count())->toBe(1);
});

it('answers an update that hits an expression index with a 422 on the field', function () {
    UvfSiteModel::create(['name' => 'First', 'custom_domain' => 'dup.example.com']);
    $second = UvfSiteModel::create(['name' => 'Second', 'custom_domain' => 'other.example.com']);

    $response = $this->putJson("/martis/api/resources/uvf-site-models/{$second->id}", [
        'name' => 'Second',
        'custom_domain' => 'DUP.example.com',
    ]);

    $response->assertStatus(422);
    expect(uvfFieldErrors($response))->toHaveKey('custom_domain')
        ->and($second->fresh()->custom_domain)->toBe('other.example.com');
});

it('still maps a plain column unique index', function () {
    UvfSiteModel::create(['name' => 'First', 'slug' => 'home']);

    $response = $this->postJson('/martis/api/resources/uvf-site-models', ['name' => 'Second', 'slug' => 'home']);

    $response->assertStatus(422);
    expect(uvfFieldErrors($response))->toBe(['slug' => 'The Slug has already been taken.']);
});

it('answers a NOT NULL failure with its own message, not a unique violation', function () {
    // `name` is nullable() for the form but NOT NULL in the table.
    $response = $this->postJson('/martis/api/resources/uvf-site-models', ['custom_domain' => 'a.example.com']);

    $response->assertStatus(500)
        ->assertJsonPath('message', DatabaseErrorResponse::NOT_NULL_MESSAGE)
        ->assertJsonPath('errors', []);
});

it('answers a relationship panel create that hits a unique index with a 422 on the field', function () {
    $agency = UvfAgencyModel::create(['name' => 'Acme']);
    UvfSiteModel::create(['name' => 'First', 'custom_domain' => 'dup.example.com']);

    $response = $this->postJson("/martis/api/resources/uvf-agency-models/{$agency->id}/has-many/sites", [
        'name' => 'Second',
        'custom_domain' => 'Dup.Example.com',
    ]);

    $response->assertStatus(422);
    expect(uvfFieldErrors($response))->toBe(['custom_domain' => 'The Custom Domain has already been taken.'])
        ->and($agency->sites()->count())->toBe(0);
});
