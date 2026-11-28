<?php

declare(strict_types=1);

namespace App\Ast;

/** Referência `Api.endpoint` das cláusulas `from` e `to` (produção ref_endpoint). */
final readonly class EndpointRef implements Node
{
    public function __construct(
        public string $api,
        public string $endpoint,
        public int $line,
        public int $column,
    ) {
    }

    /** @return array{api: string, endpoint: string} sem posição, que fica nas propriedades */
    public function toArray(): array
    {
        return ['api' => $this->api, 'endpoint' => $this->endpoint];
    }
}
