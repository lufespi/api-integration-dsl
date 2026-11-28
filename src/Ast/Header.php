<?php

declare(strict_types=1);

namespace App\Ast;

use App\Ast\Expression\Expression;

/**
 * Declaração `header "Nome" = expressão;` (produção decl_header).
 *
 * @author paulacsilva
 * @since 2026-11-20
 */
final readonly class Header implements Node
{
    public function __construct(
        public string $name,
        public Expression $value,
        public int $line,
        public int $column,
    ) {
    }

    public function toArray(): array
    {
        return [
            'type' => 'Header',
            'name' => $this->name,
            'value' => $this->value->toArray(),
            'line' => $this->line,
            'column' => $this->column,
        ];
    }
}
