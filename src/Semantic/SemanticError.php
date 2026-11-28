<?php

declare(strict_types=1);

namespace App\Semantic;

/**
 * @author pedro.augusto
 * @since 2026-11-28
 */
final readonly class SemanticError
{
    public function __construct(
        public string $message,
        public int $line,
        public int $column,
    ) {
    }

    public function format(): string
    {
        return sprintf('Erro semântico na linha %d, coluna %d: %s', $this->line, $this->column, $this->message);
    }
}
