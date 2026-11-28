<?php

declare(strict_types=1);

namespace App\Ast\Expression;

enum LiteralType: string
{
    case String = 'string';
    case Int = 'int';
    case Float = 'float';
    case Bool = 'bool';
    case Null = 'null';
}
