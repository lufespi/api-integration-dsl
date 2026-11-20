<?php

declare(strict_types=1);

namespace App\Ast;

use App\Ast\Expression\Expression;

/**
 * Cabeçalho HTTP enviado em todas as requisições a uma API
 * (ex.: header "Authorization" = "Bearer " + env("CRM_TOKEN");).
 *
 * Produção correspondente: decl_header (docs/grammar.md).
 */
final readonly class Header implements Node
{
    /**
     * @param string     $name   Nome do cabeçalho, como escrito no fonte.
     * @param Expression $value  Expressão que calcula o valor do cabeçalho.
     * @param int        $line   Linha da palavra reservada "header".
     * @param int        $column Coluna da palavra reservada "header".
     */
    public function __construct(
        public string $name,
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
            'type' => 'Header',
            'name' => $this->name,
            'value' => $this->value->toArray(),
            'line' => $this->line,
            'column' => $this->column,
        ];
    }
}
