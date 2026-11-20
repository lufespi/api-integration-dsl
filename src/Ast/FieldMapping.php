<?php

declare(strict_types=1);

namespace App\Ast;

use App\Ast\Expression\Expression;

/**
 * Mapeamento de um campo do registro enviado ao destino
 * (ex.: nome: string = user.firstName + " " + user.lastName;).
 *
 * Produção correspondente: mapeamento (docs/grammar.md).
 */
final readonly class FieldMapping implements Node
{
    /**
     * @param string     $field    Nome do campo no registro de destino.
     * @param DataType   $dataType Tipo para o qual o valor deve ser convertido.
     * @param Expression $value    Expressão que calcula o valor do campo.
     * @param int        $line     Linha do nome do campo.
     * @param int        $column   Coluna do nome do campo.
     */
    public function __construct(
        public string $field,
        public DataType $dataType,
        public Expression $value,
        public int $line,
        public int $column,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
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
