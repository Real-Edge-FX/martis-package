<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\Badge;
use Martis\Fields\Id;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

/**
 * The reporting consumer's shape: the transport mode is a model METHOD
 * derived from a column, and the resource wants a badge under that name.
 * Before computed(), getAttribute('mode') threw LogicException and the
 * index answered 500.
 */
enum ComputedFieldChannelMode: string
{
    case Push = 'push';
    case Feed = 'feed';
}

class ComputedFieldChannel extends Model
{
    protected $table = 'computed_field_channels';

    protected $fillable = ['name', 'kind'];

    public function mode(): ComputedFieldChannelMode
    {
        return $this->kind === 'rss' ? ComputedFieldChannelMode::Feed : ComputedFieldChannelMode::Push;
    }
}

class ComputedFieldChannelResource extends Resource
{
    public static function model(): string
    {
        return ComputedFieldChannel::class;
    }

    public static function uriKey(): string
    {
        return 'computed-field-channels';
    }

    public static function titleAttribute(): string
    {
        return 'name';
    }

    public function fields(Request $request): array
    {
        return [
            Id::make(),
            Text::make('name'),
            Badge::make('mode', 'Mode')
                ->computed(fn (ComputedFieldChannel $channel): string => $channel->mode()->value)
                ->map(['push' => 'info', 'feed' => 'success']),
            // Text has no form-hiding default of its own, so this one proves
            // that computed() itself keeps the field out of the forms.
            Text::make('mode_label', 'Mode label')
                ->computed(fn (ComputedFieldChannel $channel): string => ucfirst($channel->mode()->value)),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('computed_field_channels');
    Schema::create('computed_field_channels', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('kind');
        $table->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(ComputedFieldChannelResource::class);

    ComputedFieldChannel::create(['name' => 'Blog', 'kind' => 'rss']);
    ComputedFieldChannel::create(['name' => 'Alerts', 'kind' => 'webhook']);
});

afterEach(function () {
    Schema::dropIfExists('computed_field_channels');
});

it('renders computed fields named after a model method on the index', function () {
    $response = $this->getJson('/martis/api/resources/computed-field-channels');

    $response->assertStatus(200);
    $rows = collect($response->json('data'));
    expect($rows->pluck('mode', 'name')->all())
        ->toEqualCanonicalizing(['Blog' => 'feed', 'Alerts' => 'push']);
    expect($rows->pluck('mode_label', 'name')->all())
        ->toEqualCanonicalizing(['Blog' => 'Feed', 'Alerts' => 'Push']);
});

it('renders computed fields on the detail endpoint', function () {
    $channel = ComputedFieldChannel::query()->where('name', 'Blog')->firstOrFail();

    $response = $this->getJson("/martis/api/resources/computed-field-channels/{$channel->id}");

    $response->assertStatus(200);
    expect($response->json('data.mode'))->toBe('feed');
    expect($response->json('data.mode_label'))->toBe('Feed');
});

it('lists computed fields on the index schema but not on the create or update schema', function () {
    $response = $this->getJson('/martis/api/resources/computed-field-channels/schema');

    $response->assertStatus(200);
    $attributes = fn (string $key): array => collect($response->json("data.{$key}"))->pluck('attribute')->all();

    expect($attributes('fieldsForIndex'))->toContain('mode', 'mode_label');
    expect($attributes('fieldsForCreate'))->not->toContain('mode_label');
    expect($attributes('fieldsForUpdate'))->not->toContain('mode_label');
});
