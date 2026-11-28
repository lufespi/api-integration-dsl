<?php

declare(strict_types=1);

namespace App\Ast\Expression;

/** Caminho de campo, como `pedido.cliente.email`; o primeiro segmento é o alias. */
final readonly class FieldReference implements Expression
{
    /** @param list<string> $path ao menos um segmento */
    public function __construct(
        public array $path,
        public int $line,
        public int $column,
    ) {
    }

    public function toArray(): array
    {
        return [
            'type' => 'FieldReference',
            'path' => $this->path,
            'line' => $this->line,
            'column' => $this->column,
        ];
    }
}
