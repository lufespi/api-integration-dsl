<?php

declare(strict_types=1);

namespace App\Generator;

use App\Exceptions\CompilerException;

/**
 * Erro que impede uma geração segura do PHP (ver as verificações em PhpGenerator).
 *
 * @author lufespi
 * @since 2026-11-26
 */
final class GenerationException extends CompilerException
{
    public function __construct(string $reason, ?int $line = null, ?int $column = null)
    {
        $message = $line === null
            ? 'Erro de geração: ' . $reason
            : sprintf('Erro de geração na linha %d, coluna %d: %s', $line, $column ?? 0, $reason);

        parent::__construct($message, $line, $column);
    }
}
