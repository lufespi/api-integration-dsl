<?php

declare(strict_types=1);

namespace App\Ast;

/**
 * Operação de uma API: identificador, método HTTP e caminho relativo ao
 * endereço-base.
 *
 * Produção correspondente: decl_endpoint (docs/grammar.md).
 */
final readonly class Endpoint implements Node
{
    /**
     * @param string     $name   Identificador do endpoint.
     * @param HttpMethod $method Método HTTP.
     * @param string     $path   Caminho, sempre iniciado por "/".
     * @param int        $line   Linha da palavra reservada "endpoint".
     * @param int        $column Coluna da palavra reservada "endpoint".
     */
    public function __construct(
        public string $name,
        public HttpMethod $method,
        public string $path,
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
            'type' => 'Endpoint',
            'name' => $this->name,
            'method' => $this->method->value,
            'path' => $this->path,
            'line' => $this->line,
            'column' => $this->column,
        ];
    }
}
