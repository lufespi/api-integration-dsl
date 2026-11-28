<?php

declare(strict_types=1);

namespace App\Ast\Expression;

final readonly class Literal implements Expression
{
    public function __construct(
        public LiteralType $literalType,
        public string|int|float|bool|null $value,
        public int $line,
        public int $column,
    ) {
    }

    public function toArray(): array
    {
        return [
            'type' => 'Literal',
            'literalType' => $this->literalType->value,
            'value' => $this->value,
            'line' => $this->line,
            'column' => $this->column,
        ];
    }
}
