<?php

declare(strict_types=1);

namespace App\Ast\Expression;

/**
 * @author paulacsilva
 * @since 2026-11-20
 */
final readonly class UnaryExpression implements Expression
{
    public function __construct(
        public UnaryOperator $operator,
        public Expression $operand,
        public int $line,
        public int $column,
    ) {
    }

    public function toArray(): array
    {
        return [
            'type' => 'UnaryExpression',
            'operator' => $this->operator->value,
            'operand' => $this->operand->toArray(),
            'line' => $this->line,
            'column' => $this->column,
        ];
    }
}
