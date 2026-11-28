<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * @author Comassetto
 * @since 2026-11-24
 */
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
