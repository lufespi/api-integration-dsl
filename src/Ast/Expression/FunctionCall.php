<?php

declare(strict_types=1);

namespace App\Ast\Expression;

/**
 * Chamada de função embutida, como `env("CRM_TOKEN")`.
 *
 * @author paulacsilva
 * @since 2026-11-20
 */
final readonly class FunctionCall implements Expression
{
    /** @param list<Expression> $arguments */
    public function __construct(
        public string $name,
        public array $arguments,
        public int $line,
        public int $column,
    ) {
    }

    public function toArray(): array
    {
        return [
            'type' => 'FunctionCall',
            'name' => $this->name,
            'arguments' => array_map(static fn (Expression $argument): array => $argument->toArray(), $this->arguments),
            'line' => $this->line,
            'column' => $this->column,
        ];
    }
}
