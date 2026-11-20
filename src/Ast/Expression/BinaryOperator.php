<?php

declare(strict_types=1);

namespace App\Ast\Expression;

/**
 * Operadores binários da DSL.
 *
 * Precedência (da menor para a maior) e associatividade, conforme
 * docs/grammar.md, seção 6:
 *   1. ||                  esquerda
 *   2. &&                  esquerda
 *   3. == !=               não associativos
 *   4. < <= > >=           não associativos
 *   5. + -                 esquerda
 *   6. * / %               esquerda
 */
enum BinaryOperator: string
{
    case Or = '||';
    case And = '&&';
    case Equal = '==';
    case NotEqual = '!=';
    case Less = '<';
    case LessEqual = '<=';
    case Greater = '>';
    case GreaterEqual = '>=';

    /** Soma numérica ou concatenação, quando ao menos um operando é texto. */
    case Add = '+';
    case Subtract = '-';
    case Multiply = '*';
    case Divide = '/';
    case Modulo = '%';
}
