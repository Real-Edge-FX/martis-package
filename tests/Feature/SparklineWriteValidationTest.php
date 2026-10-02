<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\Sparkline;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Resource;
use Martis\ResourceRegistry;

// ===========================================================================
// A written Sparkline series is validated (F081).
//
// The chart draws every point of the stored series, and the SPA used to
// spread the whole array into Math.min() / Math.max(): a series past about
// 125,000 numbers made every page that renders the record throw. fill()
// stores whatever array it gets, so the field now declares a default
// validation: a list of at most `maxPoints()` numbers (1000 unless raised).
// ===========================================================================

class SparkSeriesModel extends Model
{
    protected $table = 'spark_series';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['points' => 'array'];
}

class SparkSeriesResource extends Resource
{
    public static function model(): string
    {
        return SparkSeriesModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('name'),
            Sparkline::make('points')->showOnForms(),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('spark_series');
    Schema::create('spark_series', function ($table) {
        $table->id();
        $table->string('name')->nullable();
        $table->json('points')->nullable();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(SparkSeriesResource::class);

    $this->series = SparkSeriesModel::create(['name' => 'Revenue', 'points' => [1, 2, 3]]);
    $this->url = '/martis/api/resources/'.SparkSeriesResource::uriKey().'/'.$this->series->id;
});

afterEach(function () {
    Schema::dropIfExists('spark_series');
});

it('refuses a series longer than the limit with a 422 and writes nothing', function () {
    $response = $this->putJson($this->url, ['points' => range(1, Sparkline::DEFAULT_MAX_POINTS + 1)]);

    $response->assertStatus(422)->assertJsonPath('errors.0.field', 'points');
    expect($this->series->fresh()->points)->toBe([1, 2, 3]);
});

it('refuses a series of 150,000 numbers that would crash every page rendering the record', function () {
    $response = $this->putJson($this->url, ['points' => range(1, 150000)]);

    $response->assertStatus(422)->assertJsonPath('errors.0.field', 'points');
    expect($this->series->fresh()->points)->toBe([1, 2, 3]);
});

it('refuses a series that holds something other than numbers', function (mixed $points) {
    $response = $this->putJson($this->url, ['points' => $points]);

    $response->assertStatus(422)->assertJsonPath('errors.0.field', 'points');
    expect($this->series->fresh()->points)->toBe([1, 2, 3]);
})->with([
    'a string' => [[1, 'two', 3]],
    'a null' => [[1, null, 3]],
    'a nested array' => [[1, [2], 3]],
    'a boolean' => [[1, true, 3]],
    'a numeric string' => [['1', '2', '3']],
    'a map' => [['a' => 1, 'b' => 2]],
]);

it('stores a series within the limit, integers and floats alike', function () {
    $this->putJson($this->url, ['points' => [1, 2.5, -3, 0]])->assertStatus(200);

    expect($this->series->fresh()->points)->toBe([1, 2.5, -3, 0]);
});

it('stores a series of exactly the limit', function () {
    $this->putJson($this->url, ['points' => range(1, Sparkline::DEFAULT_MAX_POINTS)])->assertStatus(200);

    expect($this->series->fresh()->points)->toHaveCount(Sparkline::DEFAULT_MAX_POINTS);
});

it('stores an empty series', function () {
    $this->putJson($this->url, ['points' => []])->assertStatus(200);

    expect($this->series->fresh()->points)->toBe([]);
});

it('leaves the series alone when an update does not send it', function () {
    $this->putJson($this->url, ['name' => 'Renamed'])->assertStatus(200);

    expect($this->series->fresh()->points)->toBe([1, 2, 3])
        ->and($this->series->fresh()->name)->toBe('Renamed');
});

it('refuses an over-long series sent as multipart JSON, as the SPA does with a file upload', function () {
    $response = $this->call(
        'POST',
        $this->url,
        ['_method' => 'PUT', 'points' => json_encode(range(1, Sparkline::DEFAULT_MAX_POINTS + 1))],
        [],
        [],
        ['HTTP_ACCEPT' => 'application/json'],
    );

    $response->assertStatus(422)->assertJsonPath('errors.0.field', 'points');
    expect($this->series->fresh()->points)->toBe([1, 2, 3]);
});

it('refuses a series on create too', function () {
    $response = $this->postJson('/martis/api/resources/'.SparkSeriesResource::uriKey(), [
        'name' => 'New',
        'points' => range(1, Sparkline::DEFAULT_MAX_POINTS + 1),
    ]);

    $response->assertStatus(422)->assertJsonPath('errors.0.field', 'points');
    expect(SparkSeriesModel::query()->where('name', 'New')->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// The rules themselves, run through the validator.
// ---------------------------------------------------------------------------

/** Run the field's rules against `$value` the way the controllers do. */
function sparklineErrors(Sparkline $field, mixed $value): array
{
    return validator(['points' => $value], ['points' => $field->buildRules('update')])->errors()->get('points');
}

it('validates a series as a list of at most 1000 numbers by default', function () {
    $field = Sparkline::make('points');

    expect(sparklineErrors($field, [1, 2.5, -3, 0]))->toBe([])
        ->and(sparklineErrors($field, range(1, 1000)))->toBe([])
        ->and(sparklineErrors($field, []))->toBe([])
        ->and(sparklineErrors($field, range(1, 1001)))->not->toBe([])
        ->and(sparklineErrors($field, [1, 'two']))->not->toBe([])
        ->and(sparklineErrors($field, [1, null]))->not->toBe([])
        ->and(sparklineErrors($field, [1, [2]]))->not->toBe([])
        ->and(sparklineErrors($field, ['1', '2']))->not->toBe([])
        ->and(sparklineErrors($field, ['a' => 1]))->not->toBe([])
        ->and(sparklineErrors($field, 'not an array'))->not->toBe([]);
});

it('refuses a non-finite number', function () {
    $field = Sparkline::make('points');

    expect(sparklineErrors($field, [1, NAN]))->not->toBe([])
        ->and(sparklineErrors($field, [1, INF]))->not->toBe([]);
});

it('lets maxPoints() raise or lower the limit', function () {
    $raised = Sparkline::make('points')->maxPoints(5000);
    $lowered = Sparkline::make('points')->maxPoints(3);

    expect(sparklineErrors($raised, range(1, 5000)))->toBe([])
        ->and(sparklineErrors($raised, range(1, 5001)))->not->toBe([])
        ->and(sparklineErrors($lowered, [1, 2, 3]))->toBe([])
        ->and(sparklineErrors($lowered, [1, 2, 3, 4]))->not->toBe([]);
});

it('keeps nullable and required as the field declares them', function () {
    $nullable = Sparkline::make('points')->nullable();
    $required = Sparkline::make('points')->required();

    expect(sparklineErrors($nullable, null))->toBe([])
        ->and(sparklineErrors($required, null))->not->toBe([])
        ->and(sparklineErrors($required, [1, 2]))->toBe([]);
});

it('words the numbers rule in the locale of the panel', function () {
    expect(sparklineErrors(Sparkline::make('points'), [1, 'two'])[0])->toContain('list of numbers');

    app()->setLocale('pt_PT');
    expect(sparklineErrors(Sparkline::make('points'), [1, 'two'])[0])->toContain('lista de números');
});
