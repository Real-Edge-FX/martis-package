<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\Id;
use Martis\Fields\Number;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

class UiRequestsModel extends Model
{
    protected $table = 'ui_requests_items';

    protected $fillable = ['name'];
}

class UiRequestsDefaultResource extends Resource
{
    public static function model(): string
    {
        return UiRequestsModel::class;
    }

    public static function uriKey(): string
    {
        return 'ui-requests-default';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public function fields(Request $request): array
    {
        return [Id::make(), Text::make('name')];
    }
}

class UiRequestsCustomResource extends UiRequestsDefaultResource
{
    public static function uriKey(): string
    {
        return 'ui-requests-custom';
    }

    public static function stickyActions(): bool
    {
        return true;
    }

    public function detailKicker(Request $request): string|false|null
    {
        return 'Record sheet';
    }
}

class UiRequestsHiddenKickerResource extends UiRequestsDefaultResource
{
    public static function uriKey(): string
    {
        return 'ui-requests-hidden';
    }

    public function detailKicker(Request $request): string|false|null
    {
        return false;
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('ui_requests_items');
    Schema::create('ui_requests_items', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(UiRequestsDefaultResource::class);
    $registry->register(UiRequestsCustomResource::class);
    $registry->register(UiRequestsHiddenKickerResource::class);
});

afterEach(function () {
    Schema::dropIfExists('ui_requests_items');
});

it('keeps the default kicker and non-sticky actions in the schema', function () {
    $response = $this->getJson('/martis/api/resources/ui-requests-default/schema');

    $response->assertOk();
    expect($response->json('data.detailKicker'))->toBeNull()
        ->and($response->json('data.stickyActions'))->toBeFalse();
});

it('serialises a custom kicker string and sticky actions', function () {
    $response = $this->getJson('/martis/api/resources/ui-requests-custom/schema');

    expect($response->json('data.detailKicker'))->toBe('Record sheet')
        ->and($response->json('data.stickyActions'))->toBeTrue();
});

it('serialises detailKicker false to hide the heading', function () {
    $response = $this->getJson('/martis/api/resources/ui-requests-hidden/schema');

    expect($response->json('data.detailKicker'))->toBeFalse();
});

it('serialises hideLabelOnDetail, defaulting to false', function () {
    expect(Text::make('note')->toArray()['hideLabelOnDetail'])->toBeFalse()
        ->and(Text::make('note')->hideLabelOnDetail()->toArray()['hideLabelOnDetail'])->toBeTrue()
        ->and(Text::make('note')->hideLabelOnDetail()->hideLabelOnDetail(false)->toArray()['hideLabelOnDetail'])->toBeFalse()
        ->and(Text::make('note')->fullWidth()->toArray()['fullWidth'])->toBeTrue();
});

it('serialises textAlign and hideBelow in the column block', function () {
    $column = Number::make('amount')->textAlign('right')->hideBelow('lg')->toArray()['column'];

    expect($column['textAlign'])->toBe('right')
        ->and($column['hideBelow'])->toBe('lg');

    $default = Number::make('amount')->toArray()['column'];
    expect($default['textAlign'])->toBeNull()
        ->and($default['hideBelow'])->toBeNull();
});

it('accepts every textAlign and hideBelow value', function () {
    foreach (['left', 'center', 'right'] as $align) {
        expect(Text::make('a')->textAlign($align)->toArray()['column']['textAlign'])->toBe($align);
    }
    foreach (['sm', 'md', 'lg', 'xl'] as $bp) {
        expect(Text::make('a')->hideBelow($bp)->toArray()['column']['hideBelow'])->toBe($bp);
    }
});

it('rejects an unknown textAlign or hideBelow value naming the value', function () {
    expect(fn () => Text::make('a')->textAlign('justify'))
        ->toThrow(InvalidArgumentException::class, '"justify"')
        ->and(fn () => Text::make('a')->hideBelow('2xl'))
        ->toThrow(InvalidArgumentException::class, '"2xl"');
});
