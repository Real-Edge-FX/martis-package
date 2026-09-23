<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany as EloquentHasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany as EloquentMorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\HasMany;
use Martis\Fields\MorphMany;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Http\Requests\LensRequest;
use Martis\Lenses\Lens;
use Martis\Resource;
use Martis\ResourceRegistry;

// ===========================================================================
// The query parameters of a list, and a lens over a table without
// `updated_at` (v1.38.0).
//
// `?trashed[]=with` (an array where a string is expected) answered 500 on
// the index, the HasMany and MorphMany panels and a lens of a resource that
// soft-deletes: the parameter was cast to a string. It now means the
// default, the records that are not trashed. A lens computed its cache
// signature from MAX(updated_at) on every request, the cache off included,
// so a lens over a table without that column answered 500.
// ===========================================================================

class LQRNote extends Model
{
    use SoftDeletes;

    protected $table = 'lqr_notes';

    protected $guarded = [];

    public $timestamps = false;
}

class LQRBoard extends Model
{
    protected $table = 'lqr_boards';

    protected $guarded = [];

    public $timestamps = false;

    public function notes(): EloquentHasMany
    {
        return $this->hasMany(LQRNote::class, 'board_id');
    }

    public function pins(): EloquentMorphMany
    {
        return $this->morphMany(LQRNote::class, 'owner');
    }
}

class LQRAllNotesLens extends Lens
{
    public function query(LensRequest $request, Builder $query): Builder
    {
        return $query->orderBy('id');
    }

    public function fields(Request $request): array
    {
        return [Text::make('body')];
    }
}

class LQRNoteResource extends Resource
{
    public static function model(): string
    {
        return LQRNote::class;
    }

    public static function uriKey(): string
    {
        return 'lqr-notes';
    }

    public function fields(Request $request): array
    {
        return [Text::make('body')];
    }

    public function lenses(Request $request): array
    {
        return [new LQRAllNotesLens];
    }
}

/** The same lens, cached. */
class LQRCachedNotesLens extends LQRAllNotesLens {}

class LQRCachedNoteResource extends LQRNoteResource
{
    public static function uriKey(): string
    {
        return 'lqr-cached-notes';
    }

    public function lenses(Request $request): array
    {
        return [(new LQRCachedNotesLens)->cacheFor(60)];
    }
}

class LQRBoardResource extends Resource
{
    public static function model(): string
    {
        return LQRBoard::class;
    }

    public static function uriKey(): string
    {
        return 'lqr-boards';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            HasMany::make('Notes', 'notes')->relatedResource('lqr-notes'),
            MorphMany::make('Pins', 'pins')->relatedResource('lqr-notes'),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('lqr_notes');
    Schema::dropIfExists('lqr_boards');
    Schema::create('lqr_boards', function ($table) {
        $table->id();
        $table->string('name');
    });
    // No timestamps: no `updated_at` column.
    Schema::create('lqr_notes', function ($table) {
        $table->id();
        $table->unsignedBigInteger('board_id')->nullable();
        $table->nullableMorphs('owner');
        $table->string('body');
        $table->softDeletes();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(LQRNoteResource::class);
    $registry->register(LQRCachedNoteResource::class);
    $registry->register(LQRBoardResource::class);

    Cache::flush();

    $this->board = LQRBoard::create(['name' => 'Board']);
    $attributes = ['board_id' => $this->board->id, 'owner_type' => LQRBoard::class, 'owner_id' => $this->board->id];
    LQRNote::create(['body' => 'Kept', ...$attributes]);
    LQRNote::create(['body' => 'Trashed', ...$attributes])->delete();
});

afterEach(function () {
    Schema::dropIfExists('lqr_notes');
    Schema::dropIfExists('lqr_boards');
});

it('reads a trashed parameter that is not a string as the default', function (string $uri) {
    $uri = str_replace('{board}', (string) $this->board->id, $uri);

    expect(array_column($this->getJson("{$uri}?trashed[]=with")->assertOk()->json('data'), 'body'))->toBe(['Kept'])
        ->and(array_column($this->getJson("{$uri}?trashed=with")->assertOk()->json('data'), 'body'))->toBe(['Kept', 'Trashed']);
})->with([
    'resource index' => '/martis/api/resources/lqr-notes',
    'HasMany panel' => '/martis/api/resources/lqr-boards/{board}/has-many/notes',
    'MorphMany panel' => '/martis/api/resources/lqr-boards/{board}/morph-many/pins',
    'lens' => '/martis/api/resources/lqr-notes/lenses/l-q-r-all-notes',
]);

it('serves a lens over a table without updated_at', function () {
    expect(array_column($this->getJson('/martis/api/resources/lqr-notes/lenses/l-q-r-all-notes')->assertOk()->json('data'), 'body'))->toBe(['Kept']);
});

it('caches a lens over a table without updated_at and refreshes it when a row is added', function () {
    $uri = '/martis/api/resources/lqr-cached-notes/lenses/l-q-r-cached-notes';

    expect(array_column($this->getJson($uri)->assertOk()->json('data'), 'body'))->toBe(['Kept']);

    LQRNote::create(['body' => 'Added']);

    expect(array_column($this->getJson($uri)->assertOk()->json('data'), 'body'))->toBe(['Kept', 'Added']);
});
