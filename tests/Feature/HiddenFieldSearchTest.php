<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as EloquentBelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Martis\Fields\BelongsTo;
use Martis\Fields\BelongsToMany;
use Martis\Fields\HasMany;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Layout\Section;
use Martis\Resource;
use Martis\ResourceRegistry;

// ===========================================================================
// Searching by a field the user cannot see (v1.38.0).
//
// The search of the resource index, of the relationship panels and their
// attach picker, of the global search and of a relation picker matched the
// columns of every searchable() field, those the user cannot see (canSee())
// included, so the rows a term returned told which records hold it in a
// field the user may not read; a `field:value` token could name such a
// field too. Only the searchable fields the user can see are searched now.
// ===========================================================================

class HSRClient extends Model
{
    protected $table = 'hsr_clients';

    protected $guarded = [];

    public $timestamps = false;
}

class HSRAgency extends Model
{
    protected $table = 'hsr_agencies';

    protected $guarded = [];

    public $timestamps = false;

    public function clients(): EloquentHasMany
    {
        return $this->hasMany(HSRClient::class, 'agency_id');
    }

    public function partners(): EloquentBelongsToMany
    {
        return $this->belongsToMany(HSRClient::class, 'hsr_partners', 'agency_id', 'client_id');
    }
}

class HSRProject extends Model
{
    protected $table = 'hsr_projects';

    protected $guarded = [];

    public $timestamps = false;
}

/** A searchable name, and a searchable code the user cannot see (in a Section). */
class HSRClientResource extends Resource
{
    public static function model(): string
    {
        return HSRClient::class;
    }

    public static function uriKey(): string
    {
        return 'hsr-clients';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name')->searchable(),
            Section::make('Vault', [
                Text::make('secret_code')->searchable()->canSee(fn () => false),
            ]),
        ];
    }
}

/** The clients again, searchable by the code the user cannot see only. */
class HSRVaultResource extends HSRClientResource
{
    public static function uriKey(): string
    {
        return 'hsr-vaults';
    }

    public static function globallySearchable(): bool|array
    {
        return false;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            Text::make('secret_code')->searchable()->canSee(fn () => false),
        ];
    }
}

class HSRAgencyResource extends Resource
{
    public static function model(): string
    {
        return HSRAgency::class;
    }

    public static function uriKey(): string
    {
        return 'hsr-agencies';
    }

    public static function globallySearchable(): bool|array
    {
        return false;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            HasMany::make('Clients', 'clients')->relatedResource('hsr-clients'),
            BelongsToMany::make('Partners', 'partners')->relatedResource('hsr-clients'),
        ];
    }
}

class HSRProjectResource extends Resource
{
    public static function model(): string
    {
        return HSRProject::class;
    }

    public static function uriKey(): string
    {
        return 'hsr-projects';
    }

    public static function globallySearchable(): bool|array
    {
        return false;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name')->nullable(),
            BelongsTo::make('client', 'Client')->relatedResource('hsr-clients')->nullable(),
            BelongsTo::make('vault', 'Vault')->relatedResource('hsr-vaults')->nullable(),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    foreach (['hsr_partners', 'hsr_projects', 'hsr_clients', 'hsr_agencies'] as $table) {
        Schema::dropIfExists($table);
    }
    Schema::create('hsr_agencies', function ($table) {
        $table->id();
        $table->string('name');
    });
    Schema::create('hsr_clients', function ($table) {
        $table->id();
        $table->unsignedBigInteger('agency_id')->nullable();
        $table->string('name');
        $table->string('secret_code');
    });
    Schema::create('hsr_partners', function ($table) {
        $table->unsignedBigInteger('agency_id');
        $table->unsignedBigInteger('client_id');
    });
    Schema::create('hsr_projects', function ($table) {
        $table->id();
        $table->string('name')->nullable();
        $table->unsignedBigInteger('client_id')->nullable();
        $table->unsignedBigInteger('vault_id')->nullable();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(HSRClientResource::class);
    $registry->register(HSRVaultResource::class);
    $registry->register(HSRAgencyResource::class);
    $registry->register(HSRProjectResource::class);

    $this->agency = HSRAgency::create(['name' => 'Agency']);
    HSRClient::create(['agency_id' => $this->agency->id, 'name' => 'Acme', 'secret_code' => 'zeta-1']);
    HSRClient::create(['agency_id' => $this->agency->id, 'name' => 'Beta', 'secret_code' => 'omega-2']);
});

afterEach(function () {
    foreach (['hsr_partners', 'hsr_projects', 'hsr_clients', 'hsr_agencies'] as $table) {
        Schema::dropIfExists($table);
    }
});

/** @return list<string> */
function hsrNames(TestResponse $response): array
{
    return array_column($response->assertOk()->json('data'), 'name');
}

$searches = [
    'resource index' => '/martis/api/resources/hsr-clients?',
    'HasMany panel' => '/martis/api/resources/hsr-agencies/{agency}/has-many/clients?',
    'BelongsToMany attach picker' => '/martis/api/resources/hsr-agencies/{agency}/belongs-to-many/partners/attachable?',
    'relation picker' => '/martis/api/resources/hsr-projects/_/relatable/client_id?',
];

it('does not match a term on a searchable field the user cannot see', function (string $uri) {
    $uri = str_replace('{agency}', (string) $this->agency->id, $uri);

    expect(hsrNames($this->getJson("{$uri}search=zeta")))->toBe([]);
})->with($searches);

it('matches a term on a searchable field the user can see', function (string $uri) {
    $uri = str_replace('{agency}', (string) $this->agency->id, $uri);

    expect(hsrNames($this->getJson("{$uri}search=acm")))->toBe(['Acme']);
})->with($searches);

it('drops a field:value token that names a searchable field the user cannot see', function () {
    expect(hsrNames($this->getJson('/martis/api/resources/hsr-clients?search=secret_code:zeta')))->toBe([])
        ->and(hsrNames($this->getJson('/martis/api/resources/hsr-clients?search=secret_code:zeta%20be')))->toBe(['Beta'])
        ->and(hsrNames($this->getJson('/martis/api/resources/hsr-clients?search=name:acme')))->toBe(['Acme']);
});

it('does not match the global search on a searchable field the user cannot see', function () {
    $groups = fn (string $term): array => collect($this->getJson('/martis/api/search?q='.$term)->assertOk()->json('results'))
        ->mapWithKeys(fn (array $group): array => [$group['resource'] => array_column($group['items'], 'title')])
        ->all();

    expect($groups('zeta'))->toBe([])
        ->and($groups('acm'))->toBe(['hsr-clients' => ['Acme']]);
});

it('searches the title of a relation picker whose searchable fields the user cannot see', function () {
    $uri = '/martis/api/resources/hsr-projects/_/relatable/vault_id?search=';

    expect(hsrNames($this->getJson($uri.'zeta')))->toBe([])
        ->and(hsrNames($this->getJson($uri.'acm')))->toBe(['Acme']);
});
