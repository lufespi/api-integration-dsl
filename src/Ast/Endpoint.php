<?php

declare(strict_types=1);

namespace App\Ast;

/**
 * Declaração `endpoint` (produção decl_endpoint). O caminho sempre começa por "/".
 *
 * @author paulacsilva
 * @since 2026-11-20
 */
final readonly class Endpoint implements Node
{
    public function __construct(
        public string $name,
        public HttpMethod $method,
        public string $path,
        public int $line,
        public int $column,
    ) {
    }

    public function toArray(): array
    {
        return [
            'type' => 'Endpoint',
            'name' => $this->name,
            'method' => $this->method->value,
            'path' => $this->path,
            'line' => $this->line,
            'column' => $this->column,
        ];
    }
}
