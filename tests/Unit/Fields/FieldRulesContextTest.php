<?php

declare(strict_types=1);

namespace Tests\Unit\Fields;

use Martis\Fields\Text;
use PHPUnit\Framework\TestCase;

class FieldRulesContextTest extends TestCase
{
    public function test_creation_rules_merge_into_create_context(): void
    {
        $field = Text::make('password')
            ->rules(['min:8'])
            ->creationRules(['required']);

        $createRules = $field->buildRules('create');
        $updateRules = $field->buildRules('update');
        $bareRules = $field->buildRules();

        $this->assertContains('required', $createRules);
        $this->assertContains('min:8', $createRules);

        $this->assertNotContains('required', $updateRules);
        $this->assertContains('min:8', $updateRules);

        $this->assertNotContains('required', $bareRules);
    }

    public function test_update_rules_merge_into_update_context(): void
    {
        $field = Text::make('email')
            ->rules(['email'])
            ->updateRules(['unique:users,email,1']);

        $createRules = $field->buildRules('create');
        $updateRules = $field->buildRules('update');

        $this->assertNotContains('unique:users,email,1', $createRules);
        $this->assertContains('email', $createRules);

        $this->assertContains('unique:users,email,1', $updateRules);
        $this->assertContains('email', $updateRules);
    }

    public function test_creation_and_update_rules_can_be_chained(): void
    {
        $field = Text::make('password')
            ->rules(['min:8'])
            ->creationRules(['required'])
            ->updateRules(['nullable']);

        $createRules = $field->buildRules('create');
        $updateRules = $field->buildRules('update');

        $this->assertContains('required', $createRules);
        $this->assertNotContains('nullable', $createRules);

        $this->assertContains('nullable', $updateRules);
        $this->assertNotContains('required', $updateRules);
    }

    public function test_immutable_serializes_in_to_array(): void
    {
        $field = Text::make('slug')->immutable();

        $array = $field->toArray();

        $this->assertTrue($array['immutable']);
        $this->assertTrue($field->isImmutable());
    }

    public function test_immutable_default_is_false(): void
    {
        $field = Text::make('name');

        $array = $field->toArray();

        $this->assertFalse($array['immutable']);
        $this->assertFalse($field->isImmutable());
    }

    public function test_creation_and_update_rules_appear_in_to_array_when_set(): void
    {
        $field = Text::make('password')
            ->creationRules(['required'])
            ->updateRules(['sometimes']);

        $array = $field->toArray();

        $this->assertEquals(['required'], $array['creationRules']);
        $this->assertEquals(['sometimes'], $array['updateRules']);
    }

    public function test_creation_and_update_rules_are_null_in_to_array_when_not_set(): void
    {
        $field = Text::make('name');

        $array = $field->toArray();

        $this->assertNull($array['creationRules']);
        $this->assertNull($array['updateRules']);
    }

    public function test_default_value_can_be_a_closure(): void
    {
        $field = Text::make('owner_id')->default(fn () => 'computed-value');

        $this->assertEquals('computed-value', $field->getDefaultValue());
    }

    public function test_default_value_static_passes_through(): void
    {
        $field = Text::make('status')->default('active');

        $this->assertEquals('active', $field->getDefaultValue());
    }

    public function test_readonly_accepts_a_boolean(): void
    {
        $field = Text::make('id')->readonly();
        $this->assertTrue($field->toArray()['readonly']);

        $field2 = Text::make('name')->readonly(false);
        $this->assertFalse($field2->toArray()['readonly']);
    }

    public function test_readonly_accepts_a_closure(): void
    {
        $always = Text::make('a')->readonly(fn () => true);
        $never = Text::make('b')->readonly(fn () => false);

        $this->assertTrue($always->toArray()['readonly']);
        $this->assertFalse($never->toArray()['readonly']);
    }
}
