<?php

declare(strict_types=1);

namespace App\Ast;

/**
 * Tipos de dados declaráveis nos mapeamentos de campos (bloco "map").
 *
 * Indicam ao Generator para qual tipo o valor calculado deve ser convertido
 * antes de ser enviado ao endpoint de destino.
 */
enum DataType: string
{
    case String = 'string';
    case Int = 'int';
    case Float = 'float';
    case Bool = 'bool';
}
