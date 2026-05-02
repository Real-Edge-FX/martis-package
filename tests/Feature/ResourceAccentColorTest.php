<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

class AccentTestModel extends Model
{
    protected $table = 'martis_test_accents';

    protected $fillable = ['name'];
}

class AccentTestResourceDefault extends Resource
{
    public static function model(): string
    {
        return AccentTestModel::class;
    }

    public static function uriKey(): string
    {
        return 'accent-default';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

class AccentTestResourceNamed extends Resource
{
    public static function model(): string
    {
        return AccentTestModel::class;
    }

    public static function uriKey(): string
    {
        return 'accent-named';
    }

    public static function accentColor(): ?string
    {
        return 'teal';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

class AccentTestResourceHex extends Resource
{
    public static function model(): string
    {
        return AccentTestModel::class;
    }

    public static function uriKey(): string
    {
        return 'accent-hex';
    }

    public static function accentColor(): ?string
    {
        return '#DC143C';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('martis_test_accents');
    Schema::create('martis_test_accents', function ($t) {
        $t->id();
        $t->string('name');
        $t->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(AccentTestResourceDefault::class);
    $registry->register(AccentTestResourceNamed::class);
    $registry->register(AccentTestResourceHex::class);
});

afterEach(function () {
    Schema::dropIfExists('martis_test_accents');
});

it('Resource::accentColor defaults to null', function () {
    expect(AccentTestResourceDefault::accentColor())->toBeNull();
});

it('Resource toArray includes accentColor (null by default)', function () {
    $resource = new AccentTestResourceDefault;
    expect($resource->toArray())->toHaveKey('accentColor');
    expect($resource->toArray()['accentColor'])->toBeNull();
});

it('Resource toArray emits a named accent override', function () {
    $resource = new AccentTestResourceNamed;
    expect($resource->toArray()['accentColor'])->toBe('teal');
});

it('Resource toArray emits a hex accent override', function () {
    $resource = new AccentTestResourceHex;
    expect($resource->toArray()['accentColor'])->toBe('#DC143C');
});

it('schema endpoint surfaces the accentColor on the resource payload', function () {
    AccentTestModel::create(['name' => 'sample']);

    $payload = $this->getJson('/martis/api/resources/accent-named/schema')->json();

    // accentColor lives on the resource meta inside the schema response.
    // The exact path depends on schema controller shape; we look at all
    // top-level fields plus a deep search for the value.
    $json = json_encode($payload, JSON_THROW_ON_ERROR);
    expect($json)->toContain('teal');
});
