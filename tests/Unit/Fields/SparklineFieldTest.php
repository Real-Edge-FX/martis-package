<?php

use Illuminate\Database\Eloquent\Model;
use Martis\Enums\ChartType;
use Martis\FieldContext;
use Martis\Fields\Sparkline;

class SparklineTestModel extends Model
{
    protected $table = 'users';

    protected $fillable = ['views_data'];

    public $timestamps = false;

    protected $casts = ['views_data' => 'array'];
}

it('Sparkline::make creates a sparkline field', function () {
    $field = Sparkline::make('views');
    expect($field->attribute())->toBe('views')
        ->and($field->label())->toBe('Views')
        ->and($field->type())->toBe('sparkline');
});

it('Sparkline is hidden from forms by default', function () {
    $field = Sparkline::make('views');
    expect($field->isVisibleForContext(FieldContext::INDEX))->toBeTrue()
        ->and($field->isVisibleForContext(FieldContext::DETAIL))->toBeTrue()
        ->and($field->isVisibleForContext(FieldContext::CREATE))->toBeFalse()
        ->and($field->isVisibleForContext(FieldContext::UPDATE))->toBeFalse();
});

it('Sparkline data() sets static data', function () {
    $field = Sparkline::make('views')->data([1, 2, 3, 4, 5]);
    $model = new SparklineTestModel;
    expect($field->resolve($model))->toBe([1, 2, 3, 4, 5]);
});

it('Sparkline data() accepts callable', function () {
    $field = Sparkline::make('views')->data(fn () => [10, 20, 30]);
    $model = new SparklineTestModel;
    expect($field->resolve($model))->toBe([10, 20, 30]);
});

it('Sparkline falls back to model attribute when no data set', function () {
    $model = new SparklineTestModel(['views_data' => [5, 10, 15]]);
    $field = Sparkline::make('views_data');
    expect($field->resolve($model))->toBe([5, 10, 15]);
});

it('Sparkline returns empty array for null model value', function () {
    $model = new SparklineTestModel(['views_data' => null]);
    $field = Sparkline::make('views_data');
    expect($field->resolve($model))->toBe([]);
});

it('Sparkline asBarChart() sets bar type', function () {
    $field = Sparkline::make('views')->asBarChart();
    expect($field->getChartType())->toBe(ChartType::Bar);
});

it('Sparkline defaults to line chart', function () {
    $field = Sparkline::make('views');
    expect($field->getChartType())->toBe(ChartType::Line);
});

it('Sparkline height() sets height', function () {
    $field = Sparkline::make('views')->height(50);
    expect($field->getChartHeight())->toBe(50);
});

it('Sparkline width() sets width', function () {
    $field = Sparkline::make('views')->width(200);
    expect($field->getChartWidth())->toBe(200);
});

it('Sparkline color() sets chart color', function () {
    $field = Sparkline::make('views')->color('#ff0000');
    expect($field->getChartColor())->toBe('#ff0000');
});

it('Sparkline fill saves data to model', function () {
    $model = new SparklineTestModel;
    $field = Sparkline::make('views_data');
    $field->fill($model, [1, 2, 3]);
    expect($model->getAttribute('views_data'))->toBe([1, 2, 3]);
});

it('Sparkline toArray contains chart attributes', function () {
    $field = Sparkline::make('views')->asBarChart()->height(50)->width(200)->color('#ff0000');
    $arr = $field->toArray();

    expect($arr)->toHaveKeys(['attribute', 'type', 'chartType', 'chartHeight', 'chartWidth', 'chartColor'])
        ->and($arr['type'])->toBe('sparkline')
        ->and($arr['chartType'])->toBe('bar')
        ->and($arr['chartHeight'])->toBe(50)
        ->and($arr['chartWidth'])->toBe(200)
        ->and($arr['chartColor'])->toBe('#ff0000');
});
