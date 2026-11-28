<?php

declare(strict_types=1);

namespace App\Ast;

/**
 * Nó da AST, contrato entre o Parser e as etapas seguintes.
 *
 * Todos os nós, exceto Program, guardam linha e coluna (a partir de 1): a do
 * operador em expressões unárias e binárias e, nos demais, a do primeiro token.
 */
interface Node
{
    /**
     * Árvore em arrays associativos, com a chave "type" (exceto em EndpointRef).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
