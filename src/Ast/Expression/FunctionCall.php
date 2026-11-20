<?php

declare(strict_types=1);

namespace App\Ast\Expression;

/**
 * Chamada de função embutida (ex.: env("CRM_TOKEN"), upper(user.name)).
 *
 * A gramática aceita qualquer identificador como nome de função; o conjunto
 * de funções disponíveis e sua aridade são definidos pelo Generator
 * (verificação semântica, docs/ast-contract.md).
 *
 * Produção correspondente: primario → IDENT chamada (docs/grammar.md).
 */
final readonly class FunctionCall implements Expression
{
    /**
     * @param string           $name      Nome da função.
     * @param list<Expression> $arguments Argumentos, na ordem (lista possivelmente vazia).
     * @param int              $line      Linha do nome da função.
     * @param int              $column    Coluna do nome da função.
     */
    public function __construct(
        public string $name,
        public array $arguments,
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
            'type' => 'FunctionCall',
            'name' => $this->name,
            'arguments' => array_map(static fn (Expression $argument): array => $argument->toArray(), $this->arguments),
            'line' => $this->line,
            'column' => $this->column,
        ];
    }
}
