<?php

declare(strict_types=1);

namespace App\Generator;

use App\Exceptions\CompilerException;

/**
 * Erro detectado pelo Gerador ao traduzir a AST para PHP.
 *
 * Cobre as verificações mínimas necessárias a uma geração segura (referências
 * a APIs/endpoints inexistentes ou ambíguas, funções desconhecidas, operações
 * não suportadas, referências de campo incompatíveis com o alias). Não é um
 * erro sintático: a AST recebida já é sintaticamente válida.
 *
 * Hierarquia (docs/architecture.md, seção 4):
 *   CompilerException
 *   ├── LexicalException
 *   ├── SyntaxException
 *   ├── SemanticException
 *   └── GenerationException
 */
final class GenerationException extends CompilerException
{
    /**
     * @param string   $reason Descrição do problema, em português.
     * @param int|null $line   Linha do nó da AST que causou o erro, se conhecida.
     * @param int|null $column Coluna do nó da AST que causou o erro, se conhecida.
     */
    public function __construct(string $reason, ?int $line = null, ?int $column = null)
    {
        $message = $line === null
            ? 'Erro de geração: ' . $reason
            : sprintf('Erro de geração na linha %d, coluna %d: %s', $line, $column ?? 0, $reason);

        parent::__construct($message, $line, $column);
    }
}
