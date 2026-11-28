<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Semantic\SemanticError;
use InvalidArgumentException;

/** Reúne todos os erros semânticos, ordenados por posição; a posição exposta é a do primeiro. */
final class SemanticException extends CompilerException
{
    /** @var list<SemanticError> */
    private readonly array $errors;

    /**
     * @param list<SemanticError> $errors ao menos um
     *
     * @throws InvalidArgumentException se a lista estiver vazia
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

    /** @return list<SemanticError> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @param list<SemanticError> $errors */
    private static function buildMessage(array $errors): string
    {
        if (count($errors) === 1) {
            return $errors[0]->format();
        }

        $lines = array_map(static fn (SemanticError $error): string => '  - ' . $error->format(), $errors);

        return sprintf("%d erros semânticos encontrados:\n%s", count($errors), implode("\n", $lines));
    }
}
