<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Martis\MartisManager;
use Martis\Resource;
use Martis\ResourceRegistry;

// ---------------------------------------------------------------------------
// Test fixtures — Models
// ---------------------------------------------------------------------------

class RecordUrlConfigMapWithUrlModel extends Model
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}

class RecordUrlConfigMapWithoutUrlModel extends Model
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}

// ---------------------------------------------------------------------------
// Test fixtures — Resources
// ---------------------------------------------------------------------------

class ConfigMapWithRecordUrlResource extends Resource
{
    public static function model(): string
    {
        return RecordUrlConfigMapWithUrlModel::class;
    }

    public static function uriKey(): string
    {
        return 'config-map-with-record-url-items';
    }

    public static function recordUrl(): ?string
    {
        return '/tools/pk?id={id}';
    }

    public function fields(Request $request): array
    {
        return [];
    }
}

class ConfigMapWithoutRecordUrlResource extends Resource
{
    public static function model(): string
    {
        return RecordUrlConfigMapWithoutUrlModel::class;
    }

    public static function uriKey(): string
    {
        return 'config-map-without-record-url-items';
    }

    public function fields(Request $request): array
    {
        return [];
    }
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

beforeEach(function () {
    $registry = app(ResourceRegistry::class);
    $registry->register(ConfigMapWithRecordUrlResource::class);
    $registry->register(ConfigMapWithoutRecordUrlResource::class);
});

afterEach(function () {
    app(ResourceRegistry::class)->flush();
});

// ---------------------------------------------------------------------------
// recordUrlMap()
// ---------------------------------------------------------------------------

it('maps uriKey to recordUrl template only for resources that declare one', function () {
    $map = app(MartisManager::class)->recordUrlMap();

    expect($map)->toBe([
        'config-map-with-record-url-items' => '/tools/pk?id={id}',
    ]);

    expect($map)->not->toHaveKey('config-map-without-record-url-items');
});
