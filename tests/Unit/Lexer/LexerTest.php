<?php

declare(strict_types=1);

namespace Tests\Unit\Lexer;

use App\Exceptions\LexicalException;
use App\Lexer\Lexer;
use App\Lexer\Token;
use App\Lexer\TokenType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Testes do Analisador Léxico: tokens reconhecidos, valores, posições, erros
 * e os programas de examples/.
 */
final class LexerTest extends TestCase
{
    private const EXAMPLES = __DIR__ . '/../../../examples';

    /** Prefixo das linhas de comentário que documentam o erro esperado. */
    private const EXPECTED_ERROR_MARKER = '// Erro esperado: ';

    /** @return list<Token> */
    private function lex(string $source): array
    {
        return (new Lexer($source))->tokenize();
    }

    /**
     * Tipos dos tokens, sem o Eof final.
     *
     * @return list<TokenType>
     */
    private function types(string $source): array
    {
        $types = array_map(static fn (Token $token): TokenType => $token->type, $this->lex($source));
        self::assertSame(TokenType::Eof, array_pop($types));
        self::assertNotContains(TokenType::Eof, $types);

        return $types;
    }

    /**
     * Verifica que um fonte vazio ou só com espaços e comentários gera apenas
     * o token Eof.
     */
    #[DataProvider('blankSources')]
    public function testBlankSourceProducesOnlyEof(string $source): void
    {
        self::assertSame([], $this->types($source));
    }

    /** @return iterable<string, array{string}> */
    public static function blankSources(): iterable
    {
        yield 'vazio' => [''];
        yield 'espaços' => [" \t\r\n\n"];
        yield 'comentários' => ["// um\n# dois\n/* três\nquatro **/"];
    }

    /**
     * Verifica que cada palavra reservada, operador e delimitador do contrato
     * é reconhecido com o tipo certo e sem valor semântico.
     */
    #[DataProvider('fixedTerminals')]
    public function testEveryFixedTerminalIsRecognized(TokenType $type): void
    {
        $tokens = $this->lex($type->value);

        self::assertCount(2, $tokens);
        self::assertSame($type, $tokens[0]->type);
        self::assertSame($type->value, $tokens[0]->lexeme);
        self::assertNull($tokens[0]->value);
    }

    /**
     * Fornece todos os terminais de lexema fixo.
     *
     * @return iterable<string, array{TokenType}>
     */
    public static function fixedTerminals(): iterable
    {
        foreach (TokenType::cases() as $type) {
            if (!$type->isValued() && $type !== TokenType::Eof) {
                yield $type->value => [$type];
            }
        }
    }

    /** Verifica que maiúsculas importam e que só a palavra inteira é reservada. */
    public function testReservedWordsAreExact(): void
    {
        foreach ($this->types('Api API get Get TRUE apis api_ mapX _map IDENTIFIER EOF') as $type) {
            self::assertSame(TokenType::Identifier, $type);
        }
    }

    /** Verifica o valor e o lexema dos números. */
    public function testNumbersCarryTheirValue(): void
    {
        $tokens = $this->lex('18 99.5 0 007 9223372036854775807');

        self::assertSame([18, 99.5, 0, 7, PHP_INT_MAX], array_map(static fn (Token $t) => $t->value, array_slice($tokens, 0, 5)));
        self::assertSame(TokenType::IntLiteral, $tokens[0]->type);
        self::assertSame(TokenType::FloatLiteral, $tokens[1]->type);
        self::assertSame('007', $tokens[3]->lexeme);
    }

    /** Verifica que o sinal é um operador à parte do número. */
    public function testNumbersHaveNoSign(): void
    {
        self::assertSame([TokenType::Minus, TokenType::IntLiteral], $this->types('-5'));
    }

    /** Verifica que o ponto só faz parte do número quando há dígito depois. */
    public function testDotIsDecimalPointOnlyBeforeDigit(): void
    {
        self::assertSame([TokenType::IntLiteral, TokenType::Dot, TokenType::Identifier], $this->types('1.a'));
        self::assertSame([TokenType::Identifier, TokenType::Dot, TokenType::Identifier], $this->types('Crm.listar'));
    }

    /**
     * Verifica que o valor do texto vem sem aspas e com escapes resolvidos, e
     * que o lexema preserva o original.
     */
    public function testTextValueResolvesEscapes(): void
    {
        $source = '"a\"b\\\\c\nd\te"';
        $token = $this->lex($source)[0];

        self::assertSame(TokenType::StringLiteral, $token->type);
        self::assertSame("a\"b\\c\nd\te", $token->value);
        self::assertSame($source, $token->lexeme);
        self::assertSame('', $this->lex('""')[0]->value);
    }

    /** Verifica que comentário dentro de texto é texto. */
    public function testCommentMarkersInsideTextAreKept(): void
    {
        self::assertSame('https://x/#a/*b*/', $this->lex('"https://x/#a/*b*/"')[0]->value);
    }

    /** Verifica que vale o maior casamento, com ou sem espaços. */
    public function testLongestMatchWins(): void
    {
        self::assertSame(
            [TokenType::Identifier, TokenType::GreaterEqual, TokenType::IntLiteral, TokenType::AndAnd, TokenType::Bang, TokenType::Identifier],
            $this->types('a>=1&&!b'),
        );
        self::assertSame([TokenType::EqualEqual, TokenType::Assign], $this->types('==='));
    }

    /** Verifica que a barra sozinha é divisão e que comentários somem. */
    public function testSlashIsNotConfusedWithComments(): void
    {
        self::assertSame(
            [TokenType::Identifier, TokenType::Slash, TokenType::Identifier],
            $this->types("a / b // c\n# d / e\n/* f * / g */"),
        );
    }

    /** Verifica linha e coluna, com CRLF contado como uma só quebra. */
    #[DataProvider('newlines')]
    public function testPositions(string $newline): void
    {
        $tokens = $this->lex('api Crm {' . $newline . '    base "ação"; // ok' . $newline . '}');

        self::assertSame(
            [[1, 1], [1, 5], [1, 9], [2, 5], [2, 10], [2, 16], [3, 1], [3, 2]],
            array_map(static fn (Token $token): array => [$token->line, $token->column], $tokens),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function newlines(): iterable
    {
        yield 'LF' => ["\n"];
        yield 'CRLF' => ["\r\n"];
    }

    /** Verifica que o BOM no início do arquivo é ignorado. */
    public function testBomIsIgnored(): void
    {
        $token = $this->lex("\xEF\xBB\xBFapi")[0];

        self::assertSame([TokenType::Api, 1], [$token->type, $token->column]);
    }

    /** Verifica que a mesma instância pode analisar o fonte mais de uma vez. */
    public function testTokenizeCanBeCalledTwice(): void
    {
        $lexer = new Lexer('api Crm {');

        self::assertEquals($lexer->tokenize(), $lexer->tokenize());
    }

    /** Verifica a mensagem completa e a posição de cada erro léxico. */
    #[DataProvider('lexicalErrors')]
    public function testLexicalErrors(string $source, int $line, int $column, string $reason): void
    {
        try {
            $this->lex($source);
        } catch (LexicalException $exception) {
            self::assertSame(
                sprintf('Erro léxico na linha %d, coluna %d: %s', $line, $column, $reason),
                $exception->getMessage(),
            );
            self::assertSame([$line, $column], [$exception->getSourceLine(), $exception->getSourceColumn()]);

            return;
        }

        self::fail('Era esperada uma LexicalException.');
    }

    /** @return iterable<string, array{string, int, int, string}> */
    public static function lexicalErrors(): iterable
    {
        yield 'caractere inválido' => ["api X\n  @", 2, 3, "caractere inválido '@'"];
        yield 'letra acentuada' => ['ação', 1, 2, "caractere inválido 'ç'"];
        yield 'espaço não separável' => ["a\u{00A0}b", 1, 2, 'caractere inválido U+00A0'];
        yield 'aspas curvas' => ['base “x”', 1, 6, "caractere inválido '“' (textos usam aspas duplas retas)"];
        yield 'aspas simples' => ["base 'x'", 1, 6, "caractere inválido ''' (textos usam aspas duplas retas)"];
        yield '& sozinho' => ['a & b', 1, 3, "caractere inválido '&' (o operador lógico é '&&')"];
        yield '| sozinho' => ['a | b', 1, 3, "caractere inválido '|' (o operador lógico é '||')"];
        yield 'texto até o fim do arquivo' => ['base "abc', 1, 6, 'texto não terminado: faltou fechar as aspas'];
        yield 'texto quebrando linha' => ["base \"abc\ndef\"", 1, 6, 'texto não terminado: faltou fechar as aspas'];
        yield 'texto terminado em barra' => ['"abc\\', 1, 1, 'texto não terminado: faltou fechar as aspas'];
        yield 'aspa final escapada' => ['"abc\"', 1, 1, 'texto não terminado: faltou fechar as aspas'];
        yield 'escape inválido' => ['"a\qb"', 1, 3, 'sequência de escape inválida \'\q\' (use \", \\\\, \n ou \t)'];
        yield 'dois pontos decimais' => ['x = 1.2.3;', 1, 5, "número malformado '1.2.3'"];
        yield 'número colado em letra' => ['12abc', 1, 1, "número malformado '12abc'"];
        yield 'notação científica' => ['1e5', 1, 1, "número malformado '1e5'"];
        yield 'inteiro grande demais' => ['9223372036854775808', 1, 1, "número '9223372036854775808' acima do limite aceito"];
        yield 'comentário de bloco aberto' => ["api\n/* fim *", 2, 1, "comentário de bloco não terminado: faltou fechar com '*/'"];
        yield 'UTF-8 inválido' => ["api\nç \xFF", 2, 3, 'o arquivo não está em UTF-8'];
    }

    /** Verifica que exemplos válidos e com erro sintático passam pelo Lexer. */
    #[DataProvider('lexicallyValidExamples')]
    public function testLexicallyValidExampleIsAccepted(string $path): void
    {
        self::assertNotEmpty($this->types((string) file_get_contents($path)));
    }

    /** @return iterable<string, array{string}> */
    public static function lexicallyValidExamples(): iterable
    {
        yield from self::examplesIn('valid');
        yield from self::examplesIn('invalid/syntactic');
    }

    /**
     * Verifica que cada exemplo com erro léxico é rejeitado com a mensagem
     * documentada no próprio arquivo.
     */
    #[DataProvider('lexicalErrorExamples')]
    public function testLexicalErrorExampleIsRejectedWithDocumentedError(string $path): void
    {
        $source = (string) file_get_contents($path);
        $expected = null;
        foreach (preg_split('/\R/', $source) ?: [] as $line) {
            if (str_starts_with($line, self::EXPECTED_ERROR_MARKER)) {
                $expected = substr($line, strlen(self::EXPECTED_ERROR_MARKER));
            }
        }
        self::assertNotNull($expected, 'O exemplo deve documentar um "// Erro esperado:".');

        $this->expectException(LexicalException::class);
        $this->expectExceptionMessageMatches('/^' . preg_quote($expected, '/') . '$/');
        $this->lex($source);
    }

    /** @return iterable<string, array{string}> */
    public static function lexicalErrorExamples(): iterable
    {
        return self::examplesIn('invalid/lexical');
    }

    /** @return \Generator<string, array{string}> */
    private static function examplesIn(string $directory): \Generator
    {
        foreach (glob(self::EXAMPLES . '/' . $directory . '/*.dsl') ?: [] as $file) {
            yield basename($file) => [$file];
        }
    }
}
