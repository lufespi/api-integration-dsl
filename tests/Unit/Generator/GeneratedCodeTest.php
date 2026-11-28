<?php

declare(strict_types=1);

namespace Tests\Unit\Generator;

use App\Ast\DataType;
use App\Ast\Expression\BinaryOperator as Op;
use App\Ast\HttpMethod;
use App\Ast\Program;
use App\Generator\PhpGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Generator\Support\Ast;
use Tests\Unit\Generator\Support\GeneratedScript;
use Tests\Unit\Generator\Support\LocalHttpServer;

/**
 * Scripts gerados executados contra um servidor HTTP local (127.0.0.1) no papel das APIs.
 *
 * @author lufespi
 * @since 2026-11-26
 */
final class GeneratedCodeTest extends TestCase
{
    private static LocalHttpServer $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = LocalHttpServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    protected function setUp(): void
    {
        self::$server->reset();
    }

    /**
     * @param array<string,string> $env
     *
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private function execute(Program $program, array $env = []): array
    {
        $script = new GeneratedScript((new PhpGenerator())->generate($program));
        self::assertSame(0, $script->lint()['exitCode']);

        return $script->run($env);
    }

    /** Programa Src.list (GET /src/items) -> Dst.create (POST /dst/records) no servidor local. */
    private function program(mixed ...$options): Program
    {
        return Ast::simpleProgram(self::$server->url() . '/src', self::$server->url() . '/dst', ...$options);
    }

    /** @return list<array{method: string, path: string, headers: array<string,string>, body: string}> */
    private function requestsTo(string $path): array
    {
        return array_values(array_filter(self::$server->requests(), static fn (array $request): bool => $request['path'] === $path));
    }

    /**
     * Resposta em lista: cada objeto é enviado em um POST separado, com o
     * registro original intacto (sem "map"), como JSON.
     */
    public function testListResponseSendsEachRecordUnchangedWhenThereIsNoMap(): void
    {
        $records = '[{"id":1,"nome":"Ana","meta":{},"tags":[],"valor":10.0},{"id":2,"nome":"Bia","url":"https://x.y/z"}]';
        self::$server->respond('GET', '/src/items', 200, $records);

        $result = $this->execute($this->program());

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertSame('', $result['stderr']);
        self::assertStringContainsString('[sync] 2 registro(s) recebido(s): 2 enviado(s), 0 ignorado(s) pela condição, 0 com falha.', $result['stdout']);
        self::assertStringContainsString('Concluído: 1 integração(ões) sem falhas.', $result['stdout']);

        $requests = self::$server->requests();
        self::assertCount(3, $requests);
        self::assertSame(['GET', '/src/items', ''], [$requests[0]['method'], $requests[0]['path'], $requests[0]['body']]);
        self::assertArrayNotHasKey('content-type', $requests[0]['headers'], 'GET não envia body nem Content-Type.');
        self::assertSame('POST', $requests[1]['method']);
        self::assertSame('/dst/records', $requests[1]['path']);
        self::assertSame('application/json', $requests[1]['headers']['content-type']);
        // Objetos/listas vazios e decimais "10.0" preservados; barras não escapadas.
        self::assertSame('{"id":1,"nome":"Ana","meta":{},"tags":[],"valor":10.0}', $requests[1]['body']);
        self::assertSame('{"id":2,"nome":"Bia","url":"https://x.y/z"}', $requests[2]['body']);
    }

    /** Resposta em objeto: tratada como um único registro. */
    public function testObjectResponseIsASingleRecord(): void
    {
        self::$server->respond('GET', '/src/items', 200, '{"id":7}');

        $result = $this->execute($this->program());

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        $posts = $this->requestsTo('/dst/records');
        self::assertCount(1, $posts);
        self::assertSame('{"id":7}', $posts[0]['body']);
    }

    /** Lista vazia: nada a enviar, execução bem-sucedida. */
    public function testEmptyListSendsNothing(): void
    {
        self::$server->respond('GET', '/src/items', 200, '[]');

        $result = $this->execute($this->program());

        self::assertSame(0, $result['exitCode']);
        self::assertStringContainsString('0 registro(s) recebido(s)', $result['stdout']);
        self::assertSame([], $this->requestsTo('/dst/records'));
    }

    /**
     * "when": só os registros que satisfazem a condição são enviados
     * (condição do exemplo 2, avaliada para cada registro).
     */
    public function testWhenConditionFiltersRecords(): void
    {
        self::$server->respond('GET', '/src/items', 200, json_encode([
            ['id' => 1, 'status' => 'paid', 'total' => 150.0, 'priority' => false, 'cancelled' => false],
            ['id' => 2, 'status' => 'paid', 'total' => 50.0, 'priority' => true, 'cancelled' => false],
            ['id' => 3, 'status' => 'paid', 'total' => 50.0, 'priority' => false, 'cancelled' => false],
            ['id' => 4, 'status' => 'open', 'total' => 500.0, 'priority' => true, 'cancelled' => false],
            ['id' => 5, 'status' => 'paid', 'total' => 500.0, 'priority' => true, 'cancelled' => true],
            ['id' => 6, 'status' => 'paid', 'total' => 100, 'priority' => false],
        ], JSON_PRESERVE_ZERO_FRACTION));

        $condition = Ast::bin(
            Op::And,
            Ast::bin(
                Op::And,
                Ast::bin(Op::Equal, Ast::field('pedido.status'), Ast::str('paid')),
                Ast::bin(
                    Op::Or,
                    Ast::bin(Op::GreaterEqual, Ast::field('pedido.total'), Ast::float(100.0)),
                    Ast::bin(Op::Equal, Ast::field('pedido.priority'), Ast::bool(true)),
                ),
            ),
            Ast::not(Ast::field('pedido.cancelled')),
        );

        $result = $this->execute($this->program(alias: 'pedido', condition: $condition));

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertStringContainsString('6 registro(s) recebido(s): 3 enviado(s), 3 ignorado(s) pela condição, 0 com falha.', $result['stdout']);
        $sentIds = array_map(static fn (array $request): int => json_decode($request['body'])->id, $this->requestsTo('/dst/records'));
        self::assertSame([1, 2, 6], $sentIds);
    }

    /**
     * "map" com os quatro tipos: o payload contém apenas os campos mapeados,
     * com os tipos JSON corretos (inclusive float com parte inteira).
     */
    public function testMapBuildsTypedPayload(): void
    {
        self::$server->respond('GET', '/src/items', 200, '[{"email":"ana@x.com","firstName":"Ana","lastName":"Souza","birthYear":1990,"archived":false,"score":"42","extra":"x"}]');

        $mappings = [
            Ast::map('email', DataType::String, Ast::field('contato.email')),
            Ast::map('fullName', DataType::String, Ast::bin(Op::Add, Ast::bin(Op::Add, Ast::field('contato.firstName'), Ast::str(' ')), Ast::field('contato.lastName'))),
            Ast::map('age', DataType::Int, Ast::bin(Op::Subtract, Ast::int(2026), Ast::field('contato.birthYear'))),
            Ast::map('ageAsFloat', DataType::Float, Ast::bin(Op::Subtract, Ast::int(2026), Ast::field('contato.birthYear'))),
            Ast::map('scoreAsInt', DataType::Int, Ast::field('contato.score')),
            Ast::map('active', DataType::Bool, Ast::not(Ast::field('contato.archived'))),
            Ast::map('yearText', DataType::String, Ast::field('contato.birthYear')),
        ];

        $result = $this->execute($this->program(alias: 'contato', mappings: $mappings, targetMethod: HttpMethod::Put));

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        $puts = $this->requestsTo('/dst/records');
        self::assertCount(1, $puts);
        self::assertSame('PUT', $puts[0]['method']);
        self::assertSame(
            '{"email":"ana@x.com","fullName":"Ana Souza","age":36,"ageAsFloat":36.0,"scoreAsInt":42,"active":true,"yearText":"1990"}',
            $puts[0]['body'],
        );
    }

    /**
     * Conversão impossível no "map" faz o registro falhar (reportado), sem
     * impedir o envio dos demais; o script termina com código 1.
     */
    public function testConversionFailureFailsOnlyThatRecord(): void
    {
        self::$server->respond('GET', '/src/items', 200, '[{"n":1.5},{"n":2},{"n":"abc"},{"n":null}]');

        $result = $this->execute($this->program(alias: 'r', mappings: [Ast::map('n', DataType::Int, Ast::field('r.n'))], targetMethod: HttpMethod::Patch));

        self::assertSame(1, $result['exitCode']);
        self::assertStringContainsString('[sync] ERRO: registro 1: campo "n": não é possível converter um valor float para int', $result['stderr']);
        self::assertStringContainsString('[sync] ERRO: registro 3: campo "n": não é possível converter um valor string para int', $result['stderr']);
        self::assertStringContainsString('[sync] ERRO: registro 4: campo "n": não é possível converter um valor null para int', $result['stderr']);
        self::assertStringContainsString('4 registro(s) recebido(s): 1 enviado(s), 0 ignorado(s) pela condição, 3 com falha.', $result['stdout']);
        $patches = $this->requestsTo('/dst/records');
        self::assertCount(1, $patches);
        self::assertSame(['PATCH', '{"n":2}'], [$patches[0]['method'], $patches[0]['body']]);
    }

    /**
     * Cabeçalhos: os da origem vão só para a origem e os do destino só para
     * o destino; env() é lido do ambiente; Content-Type declarado pela API
     * não é duplicado.
     */
    public function testHeadersAreSentOnlyToTheirOwnApi(): void
    {
        self::$server->respond('GET', '/src/items', 200, '[{"id":1}]');

        $program = $this->program(
            sourceHeaders: [
                Ast::header('Accept', Ast::str('application/json')),
                Ast::header('Authorization', Ast::bin(Op::Add, Ast::str('Bearer '), Ast::env('SRC_TOKEN'))),
            ],
            targetHeaders: [
                Ast::header('X-Api-Key', Ast::env('DST_KEY')),
                Ast::header('content-type', Ast::str('application/json; charset=utf-8')),
            ],
        );
        $result = $this->execute($program, ['SRC_TOKEN' => 'token-origem', 'DST_KEY' => 'chave-destino']);

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        [$get, $post] = self::$server->requests();

        self::assertSame('application/json', $get['headers']['accept']);
        self::assertSame('Bearer token-origem', $get['headers']['authorization']);
        self::assertArrayNotHasKey('x-api-key', $get['headers']);

        self::assertSame('chave-destino', $post['headers']['x-api-key']);
        self::assertArrayNotHasKey('authorization', $post['headers'], 'Credencial da origem não é repassada ao destino.');
        self::assertSame('application/json; charset=utf-8', $post['headers']['content-type']);

        self::assertStringNotContainsString('token-origem', $result['stdout'] . $result['stderr']);
        self::assertStringNotContainsString('chave-destino', $result['stdout'] . $result['stderr']);
    }

    /**
     * Variável de ambiente ausente: erro claro antes de qualquer requisição,
     * sem exibir segredos; código de saída 1.
     */
    public function testMissingEnvironmentVariableStopsTheIntegrationBeforeAnyRequest(): void
    {
        $program = $this->program(
            sourceHeaders: [Ast::header('Authorization', Ast::env('SRC_TOKEN'))],
            targetHeaders: [Ast::header('X-Api-Key', Ast::env('DST_KEY'))],
        );

        $result = $this->execute($program, ['SRC_TOKEN' => 'segredo-presente']);

        self::assertSame(1, $result['exitCode']);
        self::assertStringContainsString('[sync] ERRO: variável de ambiente obrigatória "DST_KEY" não definida', $result['stderr']);
        self::assertStringContainsString('Concluído com falhas: 1 de 1 integração(ões).', $result['stderr']);
        self::assertStringNotContainsString('segredo-presente', $result['stdout'] . $result['stderr']);
        self::assertSame([], self::$server->requests());
    }

    /**
     * Falhas na origem (status HTTP, JSON inválido, formato incompatível)
     * interrompem a integração com mensagem clara e código de saída 1.
     */
    #[DataProvider('sourceFailures')]
    public function testSourceFailuresAreReported(int $status, string $body, string $message): void
    {
        self::$server->respond('GET', '/src/items', $status, $body);

        $result = $this->execute($this->program());

        self::assertSame(1, $result['exitCode']);
        self::assertStringContainsString('[sync] ERRO: ' . sprintf($message, self::$server->url()), $result['stderr']);
        self::assertSame([], $this->requestsTo('/dst/records'));
    }

    /** @return iterable<string, array{int, string, string}> */
    public static function sourceFailures(): iterable
    {
        yield 'HTTP 500' => [500, '{"error":"x"}', 'GET %s/src/items respondeu com status HTTP 500'];
        yield 'HTTP 404' => [404, '', 'GET %s/src/items respondeu com status HTTP 404'];
        yield 'HTTP 302 (redirecionamentos não são seguidos)' => [302, '', 'GET %s/src/items respondeu com status HTTP 302'];
        yield 'JSON inválido' => [200, '<html>', 'a resposta de GET %s/src/items não é JSON válido: Syntax error'];
        yield 'body vazio' => [204, '', 'a resposta de GET %s/src/items não é JSON válido'];
        yield 'número' => [200, '42', 'formato de resposta incompatível: esperado objeto ou lista de objetos JSON, recebido int'];
        yield 'lista de textos' => [200, '[{"a":1},"x"]', 'formato de resposta incompatível: o item 2 da lista é string, esperado objeto JSON'];
    }

    /** Status de erro no destino: o registro falha, os demais continuam. */
    public function testTargetHttpErrorFailsTheRecordAndContinues(): void
    {
        self::$server->respond('GET', '/src/items', 200, '[{"id":1},{"id":2}]');
        self::$server->respond('POST', '/dst/records', 422, '{"error":"invalid"}');

        $result = $this->execute($this->program());

        self::assertSame(1, $result['exitCode']);
        self::assertStringContainsString(sprintf('[sync] ERRO: registro 1: POST %s/dst/records respondeu com status HTTP 422', self::$server->url()), $result['stderr']);
        self::assertStringContainsString('[sync] ERRO: registro 2: POST', $result['stderr']);
        self::assertCount(2, $this->requestsTo('/dst/records'));
    }

    /** Falha de conexão (porta sem servidor): erro do cURL reportado. */
    public function testConnectionFailureIsReported(): void
    {
        $closedPort = LocalHttpServer::freePort();
        $program = Ast::simpleProgram('http://127.0.0.1:' . $closedPort . '/src', self::$server->url() . '/dst');

        $result = $this->execute($program);

        self::assertSame(1, $result['exitCode']);
        self::assertStringContainsString(sprintf('[sync] ERRO: falha de conexão em GET http://127.0.0.1:%d/src/items: ', $closedPort), $result['stderr']);
    }

    /**
     * Várias integrações executam em ordem; a falha de uma não impede as
     * seguintes, mas o código de saída final é 1.
     */
    public function testMultipleIntegrationsRunIndependently(): void
    {
        $url = self::$server->url();
        self::$server->respond('GET', '/a/items', 500, '');
        self::$server->respond('GET', '/b/items', 200, '[{"id":1}]');

        $program = Ast::program(
            [
                Ast::api('A', $url . '/a', [Ast::endpoint('list', HttpMethod::Get, '/items')]),
                Ast::api('B', $url . '/b', [Ast::endpoint('list', HttpMethod::Get, '/items')]),
                Ast::api('Dst', $url . '/dst', [Ast::endpoint('create', HttpMethod::Post, '/records')]),
            ],
            [
                Ast::integration('primeira', 'A.list', 'Dst.create'),
                Ast::integration('segunda', 'B.list', 'Dst.create'),
            ],
        );

        $result = $this->execute($program);

        self::assertSame(1, $result['exitCode']);
        self::assertStringContainsString('[primeira] ERRO: GET ' . $url . '/a/items respondeu com status HTTP 500', $result['stderr']);
        self::assertStringContainsString('[segunda] 1 registro(s) recebido(s): 1 enviado(s)', $result['stdout']);
        self::assertStringContainsString('Concluído com falhas: 1 de 2 integração(ões).', $result['stderr']);
        self::assertSame(['/a/items', '/b/items', '/dst/records'], array_column(self::$server->requests(), 'path'));
    }
    /**
     * Programa cuja origem e destino têm caminhos informados (podem conter
     * query string fixa), no servidor local ou na porta indicada.
     */
    private function programWithPaths(string $sourcePath, string $targetPath, ?int $sourcePort = null): Program
    {
        $source = $sourcePort === null ? self::$server->url() : 'http://127.0.0.1:' . $sourcePort;

        return Ast::program(
            [
                Ast::api('Src', $source . '/src', [Ast::endpoint('list', HttpMethod::Get, $sourcePath)]),
                Ast::api('Dst', self::$server->url() . '/dst', [Ast::endpoint('create', HttpMethod::Post, $targetPath)]),
            ],
            [Ast::integration('sync', 'Src.list', 'Dst.create')],
        );
    }

    /**
     * Inteiros dentro do intervalo do PHP continuam números; os
     * maiores que PHP_INT_MAX (em objeto ou lista, positivos ou negativos)
     * preservam todos os dígitos, encaminhados como texto JSON.
     */
    public function testLargeJsonIntegersKeepAllDigits(): void
    {
        self::$server->respond('GET', '/src/items', 200, '[{"id":9223372036854775807,"n":42},'
            . '{"id":9223372036854775808,"pedido":{"cliente":12345678901234567890}},{"id":-12345678901234567890}]');

        $result = $this->execute($this->program());

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertSame([
            '{"id":9223372036854775807,"n":42}',
            '{"id":"9223372036854775808","pedido":{"cliente":"12345678901234567890"}}',
            '{"id":"-12345678901234567890"}',
        ], array_column($this->requestsTo('/dst/records'), 'body'));
    }

    /**
     * Resposta em objeto com identificador grande, e "map": texto
     * preserva os dígitos; int fora do intervalo é rejeitado no registro.
     */
    public function testLargeJsonIntegersInMapping(): void
    {
        self::$server->respond('GET', '/src/items', 200, '{"id":98765432109876543210}');

        $result = $this->execute($this->program(alias: 'r', mappings: [
            Ast::map('idText', DataType::String, Ast::field('r.id')),
        ]));
        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertSame('{"idText":"98765432109876543210"}', $this->requestsTo('/dst/records')[0]['body']);

        self::$server->reset();
        self::$server->respond('GET', '/src/items', 200, '[{"id":9223372036854775807},{"id":9223372036854775808}]');

        $result = $this->execute($this->program(alias: 'r', mappings: [Ast::map('id', DataType::Int, Ast::field('r.id'))]));
        self::assertSame(1, $result['exitCode']);
        self::assertStringContainsString('[sync] ERRO: registro 2: campo "id": não é possível converter um valor string para int', $result['stderr']);
        self::assertSame(['{"id":9223372036854775807}'], array_column($this->requestsTo('/dst/records'), 'body'));
    }

    /**
     * Em sucesso, o parâmetro sensível é enviado à API, mas não
     * aparece em stdout nem stderr; o log continua mostrando método e URL.
     */
    public function testQueryStringSecretsAreSentButNotLogged(): void
    {
        self::$server->respond('GET', '/src/items', 200, '[{"id":1}]');

        $result = $this->execute($this->programWithPaths('/items?api_key=secret123', '/records?token=dst-secret'));

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        $requests = self::$server->requests();
        self::assertSame('api_key=secret123', $requests[0]['query']);
        self::assertSame('token=dst-secret', $requests[1]['query']);

        self::assertStringContainsString(sprintf(
            '[sync] GET %1$s/src/items?[REDACTED] -> POST %1$s/dst/records?[REDACTED]',
            self::$server->url(),
        ), $result['stdout']);
        foreach (['secret123', 'dst-secret', 'api_key', 'token='] as $secret) {
            self::assertStringNotContainsString($secret, $result['stdout'] . $result['stderr']);
        }
    }

    /**
     * Em falhas (status HTTP da origem e do destino, JSON inválido,
     * conexão recusada), a mensagem mantém método, host e caminho, mas oculta
     * a query string.
     */
    #[DataProvider('failuresWithSecrets')]
    public function testQueryStringSecretsAreNotLoggedOnFailures(string $scenario, string $expected): void
    {
        $closedPort = LocalHttpServer::freePort();
        match ($scenario) {
            'source-status' => self::$server->respond('GET', '/src/items', 500, ''),
            'invalid-json' => self::$server->respond('GET', '/src/items', 200, '<html>'),
            'target-status' => [
                self::$server->respond('GET', '/src/items', 200, '[{"id":1}]'),
                self::$server->respond('POST', '/dst/records', 403, '{"error":"forbidden"}'),
            ],
            'connection' => null,
        };

        $program = $this->programWithPaths(
            '/items?api_key=secret123',
            '/records?token=dst-secret',
            $scenario === 'connection' ? $closedPort : null,
        );
        $result = $this->execute($program);

        self::assertSame(1, $result['exitCode']);
        self::assertStringContainsString(
            sprintf($expected, self::$server->url(), 'http://127.0.0.1:' . $closedPort),
            $result['stderr'],
        );
        foreach (['secret123', 'dst-secret', 'api_key', 'token='] as $secret) {
            self::assertStringNotContainsString($secret, $result['stdout'] . $result['stderr']);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function failuresWithSecrets(): iterable
    {
        yield 'status HTTP da origem' => ['source-status', '[sync] ERRO: GET %1$s/src/items?[REDACTED] respondeu com status HTTP 500'];
        yield 'JSON inválido' => ['invalid-json', '[sync] ERRO: a resposta de GET %1$s/src/items?[REDACTED] não é JSON válido'];
        yield 'status HTTP do destino' => ['target-status', '[sync] ERRO: registro 1: POST %1$s/dst/records?[REDACTED] respondeu com status HTTP 403'];
        yield 'conexão recusada' => ['connection', '[sync] ERRO: falha de conexão em GET %2$s/src/items?[REDACTED]: '];
    }

    /**
     * Valores não finitos falham apenas o registro em que aparecem;
     * os demais continuam sendo processados e enviados.
     */
    public function testNonFiniteValuesFailOnlyTheirRecord(): void
    {
        self::$server->respond('GET', '/src/items', 200, '[{"v":"1e400"},{"v":2.5},{"v":1e400},{"v":1e308},{"v":"3"}]');

        $result = $this->execute($this->program(alias: 'r', mappings: [
            Ast::map('v', DataType::Float, Ast::field('r.v')),
            Ast::map('dobro', DataType::Float, Ast::bin(Op::Multiply, Ast::field('r.v'), Ast::int(2))),
        ]));

        self::assertSame(1, $result['exitCode']);
        // Os campos são avaliados na ordem do "map": "v" falha antes de "dobro".
        self::assertStringContainsString('[sync] ERRO: registro 1: campo "v": o valor não é um número finito', $result['stderr']);
        self::assertStringContainsString('[sync] ERRO: registro 3: o campo "v" contém um número fora do intervalo suportado', $result['stderr']);
        self::assertStringContainsString('[sync] ERRO: registro 4: o operador "*" produziu um resultado fora do intervalo numérico suportado', $result['stderr']);
        self::assertStringContainsString('[sync] ERRO: registro 5: o operador "*" exige números, mas recebeu string e int', $result['stderr']);
        self::assertStringContainsString('5 registro(s) recebido(s): 1 enviado(s), 0 ignorado(s) pela condição, 4 com falha.', $result['stdout']);
        self::assertSame(['{"v":2.5,"dobro":5.0}'], array_column($this->requestsTo('/dst/records'), 'body'));
    }

    /**
     * Conversão para float de um texto numérico que vira INF falha
     * o registro, identificando o campo.
     */
    public function testNonFiniteConversionIdentifiesTheField(): void
    {
        self::$server->respond('GET', '/src/items', 200, '[{"v":"1e400"},{"v":"1.25"}]');

        $result = $this->execute($this->program(alias: 'r', mappings: [Ast::map('preco', DataType::Float, Ast::field('r.v'))]));

        self::assertSame(1, $result['exitCode']);
        self::assertStringContainsString('[sync] ERRO: registro 1: campo "preco": o valor não é um número finito', $result['stderr']);
        self::assertSame(['{"preco":1.25}'], array_column($this->requestsTo('/dst/records'), 'body'));
    }
}
