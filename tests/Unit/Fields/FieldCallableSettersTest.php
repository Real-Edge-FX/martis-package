<?php

declare(strict_types=1);

namespace Tests\Unit\Fields;

use Martis\Fields\BooleanGroup;
use Martis\Fields\MultiSelect;
use Martis\Fields\Select;
use Martis\Fields\Text;
use PHPUnit\Framework\TestCase;

/**
 * Phase 2 — every setter that historically accepted a static value also
 * accepts a closure that resolves at render time. The closure receives
 * the active Request (or null when no HTTP context is bootstrapped) so
 * the result can vary per user, per locale, per tenant, etc.
 *
 * Tests run with PHPUnit's bare TestCase so `safeRequest()` returns
 * `null`. That's the harder code path: any closure that crashes when
 * the request is unavailable would surface here first.
 */
class FieldCallableSettersTest extends TestCase
{
    // -------------------------------------------------------------------
    // Select / MultiSelect / BooleanGroup — options(callable)
    // -------------------------------------------------------------------

    public function test_select_options_accepts_closure_resolved_lazily(): void
    {
        $invocations = 0;
        $field = Select::make('plan')->options(function () use (&$invocations) {
            $invocations++;

            return ['Free' => 'free', 'Pro' => 'pro'];
        });

        // Closure should not have run yet.
        $this->assertSame(0, $invocations);

        $options = $field->getOptions();

        $this->assertSame(1, $invocations);
        $this->assertSame([
            ['label' => 'Free', 'value' => 'free'],
            ['label' => 'Pro', 'value' => 'pro'],
        ], $options);
    }

    public function test_select_options_closure_falls_back_to_empty_when_non_array_returned(): void
    {
        $field = Select::make('plan')->options(fn () => 'not-an-array');

        $this->assertSame([], $field->getOptions());
    }

    public function test_select_options_static_array_replaces_resolver(): void
    {
        $field = Select::make('plan')
            ->options(fn () => ['Pro' => 'pro'])
            ->options(['Free' => 'free']);

        $this->assertSame(
            [['label' => 'Free', 'value' => 'free']],
            $field->getOptions(),
        );
    }

    public function test_multi_select_options_accepts_closure_with_grouped_payload(): void
    {
        $field = MultiSelect::make('skills')->options(fn () => [
            'Backend' => ['PHP' => 'php', 'Go' => 'go'],
            'Frontend' => ['React' => 'react'],
        ]);

        $this->assertSame([
            ['label' => 'PHP', 'value' => 'php', 'group' => 'Backend'],
            ['label' => 'Go', 'value' => 'go', 'group' => 'Backend'],
            ['label' => 'React', 'value' => 'react', 'group' => 'Frontend'],
        ], $field->getOptions());
    }

    public function test_boolean_group_options_accepts_closure(): void
    {
        $field = BooleanGroup::make('permissions')
            ->options(fn () => ['create' => 'Create', 'update' => 'Update']);

        $this->assertSame(['create' => 'Create', 'update' => 'Update'], $field->getOptions());
    }

    // -------------------------------------------------------------------
    // required / nullable — closures
    // -------------------------------------------------------------------

    public function test_required_with_closure_short_circuits_to_required_in_rules(): void
    {
        $field = Text::make('reason')->required(fn () => true);

        $this->assertTrue($field->isRequired());
        $this->assertContains('required', $field->buildRules());
    }

    public function test_required_with_false_returning_closure_falls_back_to_sometimes(): void
    {
        $field = Text::make('reason')->required(fn () => false);

        $this->assertFalse($field->isRequired());
        $this->assertContains('sometimes', $field->buildRules());
    }

    public function test_nullable_with_closure_resolves_lazily(): void
    {
        $field = Text::make('subtitle')->nullable(fn () => true);

        $this->assertTrue($field->isNullable());
        $this->assertContains('nullable', $field->buildRules());
    }

    public function test_nullable_static_bool_still_works(): void
    {
        $field = Text::make('subtitle')->nullable(false);

        $this->assertFalse($field->isNullable());
    }

    // -------------------------------------------------------------------
    // help / placeholder / tooltip — closures
    // -------------------------------------------------------------------

    public function test_help_accepts_closure_and_resolves_on_demand(): void
    {
        $invocations = 0;
        $field = Text::make('username')->help(function () use (&$invocations) {
            $invocations++;

            return 'Letters and numbers only.';
        });

        $this->assertSame(0, $invocations);
        $this->assertSame('Letters and numbers only.', $field->getHelp());
        $this->assertSame(1, $invocations);
    }

    public function test_placeholder_closure_returns_string(): void
    {
        $field = Text::make('email')->placeholder(fn () => 'you@company.com');

        $this->assertSame('you@company.com', $field->getPlaceholder());
    }

    public function test_tooltip_accepts_closure(): void
    {
        $field = Text::make('username')->tooltip(fn () => 'Hover me');

        $this->assertSame('Hover me', $field->getTooltip());
    }

    public function test_tooltip_closure_returning_non_string_falls_back_to_null(): void
    {
        $field = Text::make('username')->tooltip(fn () => null);

        $this->assertNull($field->getTooltip());
    }

    // -------------------------------------------------------------------
    // label — withLabel(string|Closure)
    // -------------------------------------------------------------------

    public function test_with_label_overrides_constructor_label(): void
    {
        $field = Text::make('user_name', 'Original')->withLabel('Custom');

        $this->assertSame('Custom', $field->label());
    }

    public function test_with_label_accepts_closure(): void
    {
        $field = Text::make('user_name')->withLabel(fn () => 'From Closure');

        $this->assertSame('From Closure', $field->label());
    }

    public function test_with_label_closure_returning_non_string_falls_back_to_static_label(): void
    {
        $field = Text::make('user_name', 'Fallback')->withLabel(fn () => null);

        $this->assertSame('Fallback', $field->label());
    }

    // -------------------------------------------------------------------
    // rules(closure)
    // -------------------------------------------------------------------

    public function test_rules_accepts_closure_resolved_at_build_rules_time(): void
    {
        $invocations = 0;
        $field = Text::make('field')->rules(function () use (&$invocations) {
            $invocations++;

            return ['email', 'max:255'];
        });

        $this->assertSame(0, $invocations);
        $rules = $field->buildRules();

        $this->assertSame(1, $invocations);
        $this->assertContains('email', $rules);
        $this->assertContains('max:255', $rules);
    }

    public function test_rules_static_array_after_closure_resets_resolver(): void
    {
        $field = Text::make('field')
            ->rules(fn () => ['email'])
            ->rules(['min:1']);

        $rules = $field->buildRules();

        $this->assertContains('min:1', $rules);
        $this->assertNotContains('email', $rules);
    }

    public function test_rules_closure_returning_non_array_keeps_extra_rules_empty(): void
    {
        $field = Text::make('field')->rules(fn () => 'not-an-array');

        $rules = $field->buildRules();

        // Should not throw, should not contain the bogus value.
        $this->assertNotContains('not-an-array', $rules);
    }

    // -------------------------------------------------------------------
    // toArray() picks up resolved values
    // -------------------------------------------------------------------

    public function test_to_array_serializes_resolved_closure_values(): void
    {
        $field = Text::make('email')
            ->required(fn () => true)
            ->placeholder(fn () => 'you@company.com')
            ->help(fn () => 'Help me')
            ->tooltip(fn () => 'Tooltip text')
            ->withLabel(fn () => 'Email Address');

        $payload = $field->toArray();

        $this->assertTrue($payload['required']);
        $this->assertSame('you@company.com', $payload['placeholder']);
        $this->assertSame('Help me', $payload['helpText']);
        $this->assertSame('Tooltip text', $payload['tooltip']);
        $this->assertSame('Email Address', $payload['label']);
    }
}
