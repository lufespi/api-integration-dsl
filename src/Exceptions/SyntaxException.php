<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Parser\SyntaxError;
use InvalidArgumentException;

/**
 * Lançada pelo Parser quando o programa não pertence à linguagem gerada pela
 * GLC (docs/grammar.md).
 *
 * Reúne todos os erros encontrados na análise, em ordem de ocorrência. A
 * posição exposta por getSourceLine()/getSourceColumn() é a do primeiro erro.
 *
 * Formato da mensagem:
 *  - um erro:     "Erro sintático na linha 3, coluna 5: esperado ';' ..."
 *  - vários erros: "2 erros sintáticos encontrados:" seguido de uma linha
 *                  por erro, no formato acima.
 */
final class SyntaxException extends CompilerException
{
    /** @var list<SyntaxError> */
    private readonly array $errors;

    /**
     * @param list<SyntaxError> $errors Erros encontrados (ao menos um).
     *
     * @throws InvalidArgumentException se a lista estiver vazia.
     */
    public function __construct(array $errors)
    {
        if ($errors === []) {
            throw new InvalidArgumentException('SyntaxException exige ao menos um SyntaxError.');
        }

        $this->errors = array_values($errors);
        $first = $this->errors[0];

        parent::__construct(self::buildMessage($this->errors), $first->line, $first->column);
    }

    /**
     * Atalho para criar a exceção com um único erro.
     *
     * @param string $message Descrição do problema, sem a posição.
     * @param int    $line    Linha do erro no fonte.
     * @param int    $column  Coluna do erro no fonte.
     *
     * @return self
     */
    public static function at(string $message, int $line, int $column): self
    {
        return new self([new SyntaxError($message, $line, $column)]);
    }

    /**
     * Todos os erros sintáticos encontrados, em ordem de ocorrência.
     *
     * @return list<SyntaxError>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @param list<SyntaxError> $errors
     *
     * @return string
     */
    private static function buildMessage(array $errors): string
    {
        if (count($errors) === 1) {
            return $errors[0]->format();
        }

        $lines = array_map(static fn (SyntaxError $error): string => '  - ' . $error->format(), $errors);

        return sprintf("%d erros sintáticos encontrados:\n%s", count($errors), implode("\n", $lines));
    }
}
