<?php

declare(strict_types=1);

namespace Tests\Unit\Lexer;

use App\Lexer\Token;
use App\Lexer\TokenType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Testes do contrato de tokens compartilhado entre Lexer e Parser
 * (src/Lexer/Token.php e src/Lexer/TokenType.php). Não testam o Lexer, que
 * é outro componente.
 */
final class TokenContractTest extends TestCase
{
    /**
     * Verifica que a tabela de palavras reservadas contém exatamente as 22
     * palavras da gramática, diferencia maiúsculas de minúsculas e não inclui
     * categorias como IDENTIFIER e EOF.
     */
    public function testReservedWordsMatchTheGrammar(): void
    {
        $words = TokenType::reservedWords();

        self::assertSame(
            [
                'api', 'base', 'header', 'endpoint', 'integration', 'from', 'as', 'to', 'when', 'map',
                'GET', 'POST', 'PUT', 'PATCH', 'DELETE',
                'string', 'int', 'float', 'bool',
                'true', 'false', 'null',
            ],
            array_keys($words),
        );
        self::assertSame(TokenType::Api, $words['api']);
        self::assertArrayNotHasKey('Api', $words);
        self::assertArrayNotHasKey('get', $words);
        self::assertArrayNotHasKey('IDENTIFIER', $words);
        self::assertArrayNotHasKey('EOF', $words);
    }

    /**
     * Verifica a descrição de cada tipo de token concreto usada nas mensagens
     * de erro sintático.
     */
    #[DataProvider('tokenDescriptions')]
    public function testTokenDescriptionForErrorMessages(Token $token, string $expected): void
    {
        self::assertSame($expected, $token->describe());
    }

    /**
     * Fornece um token de cada categoria e sua descrição.
     *
     * @return iterable<string, array{Token, string}>
     */
    public static function tokenDescriptions(): iterable
    {
        yield 'palavra reservada' => [new Token(TokenType::When, 'when', 1, 1), "palavra reservada 'when'"];
        yield 'método HTTP' => [new Token(TokenType::Get, 'GET', 1, 1), "palavra reservada 'GET'"];
        yield 'identificador' => [new Token(TokenType::Identifier, 'user', 1, 1), "identificador 'user'"];
        yield 'texto' => [new Token(TokenType::StringLiteral, '"/x"', 1, 1, '/x'), 'texto entre aspas "/x"'];
        yield 'inteiro' => [new Token(TokenType::IntLiteral, '42', 1, 1, 42), "número inteiro '42'"];
        yield 'decimal' => [new Token(TokenType::FloatLiteral, '1.5', 1, 1, 1.5), "número decimal '1.5'"];
        yield 'operador' => [new Token(TokenType::AndAnd, '&&', 1, 1), "'&&'"];
        yield 'delimitador' => [new Token(TokenType::Semicolon, ';', 1, 1), "';'"];
        yield 'fim do arquivo' => [new Token(TokenType::Eof, '', 1, 1), 'fim do arquivo'];
    }
}
