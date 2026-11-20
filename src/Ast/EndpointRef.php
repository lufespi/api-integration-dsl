<?php

declare(strict_types=1);

namespace App\Ast;

/**
 * Referência a um endpoint no formato Api.endpoint, usada nas cláusulas
 * "from" e "to" de uma integração.
 *
 * Produção correspondente: ref_endpoint (docs/grammar.md).
 *
 * A existência da API e do endpoint referenciados é uma verificação
 * semântica, fora do escopo do Parser (ver docs/ast-contract.md, P2).
 */
final readonly class EndpointRef implements Node
{
    /**
     * @param string $api      Identificador da API.
     * @param string $endpoint Identificador do endpoint dentro da API.
     * @param int    $line     Linha do identificador da API.
     * @param int    $column   Coluna do identificador da API.
     */
    public function __construct(
        public string $api,
        public string $endpoint,
        public int $line,
        public int $column,
    ) {
    }

    /**
     * Formato do contrato da AST: apenas o par api + endpoint. A posição fica
     * disponível nas propriedades $line e $column do objeto.
     *
     * @return array{api: string, endpoint: string}
     */
    public function toArray(): array
    {
        return ['api' => $this->api, 'endpoint' => $this->endpoint];
    }
}
