<?php

declare(strict_types=1);

namespace App\Ast;

use App\Ast\Expression\Expression;

/** Declaração `integration` (produção decl_integracao). */
final readonly class Integration implements Node
{
    /** @param list<FieldMapping> $mappings vazio quando não há `map` (o registro é enviado inteiro) */
    public function __construct(
        public string $name,
        public EndpointRef $source,
        public ?string $alias,
        public EndpointRef $target,
        public ?Expression $condition,
        public array $mappings,
        public int $line,
        public int $column,
    ) {
    }

    public function toArray(): array
    {
        return [
            'type' => 'Integration',
            'name' => $this->name,
            'source' => $this->source->toArray(),
            'target' => $this->target->toArray(),
            'line' => $this->line,
            'column' => $this->column,
            'alias' => $this->alias,
            'condition' => $this->condition?->toArray(),
            'mappings' => array_map(static fn (FieldMapping $mapping): array => $mapping->toArray(), $this->mappings),
        ];
    }
}
