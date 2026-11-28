<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base das exceções do compilador (erros léxicos, sintáticos e semânticos).
 *
 * Carrega, opcionalmente, a posição do erro no arquivo-fonte .dsl. Os nomes
 * getSourceLine()/getSourceColumn() evitam conflito com Exception::getLine(),
 * que é final e indica a linha do código PHP onde a exceção foi lançada.
 *
 * Hierarquia (docs/architecture.md, seção 4):
 *   CompilerException
 *   ├── LexicalException   (a cargo do Analisador Léxico)
 *   ├── SyntaxException
 *   └── SemanticException  (a cargo do Analisador Semântico)
 */
abstract class CompilerException extends RuntimeException
{
    /**
     * @param string         $message      Mensagem completa, já formatada para o usuário.
     * @param int|null       $sourceLine   Linha do erro no fonte (a partir de 1), se conhecida.
     * @param int|null       $sourceColumn Coluna do erro no fonte (a partir de 1), se conhecida.
     * @param Throwable|null $previous     Exceção que originou esta, se houver.
     */
    public function __construct(
        string $message,
        private readonly ?int $sourceLine = null,
        private readonly ?int $sourceColumn = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Linha do erro no arquivo-fonte .dsl, ou null se desconhecida.
     *
     * @return int|null
     */
    public function getSourceLine(): ?int
    {
        return $this->sourceLine;
    }

    /**
     * Coluna do erro no arquivo-fonte .dsl, ou null se desconhecida.
     *
     * @return int|null
     */
    public function getSourceColumn(): ?int
    {
        return $this->sourceColumn;
    }
}
