<?php

declare(strict_types=1);

namespace App\Ast;

use App\Ast\Expression\Expression;

/**
 * Campo do bloco `map`: `nome: tipo = expressão;` (produção mapeamento).
 *
 * @author paulacsilva
 * @since 2026-11-20
 */
final readonly class FieldMapping implements Node
{
    public function __construct(
        public string $field,
        public DataType $dataType,
        public Expression $value,
        public int $line,
        public int $column,
    ) {
    }

    public function toArray(): array
    {
        return [
            'type' => 'FieldMapping',
            'field' => $this->field,
            'dataType' => $this->dataType->value,
            'value' => $this->value->toArray(),
            'line' => $this->line,
            'column' => $this->column,
        ];
    }
}
