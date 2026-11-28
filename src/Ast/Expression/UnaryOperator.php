<?php

declare(strict_types=1);

namespace App\Ast\Expression;

enum UnaryOperator: string
{
    case Not = '!';

    case Negate = '-';
}
