<?php

declare(strict_types=1);

namespace App\Ast;

/** Tipo declarado em um campo do bloco `map`; orienta a conversão feita pelo Generator. */
enum DataType: string
{
    case String = 'string';
    case Int = 'int';
    case Float = 'float';
    case Bool = 'bool';
}
