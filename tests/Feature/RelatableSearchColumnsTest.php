<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\BelongsTo;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// ---------------------------------------------------------------------------
// Fixtures: a picker target that declares NO searchable field
// ---------------------------------------------------------------------------

class RscItem extends Model
{
    protected $table = 'rsc_items';

    protected $fillable = ['name', 'code'];

    public function getLabelAttribute(): string
    {
        return strtoupper((string) $this->name);
    }
}

class RscOwner extends Model
{
    protected $table = 'rsc_owners';

    protected $fillable = ['title'];
}

abstract class RscItemResource extends Resource
{
    public static function model(): string
    {
        return RscItem::class;
    }

    /** @return list<class-string<resource>> */
    public function fields(Request $request): array
    {
        return [Text::make('name'), Text::make('code')];
    }

    public static function relatableQuery(Request $request, Builder $query): Builder
    {
        return $query;
    }
}

/** Declares the columns the picker searches, several of them. */
class RscDeclaredColumnsResource extends RscItemResource
{
    public static function uriKey(): string
    {
        return 'rsc-declared';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public static function relatableSearchColumns(): array
    {
        return ['name', 'code'];
    }
}

/** A real-column title and nothing else: the pre-existing fallback. */
class RscTitleColumnResource extends RscItemResource
{
    public static function uriKey(): string
    {
        return 'rsc-title-column';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }
}

/** A title read from an accessor, which is no column. */
class RscAccessorResource extends RscItemResource
{
    public static function uriKey(): string
    {
        return 'rsc-accessor';
    }

    public static function titleAttribute(): string
    {
        return 'label';
    }
}

/** The default `id` title attribute, with an overridden `title()`. */
class RscIdTitleResource extends RscItemResource
{
    public static function uriKey(): string
    {
        return 'rsc-id-title';
    }

    public function title(): string
    {
        return 'Item '.$this->model?->getKey();
    }
}

/** Same, but declaring the columns to search. */
class RscIdTitleColumnsResource extends RscIdTitleResource
{
    public static function uriKey(): string
    {
        return 'rsc-id-title-columns';
    }

    public static function relatableSearchColumns(): array
    {
        return ['code'];
    }
}

/** Declares a searchable field: `relatableSearchColumns()` must be ignored. */
class RscSearchableResource extends RscItemResource
{
    public static function uriKey(): string
    {
        return 'rsc-searchable';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')->searchable(), Text::make('code')];
    }

    public static function relatableSearchColumns(): array
    {
        return ['code'];
    }
}

class RscOwnerResource extends Resource
{
    public static function model(): string
    {
        return RscOwner::class;
    }

    public static function uriKey(): string
    {
        return 'rsc-owners';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title'),
            BelongsTo::make('declared_id', 'Declared')->relatedResource('rsc-declared')->nullable(),
            BelongsTo::make('title_column_id', 'Title column')->relatedResource('rsc-title-column')->nullable(),
            BelongsTo::make('accessor_id', 'Accessor')->relatedResource('rsc-accessor')->nullable(),
            BelongsTo::make('id_title_id', 'Id title')->relatedResource('rsc-id-title')->nullable(),
            BelongsTo::make('id_title_columns_id', 'Id title columns')->relatedResource('rsc-id-title-columns')->nullable(),
            BelongsTo::make('searchable_id', 'Searchable')->relatedResource('rsc-searchable')->nullable(),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::create('rsc_items', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('code');
        $table->timestamps();
    });

    Schema::create('rsc_owners', function (Blueprint $table) {
        $table->id();
        $table->string('title')->nullable();
        $table->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    foreach ([
        RscOwnerResource::class,
        RscDeclaredColumnsResource::class,
        RscTitleColumnResource::class,
        RscAccessorResource::class,
        RscIdTitleResource::class,
        RscIdTitleColumnsResource::class,
        RscSearchableResource::class,
    ] as $class) {
        $registry->register($class);
    }

    RscItem::create(['name' => 'Alpha', 'code' => 'ZX-1']);
    RscItem::create(['name' => 'Bravo', 'code' => 'QQ-2']);
    RscItem::create(['name' => 'Charlie', 'code' => 'ZX-3']);

    RscOwner::create(['title' => 'Owner']);
});

afterEach(function () {
    Schema::dropIfExists('rsc_owners');
    Schema::dropIfExists('rsc_items');

    app(ResourceRegistry::class)->flush();
});

/** The picker names the term returns, in order. */
function rscPicker(string $field, ?string $search = null): array
{
    $url = '/martis/api/resources/rsc-owners/1/relatable/'.$field.($search !== null ? '?search='.urlencode($search) : '');
    $response = test()->getJson($url)->assertOk();

    return collect($response->json('data'))->pluck('name')->sort()->values()->all();
}

// ---------------------------------------------------------------------------
// 1. relatableSearchColumns() decides the filter
// ---------------------------------------------------------------------------

it('defaults relatableSearchColumns() to no column', function () {
    expect(RscTitleColumnResource::relatableSearchColumns())->toBe([]);
});

it('filters the picker by the declared columns, OR-ed', function () {
    // The first column (name), the second column only (code), and both.
    expect(rscPicker('declared_id', 'Bravo'))->toBe(['Bravo'])
        ->and(rscPicker('declared_id', 'ZX'))->toBe(['Alpha', 'Charlie'])
        ->and(rscPicker('declared_id', 'QQ-2'))->toBe(['Bravo']);
});

it('matches a declared column case-insensitively and as a substring', function () {
    expect(rscPicker('declared_id', 'zx-3'))->toBe(['Charlie'])
        ->and(rscPicker('declared_id', 'harli'))->toBe(['Charlie']);
});

it('returns nothing for a term no declared column matches', function () {
    expect(rscPicker('declared_id', 'nomatch'))->toBe([]);
});

it('does not log a warning when the declared columns carry the search', function () {
    Log::spy();

    rscPicker('declared_id', 'ZX');

    Log::shouldNotHaveReceived('warning');
});

// ---------------------------------------------------------------------------
// 2. A real-column title is still searched (pre-existing fallback)
// ---------------------------------------------------------------------------

it('still filters by a title attribute that is a real column', function () {
    Log::spy();

    expect(rscPicker('title_column_id', 'Bravo'))->toBe(['Bravo'])
        ->and(rscPicker('title_column_id', 'nomatch'))->toBe([]);

    Log::shouldNotHaveReceived('warning');
});

// ---------------------------------------------------------------------------
// 3. An accessor title is no column: no SQL error, term logged and ignored
// ---------------------------------------------------------------------------

it('answers 200 and leaves the page unfiltered when the title attribute is an accessor', function () {
    Log::spy();

    // Before the fix this searched a `label` column and raised a QueryException.
    expect(rscPicker('accessor_id', 'Bravo'))->toBe(['Alpha', 'Bravo', 'Charlie']);

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context = []) => str_starts_with($message, 'Martis: relatable search term ignored')
            && ($context['resource'] ?? null) === RscAccessorResource::class
    );
});

it('does not log the ignored-term warning when no search term is sent', function () {
    Log::spy();

    expect(rscPicker('accessor_id'))->toBe(['Alpha', 'Bravo', 'Charlie']);

    Log::shouldNotHaveReceived('warning');
});

// ---------------------------------------------------------------------------
// 4. The `id` default (overridden title()) is unfiltered unless columns are declared
// ---------------------------------------------------------------------------

it('answers 200 unfiltered when the title attribute is the id default', function () {
    Log::spy();

    expect(rscPicker('id_title_id', 'Bravo'))->toBe(['Alpha', 'Bravo', 'Charlie']);

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context = []) => str_starts_with($message, 'Martis: relatable search term ignored')
            && ($context['resource'] ?? null) === RscIdTitleResource::class
    );
});

it('filters a resource on the id default once it declares relatableSearchColumns()', function () {
    Log::spy();

    expect(rscPicker('id_title_columns_id', 'ZX'))->toBe(['Alpha', 'Charlie'])
        ->and(rscPicker('id_title_columns_id', 'Bravo'))->toBe([]);

    Log::shouldNotHaveReceived('warning');
});

// ---------------------------------------------------------------------------
// 5. A declared searchable field decides; relatableSearchColumns() is ignored
// ---------------------------------------------------------------------------

it('ignores relatableSearchColumns() when the resource declares a searchable field', function () {
    Log::spy();

    // `code` is the declared column, `name` the searchable field: only the
    // searchable field decides.
    expect(rscPicker('searchable_id', 'Bravo'))->toBe(['Bravo'])
        ->and(rscPicker('searchable_id', 'ZX'))->toBe([]);

    Log::shouldNotHaveReceived('warning');
});
