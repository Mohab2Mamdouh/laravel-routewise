<?php

namespace Routewise\Tests;

use Routewise\DefinedTool;
use Routewise\Events\ToolCalled;
use Routewise\Field;
use Routewise\ResultView;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use RuntimeException;

class DefinedToolTest extends TestCase
{
    public function test_arguments_are_validated_cast_and_defaulted_before_the_handler_runs(): void
    {
        $tool = DefinedTool::make('things_list', 'read', 'List things.', [
            'limit' => Field::int()->min(1)->default(10), 'id' => Field::id()->required(),
        ], fn ($a) => $a);

        $this->assertSame([true, ['id' => 5, 'limit' => 10]], $this->invoke($tool, ['id' => '5']));
        [$ok, $error] = $this->invoke($tool, ['id' => 0]);
        $this->assertFalse($ok);
        $this->assertStringContainsString('id', $error);
    }

    public function test_the_kind_sets_the_annotations_the_confirmation_and_the_result_view(): void
    {
        $read = DefinedTool::make('a', 'read', 'Read.', [], fn () => 1)->toArray();
        $delete = DefinedTool::make('b', 'delete', 'Delete it.', [], fn () => 1)->toArray();

        $this->assertTrue($read['annotations']['readOnlyHint']);
        $this->assertSame(ResultView::URI, $read['_meta']['ui']['resourceUri']);
        $this->assertTrue($delete['annotations']['destructiveHint']);
        $this->assertSame('Delete it. Confirm with the user first.', $delete['description']);
        $this->assertArrayNotHasKey('_meta', $delete);

        $this->expectException(InvalidArgumentException::class);
        DefinedTool::make('c', 'remove', '', [], fn () => 1);
    }

    public function test_a_failure_becomes_a_tool_error_and_every_call_is_announced_without_argument_values(): void
    {
        Event::fake([ToolCalled::class]);
        $tool = DefinedTool::make('pay', 'write', 'Pay.', ['card' => Field::string()], fn () => throw new RuntimeException('Declined.'));

        $this->assertSame([false, 'Declined.'], $this->invoke($tool, ['card' => '4111']));
        Event::assertDispatched(ToolCalled::class, fn (ToolCalled $e) => $e->tool === 'pay' && $e->kind === 'write' && ! $e->ok
            && $e->error === 'Declined.' && $e->argumentKeys === ['card'] && $e->userId === null);
    }
}
