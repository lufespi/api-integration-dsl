<?php

declare(strict_types=1);

namespace App\Ast\Expression;

use App\Ast\Node;

/**
 * Expressão da DSL: literal, referência a campo, chamada de função, operação
 * unária ou binária.
 *
 * Usada em cabeçalhos, na condição "when" e nos mapeamentos de campos.
 * Produção correspondente: expressao e seus níveis de precedência
 * (docs/grammar.md, seção 5).
 */
interface Expression extends Node
{
}
