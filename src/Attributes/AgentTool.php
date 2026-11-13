<?php

namespace Routewise\Attributes;

use Attribute;

/**
 * Put on a controller method to say how its route appears as a tool:
 *
 *     #[AgentTool(description: 'List invoices, newest first. Filter by status or client.')]
 *     public function index(ListInvoicesRequest $request) { ... }
 *
 * `hidden: true` keeps the route out of the tool list.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final class AgentTool
{
    /**
     * @param  array<string, string>  $fields  descriptions for arguments, keyed by argument name
     */
    public function __construct(
        public readonly ?string $description = null,
        public readonly ?string $name = null,
        public readonly ?string $kind = null,
        public readonly bool $hidden = false,
        public readonly array $fields = [],
    ) {}
}
