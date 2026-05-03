<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

class LoaderTestModel extends Model
{
    protected $table = 'martis_test_loaders';

    protected $fillable = ['name'];
}

class LoaderTestResourceDefault extends Resource
{
    public static function model(): string
    {
        return LoaderTestModel::class;
    }

    public static function uriKey(): string
    {
        return 'loader-default';
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

class LoaderTestResourceCustom extends Resource
{
    public static function model(): string
    {
        return LoaderTestModel::class;
    }

    public static function uriKey(): string
    {
        return 'loader-custom';
    }

    public static function loaderConfig(): array
    {
        return [
            'message' => 'Calibrating audit log…',
            'spinnerColor' => '#FF6347',
            'disableOn' => ['detail' => true],
        ];
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('martis_test_loaders');
    Schema::create('martis_test_loaders', function ($t) {
        $t->id();
        $t->string('name');
        $t->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(LoaderTestResourceDefault::class);
    $registry->register(LoaderTestResourceCustom::class);
});

afterEach(function () {
    Schema::dropIfExists('martis_test_loaders');
});

it('Resource::loaderConfig defaults to an empty array', function () {
    expect(LoaderTestResourceDefault::loaderConfig())->toBe([]);
});

it('Resource::loaderConfig returns the per-resource override', function () {
    $override = LoaderTestResourceCustom::loaderConfig();

    expect($override)->toHaveKey('message')
        ->and($override['message'])->toBe('Calibrating audit log…')
        ->and($override['spinnerColor'])->toBe('#FF6347')
        ->and($override['disableOn'])->toBe(['detail' => true]);
});

it('schema endpoint surfaces an empty loaderConfig for resources without override', function () {
    LoaderTestModel::create(['name' => 'sample']);

    $payload = $this->getJson('/martis/api/resources/loader-default/schema')->json();
    $json = json_encode($payload, JSON_THROW_ON_ERROR);

    // The default resource ships an empty array; serialized JSON should
    // contain the `loaderConfig` key but no override values.
    expect($json)->toContain('"loaderConfig":[]');
});

it('schema endpoint surfaces the per-resource loaderConfig override', function () {
    LoaderTestModel::create(['name' => 'sample']);

    $payload = $this->getJson('/martis/api/resources/loader-custom/schema')->json();
    $json = json_encode($payload, JSON_THROW_ON_ERROR);

    expect($json)->toContain('Calibrating audit log')
        ->and($json)->toContain('#FF6347');
});
