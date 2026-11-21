<?php

declare(strict_types=1);

namespace App\Ast;

use App\Ast\Expression\Expression;

/**
 * Fluxo de integração: os dados obtidos no endpoint de origem são enviados ao
 * endpoint de destino, opcionalmente filtrados por uma condição ("when") e
 * transformados por mapeamentos de campos ("map").
 *
 * Produção correspondente: decl_integracao (docs/grammar.md).
 */
final readonly class Integration implements Node
{
    /**
     * @param string             $name      Identificador da integração.
     * @param EndpointRef        $source    Endpoint de origem (cláusula "from").
     * @param string|null        $alias     Nome dado a cada registro da origem ("as"), ou null.
     * @param EndpointRef        $target    Endpoint de destino (cláusula "to").
     * @param Expression|null    $condition Condição de encaminhamento ("when"), ou null.
     * @param list<FieldMapping> $mappings  Mapeamentos de campos ("map"); vazio = enviar o registro inteiro.
     * @param int                $line      Linha da palavra reservada "integration".
     * @param int                $column    Coluna da palavra reservada "integration".
     */
    public function __construct(
        public string $name,
        public EndpointRef $source,
        public ?string $alias,
        public EndpointRef $target,
        public ?Expression $condition,
        public array $mappings,
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
            'type' => 'Integration',
            'name' => $this->name,
            'source' => $this->source->toArray(),
            'target' => $this->target->toArray(),
            'line' => $this->line,
            'column' => $this->column,
            'alias' => $this->alias,
            'condition' => $this->condition?->toArray(),
            'mappings' => array_map(static fn (FieldMapping $mapping): array => $mapping->toArray(), $this->mappings),
        ];
    }
}
