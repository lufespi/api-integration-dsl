<?php

declare(strict_types=1);

namespace App\Ast;

/**
 * @author paulacsilva
 * @since 2026-11-20
 */
enum HttpMethod: string
{
    case Get = 'GET';
    case Post = 'POST';
    case Put = 'PUT';
    case Patch = 'PATCH';
    case Delete = 'DELETE';
}
