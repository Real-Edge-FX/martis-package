<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\Metrics\ValueMetric;
use Martis\Metrics\ValueResult;
use Martis\Resource;
use Martis\ResourceRegistry;

/*
 * Regression coverage for the resource-metric endpoint
 * GET /martis/api/resources/{resource}/cards/{card}.
 *
 * v1.15.2 — `MetricController::computeResourceMetric()` called the
 * non-existent `ResourceRegistry::resolve()` and 500'd on every
 * resource card. The registry exposes `has()` / `get()` (the idiom
 * every other controller uses); `resolve()` never existed. These
 * tests pin the endpoint's contract: a registered card computes,
 * an unknown resource 404s, an unknown card 404s.
 */

class MetricCardPostModel extends Model
{
    protected $table = 'martis_test_metric_card_posts';

    protected $fillable = ['title'];
}

class MetricCardTotalPostsMetric extends ValueMetric
{
    public function calculate(Request $request): ValueResult
    {
        return $this->result(MetricCardPostModel::query()->count());
    }
}

class MetricCardPostResource extends Resource
{
    public static function model(): string
    {
        return MetricCardPostModel::class;
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('title'),
        ];
    }

    public function cards(Request $request): array
    {
        return [
            MetricCardTotalPostsMetric::make('Total posts', 'total-posts'),
        ];
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('martis_test_metric_card_posts');
    Schema::create('martis_test_metric_card_posts', function ($table) {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });

    $registry = app(ResourceRegistry::class);
    $registry->flush();
    $registry->register(MetricCardPostResource::class);
});

afterEach(function () {
    app(ResourceRegistry::class)->flush();
    Schema::dropIfExists('martis_test_metric_card_posts');
});

it('GET /martis/api/resources/{resource}/cards/{card} computes a registered resource metric', function () {
    MetricCardPostModel::create(['title' => 'First']);
    MetricCardPostModel::create(['title' => 'Second']);

    $response = $this->getJson('/martis/api/resources/metric-card-post-models/cards/total-posts');

    // The compute response is wrapped in the standard JSON envelope:
    // { data: { result: { value: N } }, meta, links }. Assert the
    // metric actually computed the row count (2 posts created above),
    // not merely that the endpoint returned 200.
    $response->assertOk();
    expect($response->json('data.result.value'))->toBe(2);
});

it('GET /martis/api/resources/{resource}/cards/{card} returns 404 for an unknown resource', function () {
    $response = $this->getJson('/martis/api/resources/no-such-resource/cards/total-posts');

    $response->assertNotFound();
});

it('GET /martis/api/resources/{resource}/cards/{card} returns 404 for an unknown card on a known resource', function () {
    $response = $this->getJson('/martis/api/resources/metric-card-post-models/cards/no-such-card');

    $response->assertNotFound();
});
