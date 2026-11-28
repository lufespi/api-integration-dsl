<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Parser\SyntaxError;
use InvalidArgumentException;

/**
 * Reúne todos os erros sintáticos, em ordem de ocorrência; a posição exposta é a do primeiro.
 *
 * @author paulacsilva
 * @since 2026-11-20
 */
final class SyntaxException extends CompilerException
{
    /** @var list<SyntaxError> */
    private readonly array $errors;

    /**
     * @param list<SyntaxError> $errors ao menos um
     *
     * @throws InvalidArgumentException se a lista estiver vazia
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

    public static function at(string $message, int $line, int $column): self
    {
        return new self([new SyntaxError($message, $line, $column)]);
    }

    /** @return list<SyntaxError> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @param list<SyntaxError> $errors */
    private static function buildMessage(array $errors): string
    {
        if (count($errors) === 1) {
            return $errors[0]->format();
        }

        $lines = array_map(static fn (SyntaxError $error): string => '  - ' . $error->format(), $errors);

        return sprintf("%d erros sintáticos encontrados:\n%s", count($errors), implode("\n", $lines));
    }
}
