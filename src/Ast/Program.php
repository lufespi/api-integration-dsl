<?php

declare(strict_types=1);

namespace App\Ast;

/**
 * Raiz da AST, com as declarações na ordem do fonte (produção programa).
 *
 * @author paulacsilva
 * @since 2026-11-20
 */
final readonly class Program implements Node
{
    /**
     * @param list<Api>         $apis
     * @param list<Integration> $integrations
     */
    public function __construct(
        public array $apis,
        public array $integrations,
    ) {
    }

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
