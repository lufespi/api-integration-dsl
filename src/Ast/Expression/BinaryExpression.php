<?php

declare(strict_types=1);

namespace App\Ast\Expression;

/**
 * Operação binária (ex.: user.age >= 18, a && b, preco * 1.1).
 *
 * A estrutura da árvore já reflete precedência e associatividade: em
 * a - b - c, o nó raiz é (a - b) - c.
 *
 * Produções correspondentes: expr_ou, expr_e, expr_igualdade,
 * expr_relacional, expr_aditiva e expr_multiplicativa (docs/grammar.md).
 */
final readonly class BinaryExpression implements Expression
{
    /**
     * @param BinaryOperator $operator Operador aplicado.
     * @param Expression     $left     Operando à esquerda.
     * @param Expression     $right    Operando à direita.
     * @param int            $line     Linha do operador.
     * @param int            $column   Coluna do operador.
     */
    public function __construct(
        public BinaryOperator $operator,
        public Expression $left,
        public Expression $right,
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
            'type' => 'BinaryExpression',
            'operator' => $this->operator->value,
            'left' => $this->left->toArray(),
            'right' => $this->right->toArray(),
            'line' => $this->line,
            'column' => $this->column,
        ];
    }
}
