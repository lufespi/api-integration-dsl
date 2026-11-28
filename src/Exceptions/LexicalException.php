<?php

declare(strict_types=1);

namespace App\Exceptions;

final class LexicalException extends CompilerException
{
    public function __construct(string $reason, int $line, int $column)
    {
        parent::__construct(
            sprintf('Erro léxico na linha %d, coluna %d: %s', $line, $column, $reason),
            $line,
            $column,
        );
    }
}
