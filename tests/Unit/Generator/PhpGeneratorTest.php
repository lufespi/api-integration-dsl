<?php

declare(strict_types=1);

namespace Tests\Unit\Generator;

use App\Ast\Api;
use App\Ast\DataType;
use App\Ast\Endpoint;
use App\Ast\EndpointRef;
use App\Ast\Expression\BinaryOperator as Op;
use App\Ast\Expression\FieldReference;
use App\Ast\Expression\FunctionCall;
use App\Ast\FieldMapping;
use App\Ast\Header;
use App\Ast\HttpMethod;
use App\Ast\Integration;
use App\Ast\Program;
use App\Exceptions\CompilerException;
use App\Exceptions\SyntaxException;
use App\Generator\GenerationException;
use App\Generator\PhpGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Unit\Generator\Support\Ast;
use Tests\Unit\Generator\Support\GeneratedScript;

/**
 * Estrutura do código gerado e verificações do PhpGenerator, com ASTs montadas à mão.
 *
 * @author lufespi
 * @since 2026-11-26
 */
final class PhpGeneratorTest extends TestCase
{
    private function generate(Program $program): string
    {
        return (new PhpGenerator())->generate($program);
    }

    private static function assertValidPhp(string $code): void
    {
        $lint = (new GeneratedScript($code))->lint();
        self::assertSame(0, $lint['exitCode'], $lint['stdout'] . $lint['stderr']);
    }

    /**
     * Programa do exemplo 1: GET -> POST, sem
     * cabeçalhos, alias, condição nem mapeamento.
     */
    private static function example1(): Program
    {
        return Ast::program(
            [
                Ast::api('Users', 'https://users.example.com/api', [Ast::endpoint('listUsers', HttpMethod::Get, '/users')]),
                Ast::api('Crm', 'https://crm.example.com/v1', [Ast::endpoint('createContact', HttpMethod::Post, '/contacts')]),
            ],
            [Ast::integration('syncUsers', 'Users.listUsers', 'Crm.createContact')],
        );
    }

    /**
     * Uma integração GET -> POST gera PHP 8.3 válido, autônomo, com
     * strict_types, as URLs completas e o método do destino.
     */
    public function testGeneratesValidStandalonePhpForGetToPostIntegration(): void
    {
        $code = $this->generate(self::example1());

        self::assertValidPhp($code);
        self::assertStringStartsWith("<?php\n\ndeclare(strict_types=1);\n", $code);
        self::assertStringContainsString("sourceUrl: 'https://users.example.com/api/users',", $code);
        self::assertStringContainsString("targetMethod: 'POST',", $code);
        self::assertStringContainsString("targetUrl: 'https://crm.example.com/v1/contacts',", $code);
        self::assertStringContainsString("condition: null,\n        mapping: null,", $code);
        self::assertStringContainsString("if (!defined('DSL_NO_AUTORUN')) {\n    exit(dsl_main([\n        dsl_integration_1(...),\n    ]));\n}", $code);

        // Autônomo: não depende do compilador, do Composer nem de autoload.
        foreach (['App\\Ast', 'App\\Generator\\Runtime', 'vendor/autoload', 'require', 'include'] as $dependency) {
            self::assertStringNotContainsString($dependency, $code);
        }
    }

    /**
     * APIs e endpoints são resolvidos pelo nome, independentemente da ordem
     * das declarações; barra final do endereço-base é normalizada.
     */
    public function testResolvesApisAndEndpointsByName(): void
    {
        $program = Ast::program(
            [
                Ast::api('B', 'https://b.example.com/', [
                    Ast::endpoint('first', HttpMethod::Post, '/one'),
                    Ast::endpoint('second', HttpMethod::Put, '/two'),
                ]),
                Ast::api('A', 'https://a.example.com/base', [
                    Ast::endpoint('x', HttpMethod::Get, '/x'),
                    Ast::endpoint('y', HttpMethod::Get, '/y?active=1'),
                ]),
            ],
            [Ast::integration('i', 'A.y', 'B.second')],
        );

        $code = $this->generate($program);

        self::assertValidPhp($code);
        self::assertStringContainsString("sourceUrl: 'https://a.example.com/base/y?active=1',", $code);
        self::assertStringContainsString("sourceHeaders: dsl_api_headers_2(...),", $code);
        self::assertStringContainsString("targetMethod: 'PUT',", $code);
        self::assertStringContainsString("targetUrl: 'https://b.example.com/two',", $code);
        self::assertStringContainsString("targetHeaders: dsl_api_headers_1(...),", $code);
    }

    /**
     * Várias integrações: runtime incluído uma única vez, uma função de
     * cabeçalhos por API (mesmo usada duas vezes), funções numeradas e
     * execução de todas, na ordem do programa.
     */
    public function testMultipleIntegrationsShareRuntimeAndHeaderFunctions(): void
    {
        $program = Ast::program(
            [
                Ast::api('Crm', 'https://crm.example.com', [Ast::endpoint('list', HttpMethod::Get, '/c')], [
                    Ast::header('Authorization', Ast::bin(Op::Add, Ast::str('Bearer '), Ast::env('CRM_TOKEN'))),
                ]),
                Ast::api('Mail', 'https://mail.example.com', [Ast::endpoint('put', HttpMethod::Put, '/s')]),
                Ast::api('Sales', 'https://sales.example.com', [Ast::endpoint('post', HttpMethod::Post, '/l')]),
                Ast::api('Unused', 'https://unused.example.com', [Ast::endpoint('x', HttpMethod::Get, '/x')]),
            ],
            [
                Ast::integration('first', 'Crm.list', 'Mail.put', 'c', Ast::field('c.optIn')),
                Ast::integration('second', 'Crm.list', 'Sales.post', 'c', null, [Ast::map('n', DataType::String, Ast::field('c.name'))]),
            ],
        );

        $code = $this->generate($program);

        self::assertValidPhp($code);
        self::assertSame(1, substr_count($code, 'function dsl_http_request('));
        self::assertSame(1, substr_count($code, 'final class DslRuntimeException'));
        self::assertSame(1, substr_count($code, 'function dsl_api_headers_1('));
        self::assertSame(2, substr_count($code, 'sourceHeaders: dsl_api_headers_1(...)'));
        self::assertStringNotContainsString('function dsl_api_headers_4(', $code, 'API não usada não gera cabeçalhos.');
        self::assertStringContainsString('function dsl_condition_1(stdClass $record): bool', $code);
        self::assertStringNotContainsString('function dsl_condition_2(', $code);
        self::assertStringNotContainsString('function dsl_map_1(', $code);
        self::assertStringContainsString('function dsl_map_2(stdClass $record): stdClass', $code);
        self::assertStringContainsString("        dsl_integration_1(...),\n        dsl_integration_2(...),\n", $code);
        self::assertLessThan(strpos($code, 'function dsl_integration_2('), strpos($code, 'function dsl_integration_1('));
    }

    /** A mesma AST sempre produz exatamente o mesmo código. */
    public function testOutputIsDeterministic(): void
    {
        $program = Ast::simpleProgram(alias: 'r', condition: Ast::field('r.ok'), mappings: [
            Ast::map('a', DataType::Int, Ast::field('r.a')),
        ]);

        self::assertSame($this->generate($program), (new PhpGenerator())->generate($program));
    }

    /**
     * O código gerado não usa eval, comandos de sistema nem desativa a
     * verificação TLS do cURL.
     */
    public function testGeneratedCodeHasNoDangerousConstructs(): void
    {
        $code = $this->generate(Ast::simpleProgram(
            alias: 'r',
            condition: Ast::field('r.ok'),
            mappings: [Ast::map('a', DataType::String, Ast::field('r.a'))],
            targetHeaders: [Ast::header('X-Key', Ast::env('KEY'))],
        ));

        self::assertDoesNotMatchRegularExpression(
            '/(?<![A-Za-z0-9_])(eval|exec|shell_exec|system|passthru|proc_open|popen|pcntl_exec|unserialize|create_function|assert)\s*\(/i',
            $code,
        );
        foreach (['`', 'SSL_VERIFY', 'include', 'require', '$$'] as $construct) {
            self::assertStringNotContainsString($construct, $code);
        }
        self::assertStringContainsString('CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS', $code);
        self::assertStringContainsString('CURLOPT_FOLLOWLOCATION => false', $code);
    }

    /**
     * Nomes e textos hostis vindos da AST (possíveis em ASTs montadas à
     * mão) não quebram o PHP gerado nem viram código: aparecem como dados.
     */
    public function testHostileNamesAndTextsAreEscaped(): void
    {
        $hostile = "?> <?php system('id'); /* */ // \n \$x";
        $headerValue = "?> <?php system('id'); /* */ // \$x"; // cabeçalhos não aceitam quebra de linha
        $program = Ast::program(
            [
                Ast::api('Src' . $hostile, 'https://src.example.com', [Ast::endpoint('l', HttpMethod::Get, '/l')], [
                    Ast::header('X-Text', Ast::str($headerValue)),
                ]),
                Ast::api('Dst', 'https://dst.example.com', [Ast::endpoint('c', HttpMethod::Post, '/c')]),
            ],
            [Ast::integration('sync' . $hostile, 'Src' . $hostile . '.l', 'Dst.c', 'r', Ast::bin(Op::Equal, Ast::field('r.t'), Ast::str($hostile)), [
                Ast::map($hostile, DataType::String, Ast::str($hostile)),
            ])],
        );

        $script = new GeneratedScript($this->generate($program));
        self::assertSame(0, $script->lint()['exitCode']);

        $result = $script->call(sprintf(
            'return [dsl_api_headers_1(), dsl_condition_1((object) ["t" => %1$s]), (array) dsl_map_1(new stdClass())];',
            var_export($hostile, true),
        ));
        self::assertSame([[['X-Text', $headerValue]], true, [$hostile => $hostile]], $result['value'] ?? $result);
    }

    /**
     * GenerationException é um erro do compilador (RuntimeException), mas
     * distinto de SyntaxException.
     */
    public function testGenerationExceptionHierarchy(): void
    {
        $exception = new GenerationException('motivo', 3, 4);

        self::assertInstanceOf(CompilerException::class, $exception);
        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertNotInstanceOf(SyntaxException::class, $exception);
        self::assertSame('Erro de geração na linha 3, coluna 4: motivo', $exception->getMessage());
        self::assertSame('Erro de geração: motivo', (new GenerationException('motivo'))->getMessage());
    }

    /**
     * Problemas que impediriam uma geração segura são GenerationException
     * com mensagem específica e a posição do nó envolvido.
     */
    #[DataProvider('invalidPrograms')]
    public function testInvalidProgramIsRejected(Program $program, string $message, int $line, int $column): void
    {
        try {
            $this->generate($program);
            self::fail('Era esperada GenerationException.');
        } catch (GenerationException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
            self::assertSame([$line, $column], [$exception->getSourceLine(), $exception->getSourceColumn()]);
        }
    }

    /** @return iterable<string, array{Program, string, int, int}> */
    public static function invalidPrograms(): iterable
    {
        $src = new Api('Src', 'https://src.example.com', [], [new Endpoint('list', HttpMethod::Get, '/items', 2, 5)], 1, 1);
        $dst = new Api('Dst', 'https://dst.example.com', [], [new Endpoint('create', HttpMethod::Post, '/records', 6, 5)], 5, 1);
        $integration = static fn (string $source, string $target, ?string $alias = null, $condition = null, array $mappings = []): Integration => new Integration(
            'sync',
            new EndpointRef(...[...explode('.', $source), 11, 10]),
            $alias,
            new EndpointRef(...[...explode('.', $target), 12, 8]),
            $condition,
            $mappings,
            10,
            1,
        );

        yield 'API de origem inexistente' => [
            new Program([$src, $dst], [$integration('Nope.list', 'Dst.create')]),
            "a integração 'sync' usa a API 'Nope' como origem, mas ela não foi declarada", 11, 10,
        ];
        yield 'API de destino inexistente' => [
            new Program([$src, $dst], [$integration('Src.list', 'Nope.create')]),
            "usa a API 'Nope' como destino", 12, 8,
        ];
        yield 'endpoint de origem inexistente' => [
            new Program([$src, $dst], [$integration('Src.nope', 'Dst.create')]),
            "a API 'Src' não possui o endpoint 'nope' (usado como origem da integração 'sync')", 11, 10,
        ];
        yield 'endpoint de destino inexistente' => [
            new Program([$src, $dst], [$integration('Src.list', 'Dst.list')]),
            "a API 'Dst' não possui o endpoint 'list'", 12, 8,
        ];
        yield 'API duplicada' => [
            new Program([$src, $dst, new Api('Src', 'https://x.example.com', [], [new Endpoint('a', HttpMethod::Get, '/a', 21, 5)], 20, 1)], []),
            "API 'Src' declarada mais de uma vez (primeira declaração na linha 1)", 20, 1,
        ];
        yield 'endpoint duplicado' => [
            new Program([new Api('Src', 'https://src.example.com', [], [
                new Endpoint('list', HttpMethod::Get, '/a', 2, 5),
                new Endpoint('list', HttpMethod::Get, '/b', 3, 5),
            ], 1, 1)], []),
            "endpoint 'list' declarado mais de uma vez na API 'Src'", 3, 5,
        ];
        yield 'integração duplicada' => [
            new Program([$src, $dst], [$integration('Src.list', 'Dst.create'), $integration('Src.list', 'Dst.create')]),
            "integração 'sync' declarada mais de uma vez", 10, 1,
        ];
        yield 'origem com POST' => [
            new Program([$src, $dst], [$integration('Dst.create', 'Dst.create')]),
            "o endpoint 'Dst.create' usa POST, que não é suportado como origem; use GET", 11, 10,
        ];
        yield 'destino com GET' => [
            new Program([$src, $dst], [$integration('Src.list', 'Src.list')]),
            "o endpoint 'Src.list' usa GET, que não é suportado como destino; use POST, PUT, PATCH", 12, 8,
        ];
        yield 'destino com DELETE' => [
            new Program([$src, new Api('Dst', 'https://dst.example.com', [], [new Endpoint('del', HttpMethod::Delete, '/r', 6, 5)], 5, 1)], [$integration('Src.list', 'Dst.del')]),
            "usa DELETE, que não é suportado como destino", 12, 8,
        ];
        yield 'parâmetro de caminho' => [
            new Program([new Api('Src', 'https://src.example.com', [], [new Endpoint('list', HttpMethod::Get, '/orders/{id}', 2, 5)], 1, 1), $dst], [$integration('Src.list', 'Dst.create')]),
            "o endpoint 'list' usa parâmetros de caminho ('/orders/{id}'), ainda não suportados (pendência P8)", 2, 5,
        ];
        yield 'endereço-base sem http(s)' => [
            new Program([new Api('Src', 'file:///etc', [], [new Endpoint('list', HttpMethod::Get, '/passwd', 2, 5)], 1, 1), $dst], [$integration('Src.list', 'Dst.create')]),
            "o endereço-base da API 'Src' deve ser uma URL http:// ou https://", 1, 1,
        ];
        yield 'caminho com espaço' => [
            new Program([$src, new Api('Dst', 'https://dst.example.com', [], [new Endpoint('create', HttpMethod::Post, '/a b', 6, 5)], 5, 1)], [$integration('Src.list', 'Dst.create')]),
            "o caminho do endpoint 'create' deve começar com '/' e não conter espaços", 6, 5,
        ];
        yield 'função desconhecida em cabeçalho' => [
            new Program([new Api('Src', 'https://src.example.com', [new Header('X', new FunctionCall('secret', [], 3, 20), 3, 5)], $src->endpoints, 1, 1), $dst], []),
            "função desconhecida 'secret'", 3, 20,
        ];
        yield 'nome de cabeçalho inválido' => [
            new Program([new Api('Src', 'https://src.example.com', [new Header("X\r\nY", Ast::str('v'), 3, 5)], $src->endpoints, 1, 1), $dst], []),
            'nome de cabeçalho inválido na API', 3, 5,
        ];
        yield 'campo em cabeçalho' => [
            new Program([new Api('Src', 'https://src.example.com', [new Header('X', new FieldReference(['r', 'id'], 3, 18), 3, 5)], $src->endpoints, 1, 1), $dst], []),
            "a referência de campo 'r.id' não pode ser usada em cabeçalho", 3, 18,
        ];
        yield 'when com campo e sem alias' => [
            new Program([$src, $dst], [$integration('Src.list', 'Dst.create', null, new FieldReference(['pedido', 'pago'], 13, 10))]),
            "a integração 'sync' usa a referência de campo 'pedido.pago', mas não declara alias", 13, 10,
        ];
        yield 'map com alias incompatível' => [
            new Program([$src, $dst], [$integration('Src.list', 'Dst.create', 'contato', null, [
                new FieldMapping('email', DataType::String, new FieldReference(['cliente', 'email'], 14, 25), 14, 9),
            ])]),
            "a referência de campo 'cliente.email' deve começar pelo alias 'contato'", 14, 25,
        ];
        yield 'função desconhecida no map' => [
            new Program([$src, $dst], [$integration('Src.list', 'Dst.create', 'c', null, [
                new FieldMapping('n', DataType::String, new FunctionCall('upper', [Ast::str('a')], 14, 20), 14, 9),
            ])]),
            "função desconhecida 'upper'", 14, 20,
        ];
        yield 'campo repetido no map' => [
            new Program([$src, $dst], [$integration('Src.list', 'Dst.create', 'c', null, [
                new FieldMapping('n', DataType::String, Ast::str('a'), 14, 9),
                new FieldMapping('n', DataType::Int, Ast::int(1), 15, 9),
            ])]),
            "campo 'n' vazio ou repetido no bloco map da integração 'sync'", 15, 9,
        ];
    }

    /**
     * Restrições de método e de caminho valem para os endpoints USADOS: um
     * endpoint DELETE com parâmetro não referenciado (como em exemplo2.dsl)
     * não impede a geração.
     */
    public function testUnusedEndpointWithUnsupportedFeaturesIsAccepted(): void
    {
        $program = Ast::program(
            [
                Ast::api('Loja', 'https://loja.example.com', [
                    Ast::endpoint('listar', HttpMethod::Get, '/orders'),
                    Ast::endpoint('cancelar', HttpMethod::Delete, '/orders/{id}'),
                ]),
                Ast::api('Fin', 'https://fin.example.com', [Ast::endpoint('registrar', HttpMethod::Post, '/r')]),
            ],
            [Ast::integration('i', 'Loja.listar', 'Fin.registrar')],
        );

        self::assertValidPhp($this->generate($program));
    }

    /**
     * Sem referências de campo, "when" e "map" não exigem alias.
     */
    public function testWhenAndMapWithoutFieldReferencesDoNotRequireAlias(): void
    {
        $script = new GeneratedScript($this->generate(Ast::simpleProgram(
            condition: Ast::bool(true),
            mappings: [Ast::map('origem', DataType::String, Ast::str('crm'))],
        )));

        $result = $script->call('return [dsl_condition_1(new stdClass()), dsl_map_1(new stdClass())];');
        self::assertTrue($result['value'][0]);
        self::assertEquals((object) ['origem' => 'crm'], $result['value'][1]);
    }

    /** Destinos PUT e PATCH são aceitos e geram o método correspondente. */
    #[DataProvider('targetMethods')]
    public function testSupportedTargetMethods(HttpMethod $method): void
    {
        $code = $this->generate(Ast::simpleProgram(targetMethod: $method));

        self::assertValidPhp($code);
        self::assertStringContainsString(sprintf("targetMethod: '%s',", $method->value), $code);
    }

    /** @return iterable<string, array{HttpMethod}> */
    public static function targetMethods(): iterable
    {
        yield 'POST' => [HttpMethod::Post];
        yield 'PUT' => [HttpMethod::Put];
        yield 'PATCH' => [HttpMethod::Patch];
    }

    /**
     * Cabeçalhos estáticos e com env() são traduzidos na função da API,
     * com o texto original em comentário.
     */
    public function testHeadersAreTranslatedPerApi(): void
    {
        $code = $this->generate(Ast::simpleProgram(
            sourceHeaders: [
                Ast::header('Accept', Ast::str('application/json')),
                Ast::header('Authorization', Ast::bin(Op::Add, Ast::str('Bearer '), Ast::env('SRC_TOKEN'))),
            ],
            targetHeaders: [Ast::header('X-Api-Key', Ast::env('DST_KEY'))],
        ));

        self::assertStringContainsString(
            "function dsl_api_headers_1(): array\n{\n    return [\n"
            . "        // Accept = \"application/json\"\n        dsl_header('Accept', 'application/json'),\n"
            . "        // Authorization = \"Bearer \" + env(\"SRC_TOKEN\")\n        dsl_header('Authorization', dsl_add('Bearer ', dsl_env('SRC_TOKEN'))),\n"
            . "    ];\n}\n",
            $code,
        );
        self::assertStringContainsString(
            "function dsl_api_headers_2(): array\n{\n    return [\n"
            . "        // X-Api-Key = env(\"DST_KEY\")\n        dsl_header('X-Api-Key', dsl_env('DST_KEY')),\n    ];\n}\n",
            $code,
        );

        $script = new GeneratedScript($code);
        $result = $script->call('return [dsl_api_headers_1(), dsl_api_headers_2()];', ['SRC_TOKEN' => 'abc', 'DST_KEY' => 'xyz']);
        self::assertSame([
            [['Accept', 'application/json'], ['Authorization', 'Bearer abc']],
            [['X-Api-Key', 'xyz']],
        ], $result['value']);
    }

    /**
     * Valores de cabeçalho inválidos em runtime (quebra de linha, tipo
     * incompatível) geram erro, sem expor o valor.
     */
    public function testInvalidHeaderValuesFailAtRuntimeWithoutLeakingThem(): void
    {
        $code = $this->generate(Ast::simpleProgram(sourceHeaders: [
            Ast::header('X-Token', Ast::env('TOKEN')),
            Ast::header('X-Flag', Ast::bool(true)),
        ]));
        $script = new GeneratedScript($code);

        $injection = $script->call('return dsl_api_headers_1();', ['TOKEN' => "segredo\r\nX-Evil: 1"]);
        self::assertSame('cabeçalho "X-Token": o valor contém quebra de linha', $injection['error']);
        self::assertStringNotContainsString('segredo', $injection['error']);

        $wrongType = $script->call('return dsl_api_headers_1();', ['TOKEN' => 'ok']);
        self::assertSame('cabeçalho "X-Flag": valor bool inválido, esperado texto ou número', $wrongType['error']);
    }
    /**
     * Programa com a API de origem informada, a API de destino fixa e uma
     * integração entre elas. A origem é declarada na linha 3, coluna 1, e
     * seu endpoint na linha 4, coluna 5 (para conferir a posição dos erros).
     */
    private static function programWithSource(string $baseUrl, string $path = '/items'): Program
    {
        return new Program(
            [
                new Api('Src', $baseUrl, [], [new Endpoint('list', HttpMethod::Get, $path, 4, 5)], 3, 1),
                Ast::api('Dst', 'https://dst.example.com', [Ast::endpoint('create', HttpMethod::Post, '/records')]),
            ],
            [Ast::integration('sync', 'Src.list', 'Dst.create')],
        );
    }

    /**
     * Endereços-base e caminhos válidos compõem a URL esperada
     * (caminho do endereço-base preservado, query fixa no endpoint mantida,
     * localhost, IPv4, IPv6 e portas explícitas aceitos).
     */
    #[DataProvider('validUrls')]
    public function testValidBaseUrlAndPathComposeTheExpectedUrl(string $baseUrl, string $path, string $expected): void
    {
        $code = $this->generate(self::programWithSource($baseUrl, $path));

        self::assertValidPhp($code);
        self::assertStringContainsString('sourceUrl: ' . var_export($expected, true) . ',', $code);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function validUrls(): iterable
    {
        yield 'host simples' => ['https://api.example.com', '/users', 'https://api.example.com/users'];
        yield 'caminho no endereço-base' => ['https://api.example.com/v1', '/users', 'https://api.example.com/v1/users'];
        yield 'barra final no endereço-base' => ['https://api.example.com/v1/', '/users', 'https://api.example.com/v1/users'];
        yield 'query fixa no caminho' => ['https://api.example.com', '/users?active=1&sort=name', 'https://api.example.com/users?active=1&sort=name'];
        yield 'localhost com porta' => ['http://localhost:8080', '/x', 'http://localhost:8080/x'];
        yield 'IPv4 com porta' => ['http://127.0.0.1:9000/base', '/x', 'http://127.0.0.1:9000/base/x'];
        yield 'IPv6 com porta' => ['http://[::1]:8080/api', '/x', 'http://[::1]:8080/api/x'];
        yield 'IPv6 sem porta' => ['https://[2001:db8::1]', '/x', 'https://[2001:db8::1]/x'];
        yield 'subdomínios e hífen' => ['https://sub-domain.api.example.co.uk', '/x', 'https://sub-domain.api.example.co.uk/x'];
        yield 'esquema em maiúsculas' => ['HTTPS://Api.Example.com', '/x', 'HTTPS://Api.Example.com/x'];
        yield 'porta máxima' => ['http://api.example.com:65535', '/x', 'http://api.example.com:65535/x'];
    }

    /**
     * Endereços-base que produziriam URLs incorretas ou inseguras
     * são rejeitados na posição da API.
     */
    #[DataProvider('invalidBaseUrls')]
    public function testInvalidBaseUrlIsRejected(string $baseUrl, string $reason): void
    {
        try {
            $this->generate(self::programWithSource($baseUrl));
            self::fail('Era esperada GenerationException para ' . $baseUrl);
        } catch (GenerationException $exception) {
            self::assertSame(
                "Erro de geração na linha 3, coluna 1: o endereço-base da API 'Src' deve ser uma URL http:// ou https:// válida: " . $reason,
                $exception->getMessage(),
            );
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidBaseUrls(): iterable
    {
        $query = "não pode conter query string ('?') nem fragmento ('#'); coloque a query no caminho do endpoint";
        $absolute = 'esperada uma URL absoluta, como https://api.exemplo.com/v1';

        yield 'query string (caso do code review)' => ['https://api.example.com?x=1', $query];
        yield 'query string vazia' => ['https://api.example.com/v1?', $query];
        yield 'fragmento' => ['https://api.example.com/v1#parte', $query];
        yield 'usuário e senha' => ['https://user:pass@api.example.com', 'credenciais embutidas na URL não são permitidas; use um header com env()'];
        yield 'token como usuário' => ['https://token@api.example.com', 'credenciais embutidas na URL não são permitidas; use um header com env()'];
        yield 'ftp' => ['ftp://api.example.com', "protocolo 'ftp' não suportado"];
        yield 'file' => ['file:///etc', $absolute];
        yield 'sem esquema' => ['api.example.com/v1', $absolute];
        yield 'relativa ao protocolo' => ['//api.example.com', $absolute];
        yield 'sem host' => ['https://', $absolute];
        yield 'barra única' => ['https:/api.example.com', $absolute];
        yield 'porta zero' => ['https://api.example.com:0', 'porta fora do intervalo 1-65535'];
        yield 'porta acima de 65535' => ['https://api.example.com:70000', $absolute];
        yield 'porta vazia' => ['https://api.example.com:', 'host ou porta malformados'];
        yield 'porta não numérica' => ['https://api.example.com:abc', $absolute];
        yield 'host com sublinhado' => ['https://bad_host.example.com', "host 'bad_host.example.com' inválido"];
        yield 'IPv4 inválido' => ['http://999.1.1.1', "host '999.1.1.1' inválido"];
        yield 'IPv6 inválido' => ['http://[::g]', "host '[::g]' inválido"];
        yield 'espaço' => ['https://api .example.com', 'não pode conter espaços nem caracteres de controle'];
        yield 'tab' => ["https://api.example.com\t", 'não pode conter espaços nem caracteres de controle'];
        yield 'quebra de linha' => ["https://api.example.com\n/v1", 'não pode conter espaços nem caracteres de controle'];
    }

    /**
     * Caminhos com fragmento, espaços, caracteres de controle ou sem
     * "/" inicial são rejeitados na posição do endpoint.
     */
    #[DataProvider('invalidPaths')]
    public function testInvalidEndpointPathIsRejected(string $path): void
    {
        try {
            $this->generate(self::programWithSource('https://api.example.com', $path));
            self::fail('Era esperada GenerationException para ' . $path);
        } catch (GenerationException $exception) {
            self::assertSame(
                "Erro de geração na linha 4, coluna 5: o caminho do endpoint 'list' deve começar com '/' "
                . "e não conter espaços, caracteres de controle nem fragmento ('#')",
                $exception->getMessage(),
            );
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPaths(): iterable
    {
        yield 'fragmento' => ['/users#topo'];
        yield 'fragmento após query' => ['/users?a=1#x'];
        yield 'espaço' => ['/a b'];
        yield 'controle' => ["/a\x01b"];
        yield 'sem barra inicial' => ['users'];
    }

    /**
     * Parâmetros dinâmicos continuam rejeitados no endpoint usado,
     * também quando aparecem na query string.
     */
    public function testDynamicPathParametersAreStillRejected(): void
    {
        $this->expectException(GenerationException::class);
        $this->expectExceptionMessage("o endpoint 'list' usa parâmetros de caminho ('/users?id={id}'), ainda não suportados (pendência P8)");

        $this->generate(self::programWithSource('https://api.example.com', '/users?id={id}'));
    }
}
