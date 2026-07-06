<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Martis\Contracts\FieldContract;
use Martis\Resource;

// ---------------------------------------------------------------------------
// Test fixtures
// ---------------------------------------------------------------------------

class RecordUrlTestModel extends Model
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}

class ResourceWithCustomRecordUrl extends Resource
{
    public static function model(): string
    {
        return RecordUrlTestModel::class;
    }

    /** @return list<FieldContract> */
    public function fields(Request $request): array
    {
        return [];
    }

    public static function recordUrl(): ?string
    {
        return '/tools/pk?id={id}';
    }
}

class ResourceWithDefaultRecordUrl extends Resource
{
    public static function model(): string
    {
        return RecordUrlTestModel::class;
    }

    /** @return list<FieldContract> */
    public function fields(Request $request): array
    {
        return [];
    }
}

// ---------------------------------------------------------------------------
// recordUrl() / recordHref()
// ---------------------------------------------------------------------------

it('recordUrl returns null by default', function () {
    expect(ResourceWithDefaultRecordUrl::recordUrl())->toBeNull();
});

it('recordHref interpolates the {id} placeholder from a custom recordUrl template', function () {
    expect(ResourceWithCustomRecordUrl::recordHref(7))->toBe('/tools/pk?id=7');
});

it('recordHref URL-encodes the id when interpolating the template', function () {
    expect(ResourceWithCustomRecordUrl::recordHref('a b'))->toBe('/tools/pk?id=a%20b');
});

it('recordHref falls back to the default detail path when recordUrl is null', function () {
    expect(ResourceWithDefaultRecordUrl::recordHref(7))
        ->toBe('/resources/'.ResourceWithDefaultRecordUrl::uriKey().'/7');
});
