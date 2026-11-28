<?php

declare(strict_types=1);

namespace App\Parser;

/**
 * @author paulacsilva
 * @since 2026-11-20
 */
final readonly class SyntaxError
{
    public function __construct(
        public string $message,
        public int $line,
        public int $column,
    ) {
    }

    public function format(): string
    {
        return sprintf('Erro sintático na linha %d, coluna %d: %s', $this->line, $this->column, $this->message);
    }
}
