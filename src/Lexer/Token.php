<?php

declare(strict_types=1);

namespace App\Lexer;

/**
 * Token do Lexer. $lexeme é o texto exato do fonte (com aspas nos textos);
 * $value é o valor dos literais (string sem aspas e com escapes resolvidos, int
 * ou float) e null nos demais tokens.
 */
final readonly class Token
{
    public function __construct(
        public TokenType $type,
        public string $lexeme,
        public int $line,
        public int $column,
        public string|int|float|null $value = null,
    ) {
    }

    /** Descrição para mensagens de erro, ex.: "identificador 'usuarios'". */
    public function describe(): string
    {
        return match ($this->type) {
            TokenType::Eof => $this->type->description(),
            TokenType::Identifier,
            TokenType::IntLiteral,
            TokenType::FloatLiteral => sprintf("%s '%s'", $this->type->description(), $this->lexeme),
            TokenType::StringLiteral => sprintf('%s %s', $this->type->description(), $this->lexeme),
            default => $this->type->isReservedWord()
                ? sprintf("palavra reservada '%s'", $this->lexeme)
                : sprintf("'%s'", $this->lexeme),
        };
    }
}
