<?php

declare(strict_types=1);

namespace App\Ast\Expression;

/**
 * Operadores unários prefixados (precedência mais alta entre os operadores,
 * associatividade à direita).
 */
enum UnaryOperator: string
{
    /** Negação lógica. */
    case Not = '!';

    /** Negação aritmética (troca de sinal). */
    case Negate = '-';
}
