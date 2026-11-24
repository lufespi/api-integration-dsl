<?php

declare(strict_types=1);

namespace Tests\Unit\Parser;

use App\Ast\DataType;
use App\Ast\Expression\BinaryExpression;
use App\Ast\Expression\Expression;
use App\Ast\Expression\FieldReference;
use App\Ast\Expression\FunctionCall;
use App\Ast\Expression\Literal;
use App\Ast\Expression\LiteralType;
use App\Ast\HttpMethod;
use App\Ast\Program;
use App\Lexer\Lexer;
use App\Parser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExpressionPrinter;

/**
 * Testes do Analisador Sintático com programas válidos: estrutura da AST,
 * cláusulas opcionais, tipos de dados, literais, precedência e
 * associatividade dos operadores.
 *
 * Os tokens são produzidos pelo Lexer do compilador (App\Lexer\Lexer).
 */
final class ParserTest extends TestCase
{
    /**
     * Analisa um programa escrito na DSL.
     *
     * @param string $source Programa na DSL.
     *
     * @return Program
     */
    private function parse(string $source): Program
    {
        return (new Parser((new Lexer($source))->tokenize()))->parse();
    }

    /**
     * Analisa uma expressão isolada, colocando-a na cláusula "when" de uma
     * integração mínima.
     *
     * @param string $expression Expressão na DSL.
     *
     * @return Expression
     */
    private function parseExpression(string $expression): Expression
    {
        $program = $this->parse("integration t { from A.b; to C.d; when {$expression}; }");
        $condition = $program->integrations[0]->condition;
        self::assertNotNull($condition);

        return $condition;
    }

    /**
     * Verifica que o programa do esboço original (docs/grammar.md) gera
     * exatamente a AST documentada em docs/ast-contract.md, incluindo nomes,
     * métodos, caminhos, referências e posições (linha/coluna).
     *
     * @return void
     */
    public function testContractExampleProducesTheDocumentedAst(): void
    {
        $source = <<<'DSL'
            api Users {
                base "https://users.example.com/api";
                endpoint listUsers GET "/users";
            }

            api Crm {
                base "https://crm.example.com/v1";
                endpoint createContact POST "/contacts";
            }

            integration syncUsers {
                from Users.listUsers;
                to Crm.createContact;
            }
            DSL;

        $expected = [
            'type' => 'Program',
            'apis' => [
                [
                    'type' => 'Api',
                    'name' => 'Users',
                    'baseUrl' => 'https://users.example.com/api',
                    'line' => 1,
                    'column' => 1,
                    'headers' => [],
                    'endpoints' => [
                        [
                            'type' => 'Endpoint',
                            'name' => 'listUsers',
                            'method' => 'GET',
                            'path' => '/users',
                            'line' => 3,
                            'column' => 5,
                        ],
                    ],
                ],
                [
                    'type' => 'Api',
                    'name' => 'Crm',
                    'baseUrl' => 'https://crm.example.com/v1',
                    'line' => 6,
                    'column' => 1,
                    'headers' => [],
                    'endpoints' => [
                        [
                            'type' => 'Endpoint',
                            'name' => 'createContact',
                            'method' => 'POST',
                            'path' => '/contacts',
                            'line' => 8,
                            'column' => 5,
                        ],
                    ],
                ],
            ],
            'integrations' => [
                [
                    'type' => 'Integration',
                    'name' => 'syncUsers',
                    'source' => ['api' => 'Users', 'endpoint' => 'listUsers'],
                    'target' => ['api' => 'Crm', 'endpoint' => 'createContact'],
                    'line' => 11,
                    'column' => 1,
                    'alias' => null,
                    'condition' => null,
                    'mappings' => [],
                ],
            ],
        ];

        self::assertSame($expected, $this->parse($source)->toArray());
    }

    /**
     * Verifica que a barra final do endereço-base é removida, como exige o
     * contrato da AST ("baseUrl: string sem barra final").
     *
     * @return void
     */
    public function testTrailingSlashIsRemovedFromBaseUrl(): void
    {
        $program = $this->parse('api A { base "https://a.example.com/api//"; endpoint e GET "/e"; }');

        self::assertSame('https://a.example.com/api', $program->apis[0]->baseUrl);
    }

    /**
     * Verifica que cada método HTTP aceito pela gramática é convertido no
     * caso correspondente de HttpMethod.
     *
     * @param string     $lexeme   Método como escrito no fonte.
     * @param HttpMethod $expected Método esperado na AST.
     *
     * @return void
     */
    #[DataProvider('httpMethods')]
    public function testEveryHttpMethodIsAccepted(string $lexeme, HttpMethod $expected): void
    {
        $program = $this->parse("api A { base \"u\"; endpoint e {$lexeme} \"/e\"; }");

        self::assertSame($expected, $program->apis[0]->endpoints[0]->method);
    }

    /**
     * Fornece os cinco métodos HTTP da gramática (produção metodo_http).
     *
     * @return iterable<string, array{string, HttpMethod}>
     */
    public static function httpMethods(): iterable
    {
        yield 'GET' => ['GET', HttpMethod::Get];
        yield 'POST' => ['POST', HttpMethod::Post];
        yield 'PUT' => ['PUT', HttpMethod::Put];
        yield 'PATCH' => ['PATCH', HttpMethod::Patch];
        yield 'DELETE' => ['DELETE', HttpMethod::Delete];
    }

    /**
     * Verifica que cabeçalhos e endpoints são mantidos na ordem do fonte e
     * que o valor do cabeçalho é uma expressão (concatenação com chamada de
     * função).
     *
     * @return void
     */
    public function testHeadersAndEndpointsKeepSourceOrder(): void
    {
        $program = $this->parse(<<<'DSL'
            api Crm {
                base "https://crm.example.com";
                header "Accept" = "application/json";
                header "Authorization" = "Bearer " + env("CRM_TOKEN");
                endpoint list GET "/contacts";
                endpoint create POST "/contacts";
                endpoint remove DELETE "/contacts/{id}";
            }
            DSL);

        $api = $program->apis[0];

        self::assertSame(['Accept', 'Authorization'], array_map(static fn ($h) => $h->name, $api->headers));
        self::assertSame("'application/json'", ExpressionPrinter::print($api->headers[0]->value));
        self::assertSame("('Bearer ' + env('CRM_TOKEN'))", ExpressionPrinter::print($api->headers[1]->value));
        self::assertSame(['list', 'create', 'remove'], array_map(static fn ($e) => $e->name, $api->endpoints));
        self::assertSame('/contacts/{id}', $api->endpoints[2]->path);
    }

    /**
     * Verifica a integração completa: alias, condição "when" e bloco "map",
     * com tipo de dado e expressão de cada campo.
     *
     * @return void
     */
    public function testIntegrationWithAliasConditionAndMappings(): void
    {
        $program = $this->parse(<<<'DSL'
            integration syncContacts {
                from Crm.listContacts as contato;
                to Mailer.upsert;
                when contato.optIn == true;
                map {
                    email: string = contato.email;
                    age: int = 2026 - contato.birthYear;
                }
            }
            DSL);

        $integration = $program->integrations[0];

        self::assertSame('syncContacts', $integration->name);
        self::assertSame(['Crm', 'listContacts'], [$integration->source->api, $integration->source->endpoint]);
        self::assertSame(['Mailer', 'upsert'], [$integration->target->api, $integration->target->endpoint]);
        self::assertSame('contato', $integration->alias);
        self::assertNotNull($integration->condition);
        self::assertSame('(contato.optIn == true)', ExpressionPrinter::print($integration->condition));

        self::assertCount(2, $integration->mappings);
        self::assertSame('email', $integration->mappings[0]->field);
        self::assertSame(DataType::String, $integration->mappings[0]->dataType);
        self::assertSame('age', $integration->mappings[1]->field);
        self::assertSame(DataType::Int, $integration->mappings[1]->dataType);
        self::assertSame('(2026 - contato.birthYear)', ExpressionPrinter::print($integration->mappings[1]->value));
    }

    /**
     * Verifica que as cláusulas opcionais podem ser omitidas
     * independentemente: só "map", só "when", ou nenhuma delas.
     *
     * @return void
     */
    public function testOptionalClausesAreIndependent(): void
    {
        $onlyMap = $this->parse('integration t { from A.b; to C.d; map { x: bool = true; } }')->integrations[0];
        self::assertNull($onlyMap->condition);
        self::assertCount(1, $onlyMap->mappings);

        $onlyWhen = $this->parse('integration t { from A.b as r; to C.d; when r.ok; }')->integrations[0];
        self::assertNotNull($onlyWhen->condition);
        self::assertSame([], $onlyWhen->mappings);

        $none = $this->parse('integration t { from A.b; to C.d; }')->integrations[0];
        self::assertNull($none->alias);
        self::assertNull($none->condition);
        self::assertSame([], $none->mappings);
    }

    /**
     * Verifica que cada tipo de dado da gramática (produção tipo) é
     * convertido no caso correspondente de DataType.
     *
     * @param string   $lexeme   Tipo como escrito no fonte.
     * @param DataType $expected Tipo esperado na AST.
     *
     * @return void
     */
    #[DataProvider('dataTypes')]
    public function testEveryDataTypeIsAccepted(string $lexeme, DataType $expected): void
    {
        $program = $this->parse("integration t { from A.b; to C.d; map { campo: {$lexeme} = 1; } }");

        self::assertSame($expected, $program->integrations[0]->mappings[0]->dataType);
    }

    /**
     * Fornece os quatro tipos de dados da gramática.
     *
     * @return iterable<string, array{string, DataType}>
     */
    public static function dataTypes(): iterable
    {
        yield 'string' => ['string', DataType::String];
        yield 'int' => ['int', DataType::Int];
        yield 'float' => ['float', DataType::Float];
        yield 'bool' => ['bool', DataType::Bool];
    }

    /**
     * Verifica que cada forma de literal gera um nó Literal com o tipo e o
     * valor PHP corretos.
     *
     * @param string                     $source       Literal como escrito no fonte.
     * @param LiteralType                $expectedType Tipo esperado.
     * @param string|int|float|bool|null $expected     Valor esperado.
     *
     * @return void
     */
    #[DataProvider('literals')]
    public function testLiteralsKeepTypeAndValue(string $source, LiteralType $expectedType, string|int|float|bool|null $expected): void
    {
        $literal = $this->parseExpression($source);

        self::assertInstanceOf(Literal::class, $literal);
        self::assertSame($expectedType, $literal->literalType);
        self::assertSame($expected, $literal->value);
    }

    /**
     * Fornece um exemplo de cada literal (produção literal).
     *
     * @return iterable<string, array{string, LiteralType, string|int|float|bool|null}>
     */
    public static function literals(): iterable
    {
        yield 'texto' => ['"pago"', LiteralType::String, 'pago'];
        yield 'texto vazio' => ['""', LiteralType::String, ''];
        yield 'inteiro' => ['42', LiteralType::Int, 42];
        yield 'decimal' => ['99.5', LiteralType::Float, 99.5];
        yield 'verdadeiro' => ['true', LiteralType::Bool, true];
        yield 'falso' => ['false', LiteralType::Bool, false];
        yield 'nulo' => ['null', LiteralType::Null, null];
    }

    /**
     * Verifica, imprimindo a AST totalmente parentizada, que a árvore
     * respeita a tabela de precedência e associatividade de docs/grammar.md.
     *
     * @param string $source   Expressão na DSL.
     * @param string $expected Mesma expressão com os agrupamentos explícitos.
     *
     * @return void
     */
    #[DataProvider('precedenceCases')]
    public function testOperatorPrecedenceAndAssociativity(string $source, string $expected): void
    {
        self::assertSame($expected, ExpressionPrinter::print($this->parseExpression($source)));
    }

    /**
     * Fornece expressões que exercitam cada nível de precedência.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function precedenceCases(): iterable
    {
        yield '&& antes de ||' => ['a || b && c', '(a || (b && c))'];
        yield '|| associativo à esquerda' => ['a || b || c', '((a || b) || c)'];
        yield '&& associativo à esquerda' => ['a && b && c', '((a && b) && c)'];
        yield 'igualdade antes de &&' => ['a == b && c != d', '((a == b) && (c != d))'];
        yield 'relacional antes de igualdade' => ['a < b == c >= d', '((a < b) == (c >= d))'];
        yield 'aditivo antes de relacional' => ['a + b <= c - d', '((a + b) <= (c - d))'];
        yield 'multiplicativo antes de aditivo' => ['a + b * c', '(a + (b * c))'];
        yield '- associativo à esquerda' => ['a - b - c', '((a - b) - c)'];
        yield '* / % associativos à esquerda' => ['a / b * c % d', '(((a / b) * c) % d)'];
        yield 'parênteses alteram a precedência' => ['(a + b) * c', '((a + b) * c)'];
        yield 'unário antes de binário' => ['!a && b', '((!a) && b)'];
        yield 'unário associativo à direita' => ['!!a', '(!(!a))'];
        yield 'menos unário' => ['-a * b', '((-a) * b)'];
        yield 'menos binário seguido de unário' => ['a - -b', '(a - (-b))'];
        yield 'referência a campo aninhado' => ['pedido.cliente.idade >= 18', '(pedido.cliente.idade >= 18)'];
        yield 'palavras reservadas como campos' => ['r.from != r.to', '(r.from != r.to)'];
        yield 'chamada de função com argumentos' => ['f(a, b + 1) == "x"', "(f(a, (b + 1)) == 'x')"];
        yield 'chamada de função sem argumentos' => ['now()', 'now()'];
        yield 'exemplo do domínio' => [
            'p.status == "paid" && (p.total >= 100.0 || p.vip) && !p.cancelled',
            "(((p.status == 'paid') && ((p.total >= 100.0) || p.vip)) && (!p.cancelled))",
        ];
    }

    /**
     * Verifica os tipos dos nós de expressão e as posições registradas:
     * operações binárias na posição do operador, referências e chamadas na
     * posição do primeiro identificador.
     *
     * @return void
     */
    public function testExpressionNodesRecordSourcePositions(): void
    {
        // Colunas: "x" = 40, ">=" = 44, "len" = 47.
        $source ='integration t { from A.b; to C.d; when x.y >= len(z); }';
        $condition = (new Parser((new Lexer($source))->tokenize()))->parse()->integrations[0]->condition;

        self::assertInstanceOf(BinaryExpression::class, $condition);
        self::assertSame([1, 44], [$condition->line, $condition->column]);
        self::assertInstanceOf(FieldReference::class, $condition->left);
        self::assertSame(['x', 'y'], $condition->left->path);
        self::assertSame([1, 40], [$condition->left->line, $condition->left->column]);
        self::assertInstanceOf(FunctionCall::class, $condition->right);
        self::assertSame([1, 47], [$condition->right->line, $condition->right->column]);
    }

    /**
     * Verifica que APIs e integrações podem ser declaradas em qualquer ordem
     * (decisão G9) e que cada lista preserva a ordem do fonte.
     *
     * @return void
     */
    public function testDeclarationsMayAppearInAnyOrder(): void
    {
        $program = $this->parse(<<<'DSL'
            integration primeira { from A.e; to B.e; }
            api A { base "a"; endpoint e GET "/e"; }
            integration segunda { from B.e; to A.e; }
            api B { base "b"; endpoint e POST "/e"; }
            DSL);

        self::assertSame(['A', 'B'], array_map(static fn ($a) => $a->name, $program->apis));
        self::assertSame(['primeira', 'segunda'], array_map(static fn ($i) => $i->name, $program->integrations));
    }

    /**
     * Verifica que a análise pode ser repetida na mesma instância com o mesmo
     * resultado (o estado interno é reiniciado a cada chamada).
     *
     * @return void
     */
    public function testParseCanBeCalledMoreThanOnce(): void
    {
        $parser = new Parser((new Lexer('integration t { from A.b; to C.d; }'))->tokenize());

        self::assertEquals($parser->parse(), $parser->parse());
    }
}
