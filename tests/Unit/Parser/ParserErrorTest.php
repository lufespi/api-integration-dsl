<?php

declare(strict_types=1);

namespace Tests\Unit\Parser;

use App\Exceptions\CompilerException;
use App\Exceptions\SyntaxException;
use App\Lexer\Lexer;
use App\Lexer\Token;
use App\Lexer\TokenType;
use App\Parser\Parser;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Testes do Analisador Sintático com programas inválidos: mensagem, linha e
 * coluna de cada erro sintático, recuperação em modo pânico e validação do
 * contrato de tokens recebido do Lexer.
 */
final class ParserErrorTest extends TestCase
{
    /** Prefixo de integração válida até a cláusula "to", usado em vários casos. */
    private const INTEGRATION = 'integration s { from A.b; to C.d; ';

    /**
     * Analisa o programa e devolve a SyntaxException lançada, falhando o
     * teste se a análise terminar sem erro.
     */
    private function syntaxErrorOf(string $source): SyntaxException
    {
        try {
            (new Parser((new Lexer($source))->tokenize()))->parse();
        } catch (SyntaxException $exception) {
            return $exception;
        }

        self::fail('Era esperada uma SyntaxException para o programa: ' . $source);
    }

    /**
     * Verifica a mensagem completa e a posição (linha e coluna) de cada tipo
     * de erro sintático, incluindo os pontos em que o Parser escolhe a
     * mensagem conforme as cláusulas opcionais já vistas.
     */
    #[DataProvider('syntaxErrors')]
    public function testSyntaxErrorMessageAndPosition(string $source, string $expected): void
    {
        $exception = $this->syntaxErrorOf($source);

        self::assertCount(1, $exception->errors());
        self::assertSame($expected, $exception->getMessage());
    }

    /**
     * Fornece programas com um erro sintático e a mensagem esperada.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function syntaxErrors(): iterable
    {
        $i = self::INTEGRATION;

        yield 'programa vazio' => [
            '',
            "Erro sintático na linha 1, coluna 1: esperado declaração 'api' ou 'integration' (o programa está vazio), encontrado: fim do arquivo",
        ];
        yield 'declaração desconhecida no nível superior' => [
            'endpoint x GET "/x";',
            "Erro sintático na linha 1, coluna 1: esperado declaração 'api' ou 'integration', encontrado: palavra reservada 'endpoint'",
        ];
        yield 'api sem nome' => [
            'api { base "u"; endpoint e GET "/e"; }',
            "Erro sintático na linha 1, coluna 5: esperado nome da API (identificador) após 'api', encontrado: '{'",
        ];
        yield 'api sem base' => [
            'api A { endpoint e GET "/e"; }',
            "Erro sintático na linha 1, coluna 9: esperado 'base' como primeira declaração do bloco da API 'A' (endereço-base), encontrado: palavra reservada 'endpoint'",
        ];
        yield 'base sem ponto e vírgula' => [
            'api A { base "u" endpoint e GET "/e"; }',
            "Erro sintático na linha 1, coluna 17: esperado ';' ao final da declaração 'base', encontrado: palavra reservada 'endpoint'",
        ];
        yield 'ponto e vírgula ausente no fim da linha' => [
            "api A {\n    base \"u\"\n    endpoint e GET \"/e\";\n}",
            "Erro sintático na linha 2, coluna 13: esperado ';' ao final da declaração 'base', encontrado: palavra reservada 'endpoint'",
        ];
        yield 'api sem endpoints' => [
            'api A { base "u"; }',
            "Erro sintático na linha 1, coluna 19: esperado 'header' ou 'endpoint' no bloco da API 'A' (toda API deve declarar ao menos um endpoint), encontrado: '}'",
        ];
        yield 'método HTTP em minúsculas' => [
            'api A { base "u"; endpoint e get "/e"; }',
            "Erro sintático na linha 1, coluna 30: esperado método HTTP (GET, POST, PUT, PATCH ou DELETE, em maiúsculas) no endpoint 'e', encontrado: identificador 'get'",
        ];
        yield 'caminho sem barra inicial' => [
            'api A { base "u"; endpoint e GET "e"; }',
            "Erro sintático na linha 1, coluna 34: o caminho do endpoint 'e' deve começar com '/', encontrado: texto entre aspas \"e\"",
        ];
        yield 'bloco da api não fechado' => [
            'api A { base "u"; endpoint e GET "/e";',
            "Erro sintático na linha 1, coluna 39: esperado 'endpoint' ou '}' para fechar o bloco da API 'A', encontrado: fim do arquivo",
        ];
        yield 'cabeçalho sem =' => [
            'api A { base "u"; header "Accept" "json"; endpoint e GET "/e"; }',
            "Erro sintático na linha 1, coluna 35: esperado '=' após o nome do cabeçalho \"Accept\", encontrado: texto entre aspas \"json\"",
        ];
        yield 'integração sem to' => [
            'integration s { from A.b; when x; }',
            "Erro sintático na linha 1, coluna 27: esperado 'to' após a cláusula 'from' da integração 's' (endpoint de destino), encontrado: palavra reservada 'when'",
        ];
        yield 'referência sem ponto' => [
            'integration s { from A b; to C.d; }',
            "Erro sintático na linha 1, coluna 24: esperado '.' entre a API e o endpoint na cláusula 'from' (formato Api.endpoint), encontrado: identificador 'b'",
        ];
        yield 'from sem as nem ponto e vírgula' => [
            'integration s { from A.b x; to C.d; }',
            "Erro sintático na linha 1, coluna 25: esperado 'as' ou ';' ao final da cláusula 'from', encontrado: identificador 'x'",
        ];
        yield 'when sem ponto e vírgula' => [
            $i . 'when x > 1 }',
            "Erro sintático na linha 1, coluna 45: esperado ';' ao final da cláusula 'when', encontrado: '}'",
        ];
        yield 'cláusula desconhecida na integração' => [
            $i . 'foo; }',
            "Erro sintático na linha 1, coluna 35: esperado 'when', 'map' ou '}' para fechar o bloco da integração 's', encontrado: identificador 'foo'",
        ];
        yield 'when depois de map' => [
            $i . 'map { n: int = 1; } when x; }',
            "Erro sintático na linha 1, coluna 55: esperado '}' para fechar o bloco da integração 's', encontrado: palavra reservada 'when'",
        ];
        yield 'bloco map vazio' => [
            $i . 'map { } }',
            "Erro sintático na linha 1, coluna 41: esperado nome de campo no bloco 'map' (o bloco deve conter ao menos um mapeamento), encontrado: '}'",
        ];
        yield 'mapeamento sem tipo' => [
            $i . 'map { n: = 1; } }',
            "Erro sintático na linha 1, coluna 44: esperado tipo de dado (string, int, float ou bool) para o campo 'n', encontrado: '='",
        ];
        yield 'mapeamento com tipo desconhecido' => [
            $i . 'map { n: text = 1; } }',
            "Erro sintático na linha 1, coluna 44: esperado tipo de dado (string, int, float ou bool) para o campo 'n', encontrado: identificador 'text'",
        ];
        yield 'operadores relacionais encadeados' => [
            $i . 'when a < b < c; }',
            "Erro sintático na linha 1, coluna 46: operador '<' inesperado: operadores relacionais não são associativos; use parênteses, por exemplo (a < b) < c",
        ];
        yield 'operadores de igualdade encadeados' => [
            $i . 'when a == b != c; }',
            "Erro sintático na linha 1, coluna 47: operador '!=' inesperado: operadores de igualdade não são associativos; use parênteses, por exemplo (a == b) != c",
        ];
        yield 'parêntese não fechado' => [
            $i . 'when (a + b; }',
            "Erro sintático na linha 1, coluna 46: esperado ')' para fechar o '(' aberto na linha 1, coluna 40, encontrado: ';'",
        ];
        yield 'operando ausente' => [
            $i . 'when a + ; }',
            "Erro sintático na linha 1, coluna 44: esperado expressão (literal, identificador, '(', '!' ou '-'), encontrado: ';'",
        ];
        yield 'argumentos sem vírgula' => [
            $i . 'when f(a b); }',
            "Erro sintático na linha 1, coluna 44: esperado ',' ou ')' na chamada da função 'f', encontrado: identificador 'b'",
        ];
        yield 'referência terminada em ponto' => [
            $i . 'when a.; }',
            "Erro sintático na linha 1, coluna 42: esperado nome de campo após 'a.', encontrado: ';'",
        ];
    }

    /**
     * Verifica a recuperação em modo pânico: um erro em uma declaração não
     * impede a análise das seguintes, e todos os erros são relatados juntos,
     * em ordem, com a mensagem de múltiplos erros.
     */
    public function testPanicModeRecoveryReportsIndependentErrors(): void
    {
        $source = <<<'DSL'
            api A {
                base "u";
                endpoint e get "/e";
            }
            api B {
                base "v";
                endpoint f POST "/f";
            }
            integration s {
                from A.e;
                to B.f
            }
            DSL;

        $exception = $this->syntaxErrorOf($source);

        self::assertSame(
            [[3, 16], [11, 11]],
            array_map(static fn ($error) => [$error->line, $error->column], $exception->errors()),
        );
        self::assertSame(
            "2 erros sintáticos encontrados:\n"
            . "  - Erro sintático na linha 3, coluna 16: esperado método HTTP (GET, POST, PUT, PATCH ou DELETE, em maiúsculas) no endpoint 'e', encontrado: identificador 'get'\n"
            . "  - Erro sintático na linha 11, coluna 11: esperado ';' ao final da cláusula 'to', encontrado: '}'",
            $exception->getMessage(),
        );
    }

    /**
     * Verifica que, quando um bloco não é fechado e a próxima declaração
     * começa, o Parser sincroniza nela em vez de descartá-la, relatando um
     * único erro.
     */
    public function testUnclosedBlockSynchronizesOnNextDeclaration(): void
    {
        $exception = $this->syntaxErrorOf(
            'api A { base "u"; endpoint e GET "/e"; integration s { from A.e; to A.e; }',
        );

        self::assertCount(1, $exception->errors());
        self::assertStringContainsString("encontrado: palavra reservada 'integration'", $exception->getMessage());
    }

    /**
     * "api" e "integration" depois de "." são nomes de campo, não início de
     * declaração: a recuperação não sincroniza neles e não gera erros em cascata.
     */
    #[DataProvider('reservedWordsAsFieldNamesAfterError')]
    public function testRecoveryDoesNotSynchronizeOnReservedWordUsedAsFieldName(string $source): void
    {
        $exception = $this->syntaxErrorOf($source);

        self::assertCount(1, $exception->errors(), $exception->getMessage());
    }

    /** @return iterable<string, array{string}> */
    public static function reservedWordsAsFieldNamesAfterError(): iterable
    {
        yield 'campo api no when' => ["integration s {\n  from A.l as x\n  to A.c;\n  when x.api == 1;\n}"];
        yield 'campo integration no map' => ["integration s {\n  from A.l as x;\n  to A.c\n  map { n: int = x.integration; }\n}"];
    }

    /**
     * Verifica que a SyntaxException é uma CompilerException e expõe a linha
     * e a coluna do primeiro erro no arquivo-fonte.
     */
    public function testExceptionExposesPositionOfFirstError(): void
    {
        $exception = $this->syntaxErrorOf("\n\n  api A { }");

        self::assertInstanceOf(CompilerException::class, $exception);
        self::assertSame(3, $exception->getSourceLine());
        self::assertSame(11, $exception->getSourceColumn());
    }

    /** Verifica que SyntaxException não pode ser criada sem nenhum erro. */
    public function testSyntaxExceptionRequiresAtLeastOneError(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SyntaxException([]);
    }

    /**
     * Sequências de tokens que violam o contrato com o Lexer.
     *
     * @param list<mixed> $tokens
     */
    #[DataProvider('invalidTokenSequences')]
    public function testInvalidTokenSequenceIsRejected(array $tokens): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Parser($tokens);
    }

    /**
     * Fornece sequências de tokens fora do contrato.
     *
     * @return iterable<string, array{list<mixed>}>
     */
    public static function invalidTokenSequences(): iterable
    {
        $eof = new Token(TokenType::Eof, '', 1, 1);
        $api = new Token(TokenType::Api, 'api', 1, 1);

        yield 'lista vazia' => [[]];
        yield 'sem EOF no final' => [[$api]];
        yield 'EOF antes do final' => [[$eof, $api, $eof]];
        yield 'elemento que não é Token' => [['api', $eof]];
    }

    /**
     * Verifica que um literal sem valor semântico (defeito do Lexer, não do
     * programa) gera LogicException, e não um erro sintático.
     */
    public function testLiteralWithoutSemanticValueIsAContractViolation(): void
    {
        $tokens = [
            new Token(TokenType::Api, 'api', 1, 1),
            new Token(TokenType::Identifier, 'A', 1, 5),
            new Token(TokenType::LeftBrace, '{', 1, 7),
            new Token(TokenType::Base, 'base', 1, 9),
            new Token(TokenType::StringLiteral, '"u"', 1, 14),
            new Token(TokenType::Eof, '', 1, 17),
        ];

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('STRING_LITERAL');

        (new Parser($tokens))->parse();
    }
}
