<?php

declare(strict_types=1);

namespace App\Ast\Expression;

/**
 * Operação unária prefixada (ex.: !user.blocked, -desconto).
 *
 * Produção correspondente: expr_unaria (docs/grammar.md).
 */
final readonly class UnaryExpression implements Expression
{
    /**
     * @param UnaryOperator $operator Operador aplicado.
     * @param Expression    $operand  Operando.
     * @param int           $line     Linha do operador.
     * @param int           $column   Coluna do operador.
     */
    public function __construct(
        public UnaryOperator $operator,
        public Expression $operand,
        public int $line,
        public int $column,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
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
