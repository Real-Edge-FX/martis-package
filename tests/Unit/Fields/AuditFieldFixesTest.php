<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Martis\Fields\Date;
use Martis\Fields\DateTime;
use Martis\Fields\KeyValue;

/**
 * Regression guards for the ecosystem-audit field fixes:
 *  - DateTime must keep the time portion (it extends date-only Date).
 *  - fill() overrides must honour a closure-based ->readonly().
 */
function auditFakeModel(): Model
{
    return new class extends Model
    {
        protected $guarded = [];

        public $timestamps = false;
    };
}

it('DateTime resolves with the time portion intact', function () {
    $model = auditFakeModel();
    $model->setAttribute('published_at', \Carbon\Carbon::parse('2026-06-29 14:30:45'));

    expect(DateTime::make('published_at')->resolve($model))->toBe('2026-06-29 14:30:45');
});

it('Date (date-only) still strips the time portion', function () {
    $model = auditFakeModel();
    $model->setAttribute('born_on', \Carbon\Carbon::parse('2026-06-29 14:30:45'));

    expect(Date::make('born_on')->resolve($model))->toBe('2026-06-29');
});

it('KeyValue::fill() honours a closure-based readonly and skips the write', function () {
    $model = auditFakeModel();
    $model->setAttribute('meta', json_encode(['a' => '1']));

    KeyValue::make('meta')
        ->readonly(fn () => true)
        ->fill($model, [['key' => 'b', 'value' => '2']]);

    // Closure resolves to readonly=true, so fill() must be a no-op.
    expect($model->getAttribute('meta'))->toBe(json_encode(['a' => '1']));
});

it('KeyValue::fill() still writes when the readonly closure resolves false', function () {
    $model = auditFakeModel();
    $model->setAttribute('meta', json_encode(['a' => '1']));

    KeyValue::make('meta')
        ->readonly(fn () => false)
        ->fill($model, [['key' => 'b', 'value' => '2']]);

    expect($model->getAttribute('meta'))->toBe(json_encode(['b' => '2']));
});
