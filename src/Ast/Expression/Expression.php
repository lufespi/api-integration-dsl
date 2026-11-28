<?php

declare(strict_types=1);

namespace App\Ast\Expression;

use App\Ast\Node;

/** Literal, referência a campo, chamada de função ou operação unária/binária. */
interface Expression extends Node
{
}
