<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\BelongsTo;
use Martis\Fields\Number;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

/*
 * A relation picker lists the records of its related resource, and a picker
 * renders an id, a label and (when it asks for them) a subtitle. The relatable
 * endpoint therefore serialises a row as `id`, `_title` and the attributes the
 * picker reads (its title attribute, its subtitle attribute), not as the full
 * index row: a record the related resource's `relatableQuery()` lets through
 * does not hand every index column, `_authorization` block and `_resource`
 * descriptor to the caller (v2.4.0).
 */

class RprAccount extends Model
{
    protected $table = 'rpr_accounts';

    protected $guarded = [];

    public $timestamps = false;
}

class RprInvoice extends Model
{
    protected $table = 'rpr_invoices';

    protected $guarded = [];

    public $timestamps = false;
}

class RprAccountResource extends Resource
{
    public static function model(): string
    {
        return RprAccount::class;
    }

    public static function uriKey(): string
    {
        return 'rpr-accounts';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            Text::make('region'),
            Text::make('note')->canSeeForModel(fn (Request $request, Model $model): bool => $model->getAttribute('region') !== 'eu'),
            Number::make('revenue'),
            Text::make('owner_email'),
        ];
    }
}

class RprInvoiceResource extends Resource
{
    public static function model(): string
    {
        return RprInvoice::class;
    }

    public static function uriKey(): string
    {
        return 'rpr-invoices';
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('number'),
            BelongsTo::make('account_id', 'Account')
                ->relatedResource('rpr-accounts')
                ->titleAttribute('name')
                ->nullable(),
            BelongsTo::make('billing_account_id', 'Billing account')
                ->relatedResource('rpr-accounts')
                ->titleAttribute('name')
                ->subtitleAttribute('region')
                ->nullable(),
            BelongsTo::make('custom_account_id', 'Custom account')
                ->relatedResource('rpr-accounts')
                ->titleAttribute('owner_email')
                ->nullable(),
            BelongsTo::make('noted_account_id', 'Noted account')
                ->relatedResource('rpr-accounts')
                ->titleAttribute('name')
                ->subtitleAttribute('note')
                ->nullable(),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('rpr_accounts');
    Schema::create('rpr_accounts', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('region')->nullable();
        $table->string('note')->nullable();
        $table->integer('revenue')->default(0);
        $table->string('owner_email')->nullable();
    });
    Schema::dropIfExists('rpr_invoices');
    Schema::create('rpr_invoices', function ($table) {
        $table->id();
        $table->string('number')->nullable();
        $table->unsignedBigInteger('account_id')->nullable();
        $table->unsignedBigInteger('billing_account_id')->nullable();
        $table->unsignedBigInteger('custom_account_id')->nullable();
        $table->unsignedBigInteger('noted_account_id')->nullable();
    });

    RprAccount::create(['name' => 'Acme', 'region' => 'us', 'note' => 'private us note', 'revenue' => 123456, 'owner_email' => 'boss@acme.test']);

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(RprAccountResource::class);
    $registry->register(RprInvoiceResource::class);
});

afterEach(function () {
    Schema::dropIfExists('rpr_accounts');
    Schema::dropIfExists('rpr_invoices');
});

/** The keys of the first row a relatable URL answers. */
function rprRowKeys($test, string $url): array
{
    $row = $test->getJson($url)->assertOk()->json('data.0');

    return collect(array_keys($row))->sort()->values()->all();
}

it('serialises a picker row as its id, its title and the title attribute the picker reads', function () {
    expect(rprRowKeys($this, '/martis/api/resources/rpr-invoices/_/relatable/account_id'))
        ->toBe(['_title', 'id', 'name']);

    $row = $this->getJson('/martis/api/resources/rpr-invoices/_/relatable/account_id')->json('data.0');

    expect($row)->toMatchArray(['id' => 1, '_title' => 'Acme', 'name' => 'Acme'])
        ->and($row)->not->toHaveKeys(['revenue', 'region', 'note', 'owner_email', '_authorization', '_resource', '_hidden']);
});

it('adds the subtitle attribute of a picker that asks for subtitles, and only that one', function () {
    expect(rprRowKeys($this, '/martis/api/resources/rpr-invoices/_/relatable/billing_account_id'))
        ->toBe(['_title', 'id', 'name', 'region']);
});

it('serialises the title attribute a picker names, whatever it is', function () {
    $row = $this->getJson('/martis/api/resources/rpr-invoices/_/relatable/custom_account_id')->assertOk()->json('data.0');

    expect(collect(array_keys($row))->sort()->values()->all())->toBe(['_title', 'id', 'owner_email'])
        ->and($row['owner_email'])->toBe('boss@acme.test');
});

it('serialises the same picker row on a record\'s form as on a create form', function () {
    $invoice = RprInvoice::create(['number' => 'INV-1']);

    expect(rprRowKeys($this, "/martis/api/resources/rpr-invoices/{$invoice->id}/relatable/account_id"))
        ->toBe(['_title', 'id', 'name']);
});

it('keeps a field the record hides out of the picker row, as every read of the record does', function () {
    RprAccount::query()->delete();
    RprAccount::create(['name' => 'Eu Corp', 'region' => 'eu', 'note' => 'eu note']);

    // `note` is the subtitle attribute of this picker, hidden on the eu record.
    $row = $this->getJson('/martis/api/resources/rpr-invoices/_/relatable/noted_account_id')->assertOk()->json('data.0');

    expect($row)->toMatchArray(['name' => 'Eu Corp'])
        ->and($row)->not->toHaveKey('note');

    RprAccount::create(['name' => 'Us Corp', 'region' => 'us', 'note' => 'us note']);
    $rows = $this->getJson('/martis/api/resources/rpr-invoices/_/relatable/noted_account_id')->assertOk()->json('data');

    expect(collect($rows)->firstWhere('name', 'Us Corp'))->toMatchArray(['note' => 'us note']);
});

// ── The context-free endpoint ───────────────────────────────────────────────

it('serialises only id and title on the context-free endpoint', function () {
    $url = '/martis/api/resources/_/_/relatable/account_id?related_resource=rpr-accounts';

    $row = $this->getJson($url)->assertOk()->json('data.0');

    expect(collect(array_keys($row))->sort()->values()->all())->toBe(['_title', 'id'])
        ->and($row['_title'])->toBe('Acme');
});

it('serialises the title and subtitle attributes a context-free picker names, from the visible index fields only', function () {
    $url = '/martis/api/resources/_/_/relatable/account_id?related_resource=rpr-accounts&title_attribute=name&subtitle_attribute=region';

    $row = $this->getJson($url)->assertOk()->json('data.0');

    expect(collect(array_keys($row))->sort()->values()->all())->toBe(['_title', 'id', 'name', 'region']);

    // A column no index field shows is never read, whatever the request names.
    $row = $this->getJson('/martis/api/resources/_/_/relatable/account_id?related_resource=rpr-accounts&title_attribute=name&subtitle_attribute=secret_column')
        ->assertOk()->json('data.0');

    expect(collect(array_keys($row))->sort()->values()->all())->toBe(['_title', 'id', 'name']);
});

it('answers the context-free endpoint with the target relatableQuery() fence, as before', function () {
    RprAccount::create(['name' => 'Second', 'region' => 'us']);

    $names = collect($this->getJson('/martis/api/resources/_/_/relatable/account_id?related_resource=rpr-accounts')->assertOk()->json('data'))
        ->pluck('_title')->all();

    expect($names)->toBe(['Acme', 'Second']);
});
