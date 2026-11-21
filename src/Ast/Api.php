<?php

declare(strict_types=1);

namespace App\Ast;

/**
 * Declaração de uma API HTTP: identificador, endereço-base, cabeçalhos
 * enviados em todas as requisições e endpoints.
 *
 * Produção correspondente: decl_api (docs/grammar.md).
 */
final readonly class Api implements Node
{
    /**
     * @param string         $name      Identificador da API.
     * @param string         $baseUrl   Endereço-base, sem barra final.
     * @param list<Header>   $headers   Cabeçalhos HTTP da API (lista possivelmente vazia).
     * @param list<Endpoint> $endpoints Endpoints da API (ao menos um).
     * @param int            $line      Linha da palavra reservada "api".
     * @param int            $column    Coluna da palavra reservada "api".
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

    /**
     * @return array<string, mixed>
     */
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
