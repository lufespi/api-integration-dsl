<?php

declare(strict_types=1);

namespace App\Ast\Expression;

/** Operação binária. A forma da árvore já reflete precedência e associatividade. */
final readonly class BinaryExpression implements Expression
{
    public function __construct(
        public BinaryOperator $operator,
        public Expression $left,
        public Expression $right,
        public int $line,
        public int $column,
    ) {
    }

    public function toArray(): array
    {
        return [
            'type' => 'BinaryExpression',
            'operator' => $this->operator->value,
            'left' => $this->left->toArray(),
            'right' => $this->right->toArray(),
            'line' => $this->line,
            'column' => $this->column,
        ];
    }
}
