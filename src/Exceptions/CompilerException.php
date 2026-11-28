<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base dos erros léxicos, sintáticos, semânticos e de geração.
 *
 * getSourceLine()/getSourceColumn() indicam a posição no arquivo .dsl; os nomes
 * evitam conflito com Exception::getLine(), que é final.
 *
 * @author paulacsilva
 * @since 2026-11-20
 */
abstract class CompilerException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?int $sourceLine = null,
        private readonly ?int $sourceColumn = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getSourceLine(): ?int
    {
        return $this->sourceLine;
    }

    public function getSourceColumn(): ?int
    {
        return $this->sourceColumn;
    }
}
