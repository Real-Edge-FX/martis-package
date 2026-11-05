<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo as EloquentBelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\BelongsTo;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

/*
 * v2.17.0: a relation field that sets no titleAttribute() shows the related
 * resource's title(), as Nova does and as its own picker (`_title`) already
 * did. Before, it read `name`, so a related model without that column showed
 * `#4` in the cell. A resource left on the base `id` default keeps `name`,
 * and an explicit titleAttribute() still wins.
 */

class RTContact extends Model
{
    protected $table = 'rt_contacts';

    protected $fillable = ['label', 'name', 'email'];
}

class RTDeal extends Model
{
    protected $table = 'rt_deals';

    protected $fillable = ['title', 'contact_id'];

    public function contact(): EloquentBelongsTo
    {
        return $this->belongsTo(RTContact::class, 'contact_id');
    }
}

class RTContactResource extends Resource
{
    public static function model(): string
    {
        return RTContact::class;
    }

    public static function uriKey(): string
    {
        return 'rt-contacts';
    }

    public static function titleAttribute(): string
    {
        return 'label';
    }

    public function fields(Request $request): array
    {
        return [Text::make('label'), Text::make('name')];
    }
}

class RTLeadResource extends RTContactResource
{
    public static function uriKey(): string
    {
        return 'rt-leads';
    }

    public static function titleAttribute(): string
    {
        return 'id';
    }

    public function title(): string
    {
        return 'Lead of '.$this->model?->getAttribute('email');
    }
}

class RTPlainResource extends RTContactResource
{
    public static function uriKey(): string
    {
        return 'rt-plain';
    }

    public static function titleAttribute(): string
    {
        return 'id';
    }
}

class RTDealResource extends Resource
{
    public static function model(): string
    {
        return RTDeal::class;
    }

    public static function uriKey(): string
    {
        return 'rt-deals';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title'),
            BelongsTo::make('contact', 'Contact', RTContactResource::class),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('rt_deals');
    Schema::dropIfExists('rt_contacts');
    Schema::create('rt_contacts', function ($table) {
        $table->id();
        $table->string('label')->nullable();
        $table->string('name')->nullable();
        $table->string('email')->nullable();
        $table->timestamps();
    });
    Schema::create('rt_deals', function ($table) {
        $table->id();
        $table->string('title');
        $table->unsignedBigInteger('contact_id')->nullable();
        $table->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    foreach ([RTContactResource::class, RTLeadResource::class, RTPlainResource::class, RTDealResource::class] as $resource) {
        $registry->register($resource);
    }

    $this->contact = RTContact::create(['label' => 'Ana (owner)', 'name' => 'Ana', 'email' => 'ana@example.com']);
    $this->deal = RTDeal::create(['title' => 'Flat', 'contact_id' => $this->contact->id]);
});

afterEach(function () {
    Schema::dropIfExists('rt_deals');
    Schema::dropIfExists('rt_contacts');
});

function rtField(BelongsTo $field, Model $model): array
{
    return $field->resolve($model);
}

it('shows the related resource title() when the field sets no titleAttribute, on the cell', function () {
    expect(rtField(BelongsTo::make('contact', 'Contact', RTContactResource::class), $this->deal)['title'])->toBe('Ana (owner)');
});

it('honours a related resource that overrides title() rather than titleAttribute()', function () {
    expect(rtField(BelongsTo::make('contact', 'Lead', RTLeadResource::class), $this->deal)['title'])->toBe('Lead of ana@example.com');
});

it('keeps name for a related resource left on the base id default', function () {
    expect(rtField(BelongsTo::make('contact', 'Plain', RTPlainResource::class), $this->deal)['title'])->toBe('Ana');
});

it('lets an explicit titleAttribute() or displayColumn() win', function () {
    expect(rtField(BelongsTo::make('contact', 'Email', RTContactResource::class)->titleAttribute('email'), $this->deal)['title'])->toBe('ana@example.com')
        ->and(rtField(BelongsTo::make('contact', 'Email', RTContactResource::class)->displayColumn('name'), $this->deal)['title'])->toBe('Ana');
});

it('serializes no titleAttribute when the field defers, so the picker labels rows with _title', function () {
    expect(BelongsTo::make('contact', 'Contact', RTContactResource::class)->toArray())->not->toHaveKey('titleAttribute')
        ->and(BelongsTo::make('contact', 'Plain', RTPlainResource::class)->toArray()['titleAttribute'])->toBe('name')
        ->and(BelongsTo::make('contact', 'Email', RTContactResource::class)->titleAttribute('email')->toArray()['titleAttribute'])->toBe('email');
});

it('makes the index cell title and the picker _title agree for the same record', function () {
    $cell = collect($this->getJson('/martis/api/resources/rt-deals')->assertOk()->json('data'))->first();
    $picker = $this->getJson('/martis/api/resources/rt-deals/_/relatable/contact_id')->assertOk()->json('data.0._title');

    expect(data_get($cell, 'contact_id.title'))->toBe('Ana (owner)')
        ->and($picker)->toBe('Ana (owner)');
});
