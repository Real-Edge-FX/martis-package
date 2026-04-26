<?php

declare(strict_types=1);

namespace Tests\Unit\Fields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Martis\Fields\Text;
use PHPUnit\Framework\TestCase;

/**
 * Phase 2 follow-up — `resolveUsing`, `fillUsing`, `displayUsing`
 * now forward the active `Request` as their 4th argument so the
 * callback can branch per-user / per-locale / per-tenant without
 * calling the `request()` helper.
 *
 * Plus: `displayUsing` accepts an array of callables to compose a
 * pipeline. Each entry receives the output of the previous one.
 *
 * Tests run with PHPUnit's bare TestCase, so `safeRequest()` returns
 * `null`. The 4th-parameter contract MUST tolerate that — the new
 * arg is opt-in and never assumed non-null.
 */
class FieldHooksRequestParamTest extends TestCase
{
    // -------------------------------------------------------------------
    // resolveUsing — 4th param = ?Request
    // -------------------------------------------------------------------

    public function test_resolve_using_callback_with_three_params_still_works(): void
    {
        $field = Text::make('name')->resolveUsing(
            fn (mixed $value, Model $model, string $attribute): string => "[{$attribute}] {$value}",
        );

        $stub = new StubModel(['name' => 'Alice']);

        $this->assertSame('[name] Alice', $field->resolve($stub));
    }

    public function test_resolve_using_callback_receives_request_as_fourth_param_when_available(): void
    {
        $captured = null;
        $field = Text::make('name')->resolveUsing(
            function (mixed $value, Model $model, string $attribute, ?Request $request) use (&$captured): mixed {
                $captured = $request;

                return $value;
            },
        );

        $stub = new StubModel(['name' => 'Alice']);
        $field->resolve($stub);

        // No HTTP context bootstrapped → request must be null, not a TypeError.
        $this->assertNull($captured);
    }

    // -------------------------------------------------------------------
    // fillUsing — 4th param = ?Request
    // -------------------------------------------------------------------

    public function test_fill_using_callback_with_three_params_still_works(): void
    {
        $field = Text::make('name')->fillUsing(
            function (Model $model, mixed $value, string $attribute): void {
                $model->setAttribute($attribute, strtoupper((string) $value));
            },
        );

        $stub = new StubModel;
        $field->fill($stub, 'alice');

        $this->assertSame('ALICE', $stub->getAttribute('name'));
    }

    public function test_fill_using_callback_receives_request_as_fourth_param_when_available(): void
    {
        $captured = 'sentinel';
        $field = Text::make('name')->fillUsing(
            function (Model $model, mixed $value, string $attribute, ?Request $request) use (&$captured): void {
                $captured = $request;
                $model->setAttribute($attribute, $value);
            },
        );

        $stub = new StubModel;
        $field->fill($stub, 'alice');

        $this->assertNull($captured);
    }

    // -------------------------------------------------------------------
    // displayUsing — 4th param = ?Request
    // -------------------------------------------------------------------

    public function test_display_using_callback_with_three_params_still_works(): void
    {
        $field = Text::make('amount')->displayUsing(
            fn (mixed $value, Model $model, string $attribute): string => "$ {$value}",
        );

        $stub = new StubModel(['amount' => '42']);

        $this->assertSame('$ 42', $field->resolveForDisplay($stub));
    }

    public function test_display_using_callback_receives_request_as_fourth_param_when_available(): void
    {
        $captured = null;
        $field = Text::make('amount')->displayUsing(
            function (mixed $value, Model $model, string $attribute, ?Request $request) use (&$captured): mixed {
                $captured = $request;

                return $value;
            },
        );

        $stub = new StubModel(['amount' => '42']);
        $field->resolveForDisplay($stub);

        $this->assertNull($captured);
    }

    // -------------------------------------------------------------------
    // displayUsing — chained pipeline
    // -------------------------------------------------------------------

    public function test_display_using_accepts_array_pipeline_each_entry_gets_previous_output(): void
    {
        $field = Text::make('amount')->displayUsing([
            fn (mixed $v): float => (float) $v,
            fn (float $v): string => number_format($v, 2),
            fn (string $v): string => "$ {$v}",
        ]);

        $stub = new StubModel(['amount' => '42.5']);

        $this->assertSame('$ 42.50', $field->resolveForDisplay($stub));
    }

    public function test_display_using_pipeline_validates_every_entry_is_callable_at_definition_time(): void
    {
        $field = Text::make('amount');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('displayUsing(): entry 1 is not callable.');

        $field->displayUsing([
            fn ($v) => $v,
            'not-a-callable-string',
            fn ($v) => $v,
        ]);
    }

    public function test_display_using_pipeline_each_callback_can_inspect_model_attribute_request(): void
    {
        $captures = [];
        $field = Text::make('amount')->displayUsing([
            function (mixed $v, Model $m, string $attr, ?Request $r) use (&$captures): mixed {
                $captures[] = ['stage' => 1, 'value' => $v, 'attribute' => $attr, 'request' => $r];

                return ((float) $v) * 2;
            },
            function (mixed $v, Model $m, string $attr, ?Request $r) use (&$captures): mixed {
                $captures[] = ['stage' => 2, 'value' => $v, 'attribute' => $attr, 'request' => $r];

                return "{$v}!";
            },
        ]);

        $stub = new StubModel(['amount' => '3']);
        $out = $field->resolveForDisplay($stub);

        $this->assertSame('6!', $out);
        $this->assertCount(2, $captures);
        $this->assertSame('3', $captures[0]['value']);
        $this->assertSame(6.0, $captures[1]['value']);
        $this->assertSame('amount', $captures[0]['attribute']);
        $this->assertNull($captures[0]['request']);
    }

    // -------------------------------------------------------------------
    // displayUsing — single callable still works after chain support
    // -------------------------------------------------------------------

    public function test_display_using_single_callable_still_works(): void
    {
        $field = Text::make('amount')->displayUsing(fn ($v) => "$ {$v}");
        $stub = new StubModel(['amount' => '42']);

        $this->assertSame('$ 42', $field->resolveForDisplay($stub));
    }

    public function test_display_using_callable_overrides_previous_pipeline(): void
    {
        $field = Text::make('amount')
            ->displayUsing([fn ($v) => $v.'-A', fn ($v) => $v.'-B'])
            ->displayUsing(fn ($v) => $v.'-C');

        $stub = new StubModel(['amount' => 'X']);

        $this->assertSame('X-C', $field->resolveForDisplay($stub));
    }
}

/**
 * Tiny in-memory Eloquent stand-in. Avoids booting a full DB just
 * to read/write a single attribute.
 */
class StubModel extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
    }
}
