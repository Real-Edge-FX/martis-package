<?php

declare(strict_types=1);

namespace Tests\Unit\Fields;

use Illuminate\Validation\Rule;
use Martis\Fields\Text;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * v2.10.0: only the exact `required` rule makes a field required. A
 * conditional sibling (`required_if`, `required_with`, ...) is evaluated by
 * Laravel at validation time, so it neither flips the `required` flag nor
 * gets a literal `required` prepended (Nova 5 compares the same way).
 */
class FieldRequiredRulesTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function conditionalRules(): array
    {
        return [
            'required_if' => ['required_if:type,company'],
            'required_unless' => ['required_unless:type,person'],
            'required_with' => ['required_with:first_name'],
            'required_with_all' => ['required_with_all:a,b'],
            'required_without' => ['required_without:first_name'],
            'required_without_all' => ['required_without_all:a,b'],
            'required_if_accepted' => ['required_if_accepted:terms'],
            'required_if_declined' => ['required_if_declined:terms'],
            'required_array_keys' => ['required_array_keys:a'],
        ];
    }

    #[DataProvider('conditionalRules')]
    public function test_a_conditional_required_string_rule_is_not_required(string $rule): void
    {
        $field = Text::make('legal_name')->rules(['nullable', 'string', $rule]);

        $this->assertFalse($field->isRequired());
        $this->assertFalse($field->toArray()['required']);
        $this->assertSame(['nullable', 'string', $rule], $field->buildRules());
        $this->assertNotContains('required', $field->buildRules());
    }

    #[DataProvider('conditionalRules')]
    public function test_a_conditional_required_rule_without_nullable_leaves_out_sometimes(string $rule): void
    {
        $field = Text::make('legal_name')->rules(['string', $rule]);

        $this->assertFalse($field->isRequired());
        // `sometimes` would skip the rule for a key missing from the input.
        $this->assertSame(['string', $rule], $field->buildRules());
    }

    public function test_a_field_without_any_required_rule_keeps_sometimes(): void
    {
        $this->assertSame(['sometimes', 'string'], Text::make('a')->rules(['string'])->buildRules());
        $this->assertSame(['nullable', 'string'], Text::make('a')->nullable()->rules(['string'])->buildRules());
    }

    public function test_a_conditional_rule_in_the_create_rules_drops_the_base_sometimes(): void
    {
        $rules = Text::make('a')->rules(['string'])->creationRules(['required_if:type,company'])->buildRules('create');

        $this->assertSame(['string', 'required_if:type,company'], $rules);
        $this->assertSame(['sometimes', 'string'], Text::make('a')->rules(['string'])->creationRules(['required_if:type,company'])->buildRules('update'));
    }

    public function test_the_literal_required_rule_still_flags_the_field(): void
    {
        $field = Text::make('name')->rules(['required', 'string']);

        $this->assertTrue($field->isRequired());
        $this->assertTrue($field->toArray()['required']);
        $this->assertSame(['required', 'string'], $field->buildRules());
    }

    public function test_rule_required_if_true_is_required_and_false_is_not(): void
    {
        $this->assertTrue(Text::make('a')->rules([Rule::requiredIf(true)])->isRequired());
        $this->assertTrue(Text::make('a')->rules([Rule::requiredIf(fn () => true)])->isRequired());
        $this->assertFalse(Text::make('a')->rules([Rule::requiredIf(false)])->isRequired());
        $this->assertFalse(Text::make('a')->rules([Rule::requiredIf(fn () => false)])->isRequired());
    }

    public function test_a_rule_object_that_merely_mentions_required_is_not_required(): void
    {
        $this->assertFalse(Text::make('a')->rules([Rule::in(['required', 'x'])])->isRequired());
    }

    public function test_the_explicit_required_flag_still_wins_over_a_conditional_rule(): void
    {
        $field = Text::make('a')->required()->rules(['required_if:type,company']);

        $this->assertTrue($field->isRequired());
        $this->assertSame('required', $field->buildRules()[0]);
    }
}
