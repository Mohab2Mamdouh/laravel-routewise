<?php

namespace Routewise\Tests;

use Routewise\Field;
use Illuminate\JsonSchema\JsonSchema;

class FieldTest extends TestCase
{
    public function test_one_declaration_gives_the_schema_and_the_rules(): void
    {
        $field = Field::int('How many')->min(1)->max(50)->required();

        $schema = JsonSchema::object(fn ($s) => ['limit' => $field->schema($s)])->toArray();
        $this->assertSame('integer', $schema['properties']['limit']['type']);
        $this->assertSame('How many', $schema['properties']['limit']['description']);
        $this->assertSame(['limit'], $schema['required']);
        $this->assertSame(['limit' => ['required', 'integer', 'min:1', 'max:50']], $field->rules('limit'));
    }

    public function test_lists_validate_their_items_and_values_are_cast(): void
    {
        $this->assertSame(['ids' => ['sometimes', 'array'], 'ids.*' => ['integer', 'min:1']], Field::list('integer')->rules('ids'));
        $this->assertSame([1, 2], Field::list('integer')->cast(['1', '2']));
        $this->assertSame(5, Field::int()->cast('5'));
        $this->assertTrue(Field::bool()->cast('true'));
        $this->assertNull(Field::int()->cast(null));
    }

    public function test_extra_rules_are_added_and_the_field_describes_itself(): void
    {
        $field = Field::enum(['a', 'b'], 'Which one')->rule('not_in:c');

        $this->assertSame(['sometimes', 'string', 'in:a,b', 'not_in:c'], $field->rules('x')['x']);
        $this->assertSame(['type' => 'string', 'description' => 'Which one', 'enum' => ['a', 'b']], $field->toArray());
    }
}
