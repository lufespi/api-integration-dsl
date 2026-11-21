<?php

declare(strict_types=1);

namespace App\Ast\Expression;

/**
 * Valor constante escrito no programa: texto, inteiro, decimal, booleano ou
 * nulo (ex.: "Bearer ", 18, 99.9, true, null).
 *
 * Produção correspondente: literal (docs/grammar.md).
 */
final readonly class Literal implements Expression
{
    /**
     * @param LiteralType                $literalType Tipo do literal.
     * @param string|int|float|bool|null $value       Valor já convertido para o tipo PHP equivalente.
     * @param int                        $line        Linha do literal.
     * @param int                        $column      Coluna do literal.
     */
    public function __construct(
        public LiteralType $literalType,
        public string|int|float|bool|null $value,
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
            'type' => 'Literal',
            'literalType' => $this->literalType->value,
            'value' => $this->value,
            'line' => $this->line,
            'column' => $this->column,
        ];
    }
}
