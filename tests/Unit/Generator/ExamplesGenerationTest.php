<?php

declare(strict_types=1);

namespace Tests\Unit\Generator;

use App\Ast\Program;
use App\Generator\PhpGenerator;
use App\Lexer\Lexer;
use App\Parser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Generator\Support\GeneratedScript;

/**
 * Os exemplos de examples/valid/ geram PHP válido. As funções de condição,
 * mapeamento e cabeçalhos são executadas com registros simulados, nunca contra
 * as URLs de exemplo.
 */
final class ExamplesGenerationTest extends TestCase
{
    private const EXAMPLES = __DIR__ . '/../../../examples/valid';

    private static function parse(string $file): Program
    {
        return (new Parser((new Lexer((string) file_get_contents(self::EXAMPLES . '/' . $file)))->tokenize()))->parse();
    }

    private static function script(string $file): GeneratedScript
    {
        return new GeneratedScript((new PhpGenerator())->generate(self::parse($file)));
    }

    /** Todo exemplo válido gera PHP sintaticamente válido. */
    #[DataProvider('validExamples')]
    public function testEveryValidExampleGeneratesValidPhp(string $file): void
    {
        $lint = self::script($file)->lint();

        self::assertSame(0, $lint['exitCode'], $lint['stdout'] . $lint['stderr']);
    }

    /** @return iterable<string, array{string}> */
    public static function validExamples(): iterable
    {
        $files = glob(self::EXAMPLES . '/*.dsl') ?: [];
        sort($files);
        foreach ($files as $file) {
            yield basename($file) => [basename($file)];
        }
    }

    /**
     * Exemplo 1: GET Users.listUsers -> POST Crm.createContact, registro
     * enviado sem alterações.
     */
    public function testExample1(): void
    {
        $code = self::script('exemplo1.dsl')->code;

        self::assertStringContainsString("sourceUrl: 'https://users.example.com/api/users',", $code);
        self::assertStringContainsString("targetMethod: 'POST',", $code);
        self::assertStringContainsString("targetUrl: 'https://crm.example.com/v1/contacts',", $code);
        self::assertStringContainsString("condition: null,\n        mapping: null,", $code);
    }

    /**
     * Exemplo 2: cabeçalhos com env(), condição "when" avaliada sobre
     * pedidos simulados, endpoint DELETE não usado aceito.
     */
    public function testExample2(): void
    {
        $script = self::script('exemplo2.dsl');
        self::assertStringContainsString("sourceUrl: 'https://loja.example.com/api/orders',", $script->code);
        self::assertStringContainsString("targetUrl: 'https://financeiro.example.com/v2/receivables',", $script->code);

        $result = $script->call(<<<'PHP'
            $orders = json_decode('[
                {"status": "paid", "total": 150.0, "priority": false, "cancelled": false},
                {"status": "paid", "total": 20.0,  "priority": true,  "cancelled": false},
                {"status": "paid", "total": 20.0,  "priority": false, "cancelled": false},
                {"status": "paid", "total": 500.0, "priority": true,  "cancelled": true},
                {"status": "open", "total": 500.0, "priority": true,  "cancelled": false}
            ]', false, 512, JSON_THROW_ON_ERROR);

            return [
                array_map(dsl_condition_1(...), $orders),
                dsl_api_headers_1(),
                dsl_api_headers_2(),
            ];
            PHP, ['LOJA_TOKEN' => 'tk-loja', 'FINANCEIRO_KEY' => 'k-fin']);

        self::assertSame([
            [true, true, false, false, false],
            [['Accept', 'application/json'], ['Authorization', 'Bearer tk-loja']],
            [['X-Api-Key', 'k-fin']],
        ], $result['value'] ?? $result);
    }

    /**
     * Exemplo 3: duas integrações com a mesma origem; condições e
     * mapeamentos com os quatro tipos avaliados sobre contatos simulados.
     */
    public function testExample3(): void
    {
        $script = self::script('exemplo3.dsl');
        self::assertStringContainsString("targetMethod: 'PUT',", $script->code);
        self::assertSame(1, substr_count($script->code, 'function dsl_api_headers_1('));

        $result = $script->call(<<<'PHP'
            $contacts = json_decode('[
                {"email": "ana@x.com", "optIn": true, "firstName": "Ana", "lastName": "Souza",
                 "birthYear": 1990, "archived": false, "score": 60, "from": "site"},
                {"email": null, "optIn": true, "score": 40, "from": "partner"},
                {"optIn": false, "firstName": "Bia", "score": 50, "from": "crm"}
            ]', false, 512, JSON_THROW_ON_ERROR);

            return [
                array_map(dsl_condition_1(...), $contacts),
                dsl_map_1($contacts[0]),
                array_map(dsl_condition_2(...), $contacts),
                dsl_map_2($contacts[0]),
                dsl_map_2($contacts[2]),
            ];
            PHP);

        self::assertArrayHasKey('value', $result, $result['error'] ?? '');
        [$condition1, $map1, $condition2, $map2a, $map2c] = $result['value'];
        self::assertSame([true, false, false], $condition1);
        self::assertSame(['email' => 'ana@x.com', 'fullName' => 'Ana Souza', 'age' => 36, 'active' => true], (array) $map1);
        self::assertSame([true, false, true], $condition2); // 60*1.5=90, 40*1.5=60, 50*1.5=75
        self::assertSame(['nome' => 'Ana', 'pontuacao' => 90.0, 'origem' => 'crm'], (array) $map2a);
        self::assertSame(['nome' => 'Bia', 'pontuacao' => 75.0, 'origem' => 'crm'], (array) $map2c);
    }
}
