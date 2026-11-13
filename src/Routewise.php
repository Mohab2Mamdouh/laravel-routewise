<?php

namespace Routewise;

use Closure;
use InvalidArgumentException;

/**
 * The app's tool list. Register tool sets in a service provider, then hand
 * `Routewise::tools()` to your MCP server:
 *
 *     Routewise::register(InvoiceTools::class);          // a class with all(): DefinedTool[]
 *     Routewise::register(fn () => [DefinedTool::make(...)]);
 *
 *     class AppServer extends \Laravel\Mcp\Server {
 *         public function __construct(Transport $transport) {
 *             parent::__construct($transport);
 *             $this->tools = Routewise::tools();
 *         }
 *     }
 *
 * Sets are resolved on every call, so a closure can decide per signed-in user what to list.
 */
final class Routewise
{
    /** @var array<int, Closure|class-string> */
    private static array $sets = [];

    public static function register(Closure|string $set): void
    {
        self::$sets[] = $set;
    }

    /** @return array<int, DefinedTool> registered sets, then routes (when enabled); the first tool with a name wins */
    public static function tools(): array
    {
        $tools = [];
        foreach (self::$sets as $set) {
            $made = $set instanceof Closure ? $set() : app($set)->all();
            foreach ($made as $tool) {
                if (! $tool instanceof DefinedTool) {
                    throw new InvalidArgumentException('Tool sets must return '.DefinedTool::class.' instances.');
                }
                $tools[$tool->name()] ??= $tool;
            }
        }
        if (config('routewise.routes.enabled')) {
            foreach (app(RouteTools::class)->all() as $tool) {
                $tools[$tool->name()] ??= $tool;
            }
        }

        return array_values($tools);
    }

    public static function flush(): void
    {
        self::$sets = [];
    }
}
