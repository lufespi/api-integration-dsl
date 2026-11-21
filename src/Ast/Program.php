<?php

declare(strict_types=1);

namespace App\Ast;

/**
 * Raiz da AST: todas as APIs e integrações declaradas no arquivo-fonte, na
 * ordem em que aparecem.
 *
 * Produção correspondente: programa (docs/grammar.md).
 */
final readonly class Program implements Node
{
    /**
     * @param list<Api>         $apis         APIs declaradas.
     * @param list<Integration> $integrations Integrações declaradas.
     */
    public function __construct(
        public array $apis,
        public array $integrations,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => 'Program',
            'apis' => array_map(static fn (Api $api): array => $api->toArray(), $this->apis),
            'integrations' => array_map(
                static fn (Integration $integration): array => $integration->toArray(),
                $this->integrations,
            ),
        ];
    }
}
