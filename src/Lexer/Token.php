<?php

declare(strict_types=1);

namespace App\Lexer;

/**
 * Token produzido pelo Analisador Léxico e consumido pelo Analisador
 * Sintático.
 *
 * Contrato (ver docs/tokens.md e docs/grammar.md):
 *  - $lexeme é o texto exato reconhecido no fonte (com aspas, no caso de
 *    literais de texto);
 *  - $value é o valor semântico, obrigatório para literais:
 *      StringLiteral => string já sem aspas e com sequências de escape resolvidas;
 *      IntLiteral    => int;
 *      FloatLiteral  => float;
 *    Para os demais tipos, $value é null;
 *  - $line e $column indicam a posição inicial do token (a partir de 1).
 */
final readonly class Token
{
    /**
     * @param TokenType             $type   Categoria do token (terminal da gramática).
     * @param string                $lexeme Texto exato reconhecido no fonte.
     * @param int                   $line   Linha inicial (a partir de 1).
     * @param int                   $column Coluna inicial (a partir de 1).
     * @param string|int|float|null $value  Valor semântico dos literais.
     */
    public function __construct(
        public TokenType $type,
        public string $lexeme,
        public int $line,
        public int $column,
        public string|int|float|null $value = null,
    ) {
    }

    /**
     * Descrição do token concreto para mensagens de erro, incluindo o lexema
     * quando ele ajuda a localizar o problema (ex.: "identificador 'usuarios'").
     *
     * @return string
     */
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
