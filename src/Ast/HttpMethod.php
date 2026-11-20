<?php

declare(strict_types=1);

namespace App\Ast;

/**
 * Métodos HTTP aceitos na declaração de endpoints.
 *
 * O valor de cada caso é o nome do método em maiúsculas, como exige o
 * contrato da AST (campo "method" de Endpoint).
 */
enum HttpMethod: string
{
    case Get = 'GET';
    case Post = 'POST';
    case Put = 'PUT';
    case Patch = 'PATCH';
    case Delete = 'DELETE';
}
