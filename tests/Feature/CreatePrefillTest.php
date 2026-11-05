<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\BelongsTo;
use Martis\Fields\Id;
use Martis\Fields\Select;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

class PrefillOwner extends Model
{
    protected $table = 'prefill_owners';

    protected $fillable = ['name', 'visible'];
}

class PrefillItem extends Model
{
    protected $table = 'prefill_items';

    protected $fillable = ['name', 'status', 'owner_id'];

    public function owner()
    {
        return $this->belongsTo(PrefillOwner::class, 'owner_id');
    }
}

class PrefillOwnerResource extends Resource
{
    public static function model(): string
    {
        return PrefillOwner::class;
    }

    public static function uriKey(): string
    {
        return 'prefill-owners';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public static function relatableQuery(Request $request, Builder $query): Builder
    {
        return $query->where('visible', true);
    }

    public function fields(Request $request): array
    {
        return [Id::make(), Text::make('name')];
    }
}

class PrefillItemResource extends Resource
{
    public static function model(): string
    {
        return PrefillItem::class;
    }

    public static function uriKey(): string
    {
        return 'prefill-items';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public static function acceptsPrefill(): array
    {
        return ['of', 'owner'];
    }

    public function fields(Request $request): array
    {
        return [
            Id::make(),
            Text::make('name')->default(fn (?Request $r) => $r?->query('of')),
            Select::make('status')->options(['draft' => 'Draft', 'live' => 'Live'])
                ->default(fn (?Request $r) => $r?->query('of')),
            BelongsTo::make('owner', 'Owner')->relatedResource('prefill-owners')->titleAttribute('name')->nullable()
                ->default(fn (?Request $r) => $r?->query('owner')),
        ];
    }
}

class PrefillPlainResource extends PrefillItemResource
{
    public static function uriKey(): string
    {
        return 'prefill-plain';
    }

    public static function acceptsPrefill(): array
    {
        return [];
    }
}

function prefillSchemaUrl(string $resource, string $query = ''): string
{
    return "/martis/api/resources/{$resource}/schema".($query === '' ? '' : "?{$query}");
}

function prefillDefault($response, string $attribute = 'name', string $list = 'fieldsForCreate'): mixed
{
    return collect($response->json("data.{$list}"))->firstWhere('attribute', $attribute)['defaultValue'] ?? null;
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('prefill_items');
    Schema::dropIfExists('prefill_owners');
    Schema::create('prefill_owners', function ($table) {
        $table->id();
        $table->string('name');
        $table->boolean('visible')->default(true);
        $table->timestamps();
    });
    Schema::create('prefill_items', function ($table) {
        $table->id();
        $table->string('name')->nullable();
        $table->string('status')->nullable();
        $table->unsignedBigInteger('owner_id')->nullable();
        $table->timestamps();
    });
    PrefillOwner::create(['name' => 'Pickable', 'visible' => true]);
    PrefillOwner::create(['name' => 'Hidden', 'visible' => false]);

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(PrefillOwnerResource::class);
    $registry->register(PrefillItemResource::class);
    $registry->register(PrefillPlainResource::class);
});

afterEach(function () {
    Schema::dropIfExists('prefill_items');
    Schema::dropIfExists('prefill_owners');
});

it('exposes the declared prefill keys on the schema', function () {
    expect(Resource::acceptsPrefill())->toBe([])
        ->and($this->getJson(prefillSchemaUrl('prefill-items'))->json('data.prefillKeys'))->toBe(['of', 'owner'])
        ->and($this->getJson(prefillSchemaUrl('prefill-plain'))->json('data.prefillKeys'))->toBe([]);
});

it('lets a default closure read the prefill from the query', function () {
    $response = $this->getJson(prefillSchemaUrl('prefill-items', 'prefill[of]=live'));

    expect(prefillDefault($response))->toBe('live')
        ->and(prefillDefault($response, 'status'))->toBe('live');
});

it('does not serve the prefilled schema from, or into, the cache', function () {
    expect(prefillDefault($this->getJson(prefillSchemaUrl('prefill-items', 'prefill[of]=one'))))->toBe('one')
        ->and(prefillDefault($this->getJson(prefillSchemaUrl('prefill-items', 'prefill[of]=two'))))->toBe('two')
        ->and(prefillDefault($this->getJson(prefillSchemaUrl('prefill-items'))))->toBeNull();
});

it('ignores a prefill key the resource did not declare', function () {
    expect(prefillDefault($this->getJson(prefillSchemaUrl('prefill-items', 'prefill[other]=x&prefill[of]=live'))))->toBe('live')
        ->and(prefillDefault($this->getJson(prefillSchemaUrl('prefill-plain', 'prefill[of]=live'))))->toBeNull();
});

it('ignores a prefill value that is not a string or is longer than 255 characters', function () {
    expect(prefillDefault($this->getJson(prefillSchemaUrl('prefill-items', 'prefill[of][]=a'))))->toBeNull()
        ->and(prefillDefault($this->getJson(prefillSchemaUrl('prefill-items', 'prefill[of]='.str_repeat('a', 256)))))->toBeNull()
        ->and(prefillDefault($this->getJson(prefillSchemaUrl('prefill-items', 'prefill[of]='.str_repeat('a', 255)))))->toBe(str_repeat('a', 255));
});

it('drops a prefilled Select value that is not one of its options', function () {
    $response = $this->getJson(prefillSchemaUrl('prefill-items', 'prefill[of]=bogus'));

    expect(prefillDefault($response, 'status'))->toBeNull()
        ->and(prefillDefault($response))->toBe('bogus');
});

it('drops a prefilled BelongsTo the picker would not list and keeps one it lists', function () {
    $hidden = $this->getJson(prefillSchemaUrl('prefill-items', 'prefill[owner]=2'));
    $pickable = $this->getJson(prefillSchemaUrl('prefill-items', 'prefill[owner]=1'));
    $missing = $this->getJson(prefillSchemaUrl('prefill-items', 'prefill[owner]=99'));

    expect(prefillDefault($hidden, 'owner_id'))->toBeNull()
        ->and(prefillDefault($missing, 'owner_id'))->toBeNull()
        ->and((string) prefillDefault($pickable, 'owner_id'))->toBe('1');
});

it('serialises sticky on the field descriptor', function () {
    expect(Text::make('a')->toArray()['sticky'])->toBeFalse()
        ->and(Text::make('a')->sticky()->toArray()['sticky'])->toBeTrue()
        ->and(Text::make('a')->sticky()->sticky(false)->toArray()['sticky'])->toBeFalse();
});
