<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// ---------------------------------------------------------------------------
// The morph-many, morph-one and morph-to-many controllers each resolve their
// own context (parent resource, parent record, relationship field), so each
// answers 404 for the three ways a request can miss it. The happy paths of
// every type live in MorphManyControllerTest, MorphOneControllerTest and
// MorphToManyControllerTest.
// ---------------------------------------------------------------------------

class MorphNotFoundParentModel extends Model
{
    protected $table = 'morph_not_found_parents';

    protected $fillable = ['title'];
}

class MorphNotFoundParentResource extends Resource
{
    public static function model(): string
    {
        return MorphNotFoundParentModel::class;
    }

    public static function uriKey(): string
    {
        return 'morph-not-found-parents';
    }

    public function fields(Request $request): array
    {
        // No relationship field at all: every relationship name is unknown.
        return [Text::make('title')];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('morph_not_found_parents');
    Schema::create('morph_not_found_parents', function ($table) {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });

    app(ResourceRegistry::class)->register(MorphNotFoundParentResource::class);
});

afterEach(function () {
    Schema::dropIfExists('morph_not_found_parents');
});

it('answers 404 on a morph relation route when the parent resource, the parent record or the relationship is unknown', function (string $segment, string $miss) {
    $parent = MorphNotFoundParentModel::create(['title' => 'Parent']);

    $path = match ($miss) {
        'resource' => "nonexistent/{$parent->id}/{$segment}/anything",
        'record' => "morph-not-found-parents/99999/{$segment}/anything",
        'relationship' => "morph-not-found-parents/{$parent->id}/{$segment}/nonexistent",
    };

    $this->getJson("/martis/api/resources/{$path}")->assertNotFound();
})->with([
    'morph-many' => ['morph-many'],
    'morph-one' => ['morph-one'],
    'morph-to-many' => ['morph-to-many'],
])->with([
    'unknown parent resource' => ['resource'],
    'unknown parent record' => ['record'],
    'unknown relationship name' => ['relationship'],
]);
