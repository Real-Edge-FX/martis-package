<?php

namespace Tests\Unit;

use Martis\Actions\ActionFields;
use PHPUnit\Framework\TestCase;

class ActionFieldsTest extends TestCase
{
    public function test_from_request(): void
    {
        $fields = ActionFields::fromRequest(['name' => 'John', 'email' => 'john@test.com']);
        $this->assertEquals('John', $fields->get('name'));
        $this->assertEquals('john@test.com', $fields->get('email'));
    }

    public function test_get_with_default(): void
    {
        $fields = ActionFields::fromRequest([]);
        $this->assertEquals('default', $fields->get('missing', 'default'));
    }

    public function test_dynamic_property_access(): void
    {
        $fields = ActionFields::fromRequest(['title' => 'Hello']);
        $this->assertEquals('Hello', $fields->title);
    }

    public function test_isset_on_dynamic_property(): void
    {
        $fields = ActionFields::fromRequest(['title' => 'Hello']);
        $this->assertTrue(isset($fields->title));
        $this->assertFalse(isset($fields->missing));
    }

    public function test_all_returns_complete_data(): void
    {
        $data = ['a' => 1, 'b' => 2];
        $fields = ActionFields::fromRequest($data);
        $this->assertEquals($data, $fields->all());
    }

    public function test_to_collection(): void
    {
        $fields = ActionFields::fromRequest(['x' => 'y']);
        $collection = $fields->toCollection();
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $collection);
        $this->assertEquals('y', $collection->get('x'));
    }
}
