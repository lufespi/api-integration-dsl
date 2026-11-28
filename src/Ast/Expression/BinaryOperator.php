<?php

declare(strict_types=1);

namespace App\Ast\Expression;

/**
 * @author paulacsilva
 * @since 2026-11-20
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
