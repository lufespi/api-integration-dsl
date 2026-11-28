<?php

declare(strict_types=1);

namespace App\Ast;

/** Declaração `api` (produção decl_api). O endereço-base não tem barra final. */
final readonly class Api implements Node
{
    /**
     * @param list<Header>   $headers
     * @param list<Endpoint> $endpoints ao menos um
     */
    public function __construct(
        public string $name,
        public string $baseUrl,
        public array $headers,
        public array $endpoints,
        public int $line,
        public int $column,
    ) {
    }

    public function toArray(): array
    {
        return [
            'type' => 'Api',
            'name' => $this->name,
            'baseUrl' => $this->baseUrl,
            'line' => $this->line,
            'column' => $this->column,
            'headers' => array_map(static fn (Header $header): array => $header->toArray(), $this->headers),
            'endpoints' => array_map(static fn (Endpoint $endpoint): array => $endpoint->toArray(), $this->endpoints),
        ];
    }
}
