<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Semantic\SemanticError;
use InvalidArgumentException;

/**
 * Lançada pelo Analyzer quando o programa é sintaticamente válido, mas viola
 * regras de significado da linguagem (docs/semantic-analysis.md): nomes
 * repetidos, referências a APIs/endpoints inexistentes, campos fora do alias
 * e chamadas de função inválidas.
 *
 * Reúne todos os erros encontrados, ordenados por posição no fonte. A posição
 * exposta por getSourceLine()/getSourceColumn() é a do primeiro erro.
 *
 * Formato da mensagem:
 *  - um erro:      "Erro semântico na linha 3, coluna 5: API 'X' não declarada ..."
 *  - vários erros: "2 erros semânticos encontrados:" seguido de uma linha por
 *                  erro, no formato acima.
 */
final class SemanticException extends CompilerException
{
    /** @var list<SemanticError> */
    private readonly array $errors;

    /**
     * @param list<SemanticError> $errors Erros encontrados (ao menos um).
     *
     * @throws InvalidArgumentException se a lista estiver vazia.
     */
    public function __construct(array $errors)
    {
        if ($errors === []) {
            throw new InvalidArgumentException('SemanticException exige ao menos um SemanticError.');
        }

        $this->errors = array_values($errors);
        $first = $this->errors[0];

        parent::__construct(self::buildMessage($this->errors), $first->line, $first->column);
    }

    /**
     * Todos os erros semânticos encontrados, em ordem de posição no fonte.
     *
     * @return list<SemanticError>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @param list<SemanticError> $errors
     *
     * @return string
     */
    private static function buildMessage(array $errors): string
    {
        if (count($errors) === 1) {
            return $errors[0]->format();
        }

        $lines = array_map(static fn (SemanticError $error): string => '  - ' . $error->format(), $errors);

        return sprintf("%d erros semânticos encontrados:\n%s", count($errors), implode("\n", $lines));
    }
}
