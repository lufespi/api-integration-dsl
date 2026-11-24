<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Lançada pelo Lexer quando o texto-fonte contém algo que não forma um token
 * da linguagem (docs/tokens.md, seção 5).
 *
 * Formato da mensagem: "Erro léxico na linha 3, coluna 5: caractere inválido '@'"
 */
final class LexicalException extends CompilerException
{
    /**
     * @param string $reason Descrição do problema, sem a posição.
     * @param int    $line   Linha do erro no fonte (a partir de 1).
     * @param int    $column Coluna do erro no fonte (a partir de 1).
     */
    public function __construct(string $reason, int $line, int $column)
    {
        parent::__construct(
            sprintf('Erro léxico na linha %d, coluna %d: %s', $line, $column, $reason),
            $line,
            $column,
        );
    }
}
