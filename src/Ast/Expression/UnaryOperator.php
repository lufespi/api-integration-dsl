<?php

declare(strict_types=1);

namespace App\Ast\Expression;

/**
 * @author paulacsilva
 * @since 2026-11-20
 */
enum UnaryOperator: string
{
    case Not = '!';

    case Negate = '-';
}
