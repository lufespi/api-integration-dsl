<?php

declare(strict_types=1);

namespace App\Ast;

/**
 * Nó da Árvore Sintática Abstrata (AST).
 *
 * A AST é o contrato entre o Parser (que a constrói) e o Generator (que a
 * consome). Os nós são objetos imutáveis; toArray() fornece a representação
 * em arrays associativos descrita em docs/ast-contract.md, útil para testes,
 * depuração e para consumidores que prefiram arrays.
 */
interface Node
{
    /**
     * Representação do nó (e de seus filhos) em arrays associativos, sempre
     * com a chave "type" contendo o nome do nó (exceto EndpointRef, que é um
     * par api + endpoint).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
