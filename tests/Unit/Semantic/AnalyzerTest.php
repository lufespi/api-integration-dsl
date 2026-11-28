<?php

declare(strict_types=1);

namespace Tests\Unit\Semantic;

use App\Ast\Program;
use App\Exceptions\CompilerException;
use App\Exceptions\SemanticException;
use App\Lexer\Lexer;
use App\Parser\Parser;
use App\Semantic\Analyzer;
use App\Semantic\SemanticError;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Testes do Analisador Semântico: cada regra de docs/semantic-analysis.md tem
 * ao menos um programa válido e um inválido, com mensagem, linha e coluna
 * verificadas.
 *
 * As posições esperadas são calculadas a partir do próprio texto do programa
 * de teste (método positionOf), e não escritas à mão, para que o teste continue
 * correto se o programa de exemplo mudar.
 */
final class AnalyzerTest extends TestCase
{
    /** Duas APIs válidas, usadas como base da maioria dos programas de teste. */
    private const APIS = [
        'api Users {',
        '    base "https://users.example.com";',
        '    endpoint listUsers GET "/users";',
        '    endpoint getUser GET "/users/{id}";',
        '}',
        '',
        'api Crm {',
        '    base "https://crm.example.com";',
        '    header "Authorization" = "Bearer " + env("CRM_TOKEN");',
        '    endpoint createContact POST "/contacts";',
        '}',
        '',
    ];

    // ------------------------------------------------------------------
    // Montagem de programas e de mensagens esperadas
    // ------------------------------------------------------------------

    /**
     * Programa formado pelas APIs-base seguidas das linhas informadas.
     *
     * @param string ...$lines Linhas adicionais (APIs e/ou integrações).
     *
     * @return string
     */
    private static function programWithApis(string ...$lines): string
    {
        return implode("\n", [...self::APIS, ...$lines]) . "\n";
    }

    /**
     * Programa formado somente pelas linhas informadas.
     *
     * @param string ...$lines Linhas do programa.
     *
     * @return string
     */
    private static function programOf(string ...$lines): string
    {
        return implode("\n", $lines) . "\n";
    }

    /**
     * Linha e coluna (a partir de 1) da n-ésima ocorrência de um trecho.
     *
     * @param string $source     Programa.
     * @param string $needle     Trecho procurado.
     * @param int    $occurrence Ocorrência desejada (a partir de 1).
     *
     * @return array{int, int}
     */
    private static function positionOf(string $source, string $needle, int $occurrence = 1): array
    {
        $offset = -1;
        for ($i = 0; $i < $occurrence; $i++) {
            $offset = strpos($source, $needle, $offset + 1);
            self::assertNotFalse(
                $offset,
                sprintf("Trecho '%s' (ocorrência %d) não encontrado no programa de teste.", $needle, $occurrence),
            );
        }

        $before = substr($source, 0, $offset);
        $lastNewline = strrpos($before, "\n");
        $currentLine = $lastNewline === false ? $before : substr($before, $lastNewline + 1);

        return [substr_count($before, "\n") + 1, mb_strlen($currentLine, 'UTF-8') + 1];
    }

    /**
     * Texto "linha L, coluna C" da n-ésima ocorrência de um trecho.
     *
     * @param string $source     Programa.
     * @param string $needle     Trecho procurado.
     * @param int    $occurrence Ocorrência desejada (a partir de 1).
     *
     * @return string
     */
    private static function placeOf(string $source, string $needle, int $occurrence = 1): string
    {
        [$line, $column] = self::positionOf($source, $needle, $occurrence);

        return sprintf('linha %d, coluna %d', $line, $column);
    }

    /**
     * Mensagem completa esperada para um erro que começa na n-ésima
     * ocorrência de um trecho.
     *
     * @param string $source     Programa.
     * @param string $needle     Trecho onde o erro deve ser apontado.
     * @param string $message    Descrição do problema, sem a posição.
     * @param int    $occurrence Ocorrência desejada do trecho (a partir de 1).
     *
     * @return string
     */
    private static function expectedError(string $source, string $needle, string $message, int $occurrence = 1): string
    {
        [$line, $column] = self::positionOf($source, $needle, $occurrence);

        return sprintf('Erro semântico na linha %d, coluna %d: %s', $line, $column, $message);
    }

    // ------------------------------------------------------------------
    // Execução do pipeline Lexer -> Parser -> Analyzer
    // ------------------------------------------------------------------

    /**
     * Executa Lexer, Parser e Analyzer sobre o programa.
     *
     * @param string        $source   Programa na DSL.
     * @param Analyzer|null $analyzer Analyzer a usar (padrão: funções-padrão).
     *
     * @return Program
     */
    private static function runAnalysis(string $source, ?Analyzer $analyzer = null): Program
    {
        $program = (new Parser((new Lexer($source))->tokenize()))->parse();

        return ($analyzer ?? new Analyzer())->analyze($program);
    }

    /**
     * Analisa o programa e devolve a SemanticException lançada, falhando o
     * teste se a análise terminar sem erro.
     *
     * @param string        $source   Programa na DSL.
     * @param Analyzer|null $analyzer Analyzer a usar (padrão: funções-padrão).
     *
     * @return SemanticException
     */
    private static function exceptionOf(string $source, ?Analyzer $analyzer = null): SemanticException
    {
        try {
            self::runAnalysis($source, $analyzer);
        } catch (SemanticException $exception) {
            return $exception;
        }

        self::fail('Era esperada uma SemanticException para o programa: ' . $source);
    }

    /**
     * Mensagens completas de todos os erros semânticos do programa.
     *
     * @param string        $source   Programa na DSL.
     * @param Analyzer|null $analyzer Analyzer a usar (padrão: funções-padrão).
     *
     * @return list<string>
     */
    private static function errorsOf(string $source, ?Analyzer $analyzer = null): array
    {
        return array_map(
            static fn (SemanticError $error): string => $error->format(),
            self::exceptionOf($source, $analyzer)->errors(),
        );
    }

    // ------------------------------------------------------------------
    // Programas válidos
    // ------------------------------------------------------------------

    /**
     * Programa correto passa e o mesmo Program é devolvido (permite encadear).
     *
     * @return void
     */
    public function testValidProgramIsAcceptedAndReturnedUnchanged(): void
    {
        $source = self::programWithApis(
            'integration sync {',
            '    from Users.listUsers as user;',
            '    to Crm.createContact;',
            '    when user.age >= 18 && !user.blocked;',
            '    map {',
            '        name: string = upper(user.name);',
            '        whole: string = user;',
            '        kind: string = user.from;',
            '        origin: string = "users";',
            '        at: string = now();',
            '    }',
            '}',
        );
        $program = (new Parser((new Lexer($source))->tokenize()))->parse();

        self::assertSame($program, (new Analyzer())->analyze($program));
    }

    /**
     * A ordem das declarações não importa: uma integração pode aparecer antes
     * das APIs que referencia.
     *
     * @return void
     */
    public function testDeclarationOrderDoesNotMatter(): void
    {
        $source = self::programOf(
            'integration sync {',
            '    from Users.listUsers;',
            '    to Crm.createContact;',
            '}',
            '',
            ...self::APIS,
        );

        self::assertNotEmpty(self::runAnalysis($source)->integrations);
    }

    /**
     * Nomes iguais em escopos diferentes são permitidos: o mesmo endpoint em
     * APIs distintas e o mesmo cabeçalho em APIs distintas.
     *
     * @return void
     */
    public function testSameNamesInDifferentScopesAreAllowed(): void
    {
        $source = self::programOf(
            'api Shop {',
            '    base "https://shop.example.com";',
            '    header "Accept" = "application/json";',
            '    endpoint list GET "/items";',
            '}',
            '',
            'api Other {',
            '    base "https://other.example.com";',
            '    header "Accept" = "application/json";',
            '    endpoint list GET "/things";',
            '}',
            '',
            'integration sync {',
            '    from Shop.list;',
            '    to Other.list;',
            '}',
        );

        self::assertNotEmpty(self::runAnalysis($source)->apis);
    }

    /**
     * Sem alias, a integração é válida desde que não referencie campos
     * (somente literais, funções e operadores).
     *
     * @return void
     */
    public function testNoAliasIsValidWhenNoFieldIsReferenced(): void
    {
        $source = self::programWithApis(
            'integration sync {',
            '    from Users.listUsers;',
            '    to Crm.createContact;',
            '    when true;',
            '    map {',
            '        origin: string = "users";',
            '        version: int = 1;',
            '    }',
            '}',
        );

        self::assertNotEmpty(self::runAnalysis($source)->integrations);
    }

    // ------------------------------------------------------------------
    // Nomes repetidos
    // ------------------------------------------------------------------

    /**
     * API declarada duas vezes: o erro aponta a segunda declaração e cita a
     * primeira. As referências continuam resolvendo para a primeira.
     *
     * @return void
     */
    public function testDuplicateApiIsReported(): void
    {
        $source = self::programWithApis(
            'api Users {',
            '    base "https://other.example.com";',
            '    endpoint other GET "/other";',
            '}',
            '',
            'integration sync {',
            '    from Users.listUsers;',
            '    to Crm.createContact;',
            '}',
        );

        self::assertSame(
            [self::expectedError(
                $source,
                'api Users',
                sprintf("API 'Users' já declarada na %s", self::placeOf($source, 'api Users', 1)),
                2,
            )],
            self::errorsOf($source),
        );
    }

    /**
     * Endpoint repetido dentro da mesma API.
     *
     * @return void
     */
    public function testDuplicateEndpointInSameApiIsReported(): void
    {
        $source = self::programOf(
            'api Shop {',
            '    base "https://shop.example.com";',
            '    endpoint list GET "/items";',
            '    endpoint list POST "/items";',
            '}',
            '',
            'api Other {',
            '    base "https://other.example.com";',
            '    endpoint list GET "/things";',
            '}',
            '',
            'integration sync {',
            '    from Shop.list;',
            '    to Other.list;',
            '}',
        );

        self::assertSame(
            [self::expectedError(
                $source,
                'endpoint list',
                sprintf(
                    "endpoint 'list' já declarado na API 'Shop' (%s)",
                    self::placeOf($source, 'endpoint list', 1),
                ),
                2,
            )],
            self::errorsOf($source),
        );
    }

    /**
     * Cabeçalho repetido na mesma API, sem diferenciar maiúsculas de minúsculas.
     *
     * @return void
     */
    public function testDuplicateHeaderIsCaseInsensitive(): void
    {
        $source = self::programOf(
            'api Shop {',
            '    base "https://shop.example.com";',
            '    header "Accept" = "application/json";',
            '    header "accept" = "text/plain";',
            '    endpoint list GET "/items";',
            '}',
            '',
            'api Other {',
            '    base "https://other.example.com";',
            '    endpoint list GET "/things";',
            '}',
            '',
            'integration sync {',
            '    from Shop.list;',
            '    to Other.list;',
            '}',
        );

        self::assertSame(
            [self::expectedError(
                $source,
                'header "accept"',
                sprintf(
                    "cabeçalho 'accept' repetido na API 'Shop': já declarado como 'Accept' (%s); "
                    . 'nomes de cabeçalho HTTP não diferenciam maiúsculas de minúsculas',
                    self::placeOf($source, 'header "Accept"'),
                ),
            )],
            self::errorsOf($source),
        );
    }

    /**
     * Integração declarada duas vezes.
     *
     * @return void
     */
    public function testDuplicateIntegrationIsReported(): void
    {
        $source = self::programWithApis(
            'integration sync {',
            '    from Users.listUsers;',
            '    to Crm.createContact;',
            '}',
            '',
            'integration sync {',
            '    from Users.getUser;',
            '    to Crm.createContact;',
            '}',
        );

        self::assertSame(
            [self::expectedError(
                $source,
                'integration sync',
                sprintf("integração 'sync' já declarada na %s", self::placeOf($source, 'integration sync', 1)),
                2,
            )],
            self::errorsOf($source),
        );
    }

    /**
     * Campo repetido no bloco "map".
     *
     * @return void
     */
    public function testDuplicateMapFieldIsReported(): void
    {
        $source = self::programWithApis(
            'integration sync {',
            '    from Users.listUsers as user;',
            '    to Crm.createContact;',
            '    map {',
            '        name: string = user.name;',
            '        age: int = user.age;',
            '        name: string = user.fullName;',
            '    }',
            '}',
        );

        self::assertSame(
            [self::expectedError(
                $source,
                'name:',
                sprintf(
                    "campo 'name' repetido no bloco 'map' da integração 'sync' (primeira ocorrência na %s)",
                    self::placeOf($source, 'name:', 1),
                ),
                2,
            )],
            self::errorsOf($source),
        );
    }

    // ------------------------------------------------------------------
    // Referências from / to
    // ------------------------------------------------------------------

    /**
     * API inexistente em "from" e em "to": os dois erros são relatados e a
     * mensagem lista as APIs declaradas.
     *
     * @return void
     */
    public function testUnknownApiInFromAndToIsReported(): void
    {
        $source = self::programWithApis(
            'integration sync {',
            '    from Missing.list;',
            '    to Absent.create;',
            '}',
        );

        self::assertSame(
            [
                self::expectedError(
                    $source,
                    'Missing',
                    "API 'Missing' não declarada na cláusula 'from' da integração 'sync'; APIs declaradas: Users, Crm",
                ),
                self::expectedError(
                    $source,
                    'Absent',
                    "API 'Absent' não declarada na cláusula 'to' da integração 'sync'; APIs declaradas: Users, Crm",
                ),
            ],
            self::errorsOf($source),
        );
    }

    /**
     * Endpoint que existe em outra API, mas não na citada: a verificação é
     * por API, e a mensagem lista os endpoints daquela API.
     *
     * @return void
     */
    public function testUnknownEndpointIsReportedPerApi(): void
    {
        $source = self::programWithApis(
            'integration sync {',
            '    from Users.createContact;',
            '    to Crm.listUsers;',
            '}',
        );

        self::assertSame(
            [
                self::expectedError(
                    $source,
                    'Users.createContact',
                    "a API 'Users' não declara o endpoint 'createContact' (cláusula 'from' da integração 'sync'); "
                    . "endpoints de 'Users': listUsers, getUser",
                ),
                self::expectedError(
                    $source,
                    'Crm.listUsers',
                    "a API 'Crm' não declara o endpoint 'listUsers' (cláusula 'to' da integração 'sync'); "
                    . "endpoints de 'Crm': createContact",
                ),
            ],
            self::errorsOf($source),
        );
    }

    // ------------------------------------------------------------------
    // Alias e referências a campos
    // ------------------------------------------------------------------

    /**
     * Sem alias, usar campos é erro, mas relatado uma única vez por
     * integração (todas as referências têm a mesma causa), na primeira
     * referência encontrada.
     *
     * @return void
     */
    public function testFieldReferenceWithoutAliasIsReportedOnlyOnce(): void
    {
        $source = self::programWithApis(
            'integration sync {',
            '    from Users.listUsers;',
            '    to Crm.createContact;',
            '    when status == "active" && age > 18;',
            '    map {',
            '        name: string = name;',
            '    }',
            '}',
        );

        self::assertSame(
            [self::expectedError(
                $source,
                'status',
                "a integração 'sync' usa o campo 'status' do registro de origem, mas a cláusula 'from' não "
                . "declara alias; escreva 'from Users.listUsers as nome'",
            )],
            self::errorsOf($source),
        );
    }

    /**
     * Com alias, todo caminho de campo deve começar por ele, em "when" e em
     * "map". O alias sozinho e palavras reservadas como campo são permitidos.
     *
     * @return void
     */
    public function testReferenceMustStartWithAlias(): void
    {
        $source = self::programWithApis(
            'integration sync {',
            '    from Users.listUsers as user;',
            '    to Crm.createContact;',
            '    when order.total > 0 && user.active;',
            '    map {',
            '        name: string = user.name;',
            '        total: float = order.total;',
            '        whole: string = user;',
            '        kind: string = user.from;',
            '    }',
            '}',
        );
        $message = "a referência 'order.total' deve começar pelo alias 'user' da integração 'sync'";

        self::assertSame(
            [
                self::expectedError($source, 'order.total', $message, 1),
                self::expectedError($source, 'order.total', $message, 2),
            ],
            self::errorsOf($source),
        );
    }

    /**
     * Cabeçalhos não têm registro de origem: referenciar campo é erro e a
     * mensagem sugere env().
     *
     * @return void
     */
    public function testHeaderCannotReferenceFields(): void
    {
        $source = self::programWithApis(
            'api Shop {',
            '    base "https://shop.example.com";',
            '    header "X-Token" = token;',
            '    header "X-User" = "id-" + session.userId;',
            '    endpoint list GET "/items";',
            '}',
            '',
            'integration sync {',
            '    from Shop.list;',
            '    to Crm.createContact;',
            '}',
        );

        self::assertSame(
            [
                self::expectedError(
                    $source,
                    'token;',
                    "o cabeçalho 'X-Token' da API 'Shop' referencia 'token', mas cabeçalhos não têm acesso a "
                    . 'registros da origem; para ler um segredo use env("NOME")',
                ),
                self::expectedError(
                    $source,
                    'session.userId',
                    "o cabeçalho 'X-User' da API 'Shop' referencia 'session.userId', mas cabeçalhos não têm acesso a "
                    . 'registros da origem; para ler um segredo use env("NOME")',
                ),
            ],
            self::errorsOf($source),
        );
    }

    // ------------------------------------------------------------------
    // Funções
    // ------------------------------------------------------------------

    /**
     * Função inexistente: a mensagem lista as funções disponíveis, em ordem
     * alfabética.
     *
     * @return void
     */
    public function testUnknownFunctionIsReported(): void
    {
        $source = self::programWithApis(
            'integration sync {',
            '    from Users.listUsers as user;',
            '    to Crm.createContact;',
            '    map {',
            '        name: string = capitalize(user.name);',
            '    }',
            '}',
        );

        self::assertSame(
            [self::expectedError(
                $source,
                'capitalize',
                "função desconhecida 'capitalize'; funções disponíveis: env, lower, now, upper",
            )],
            self::errorsOf($source),
        );
    }

    /**
     * Número de argumentos diferente do esperado (com singular e plural
     * corretos); chamadas corretas não geram erro.
     *
     * @return void
     */
    public function testWrongArityIsReported(): void
    {
        $source = self::programWithApis(
            'integration sync {',
            '    from Users.listUsers as user;',
            '    to Crm.createContact;',
            '    map {',
            '        a: string = env();',
            '        b: string = env("A", "B");',
            '        c: string = now(1);',
            '        d: string = upper(user.name);',
            '        e: string = now();',
            '    }',
            '}',
        );

        self::assertSame(
            [
                self::expectedError($source, 'env()', "a função 'env' espera 1 argumento, mas recebeu 0 argumentos"),
                self::expectedError($source, 'env("A"', "a função 'env' espera 1 argumento, mas recebeu 2 argumentos"),
                self::expectedError($source, 'now(1)', "a função 'now' espera 0 argumentos, mas recebeu 1 argumento"),
            ],
            self::errorsOf($source),
        );
    }

    /**
     * Argumentos de funções e operandos de operadores também são verificados.
     *
     * @return void
     */
    public function testNestedExpressionsAreAnalyzed(): void
    {
        $source = self::programWithApis(
            'integration sync {',
            '    from Users.listUsers as user;',
            '    to Crm.createContact;',
            '    map {',
            '        name: string = upper(person.name);',
            '        flag: bool = !other.flag;',
            '        sum: int = 1 + capitalize(user.age);',
            '    }',
            '}',
        );

        self::assertSame(
            [
                self::expectedError(
                    $source,
                    'person.name',
                    "a referência 'person.name' deve começar pelo alias 'user' da integração 'sync'",
                ),
                self::expectedError(
                    $source,
                    'other.flag',
                    "a referência 'other.flag' deve começar pelo alias 'user' da integração 'sync'",
                ),
                self::expectedError(
                    $source,
                    'capitalize',
                    "função desconhecida 'capitalize'; funções disponíveis: env, lower, now, upper",
                ),
            ],
            self::errorsOf($source),
        );
    }

    /**
     * A tabela de funções é configurável: o Analyzer aceita exatamente as
     * funções informadas (nome => aridade).
     *
     * @return void
     */
    public function testCustomFunctionTable(): void
    {
        $analyzer = new Analyzer(['env' => 1, 'slug' => 1]);

        $accepted = self::programWithApis(
            'integration sync {',
            '    from Users.listUsers as user;',
            '    to Crm.createContact;',
            '    map {',
            '        name: string = slug(user.name);',
            '    }',
            '}',
        );
        self::assertNotEmpty(self::runAnalysis($accepted, $analyzer)->integrations);

        $rejected = self::programWithApis(
            'integration sync {',
            '    from Users.listUsers as user;',
            '    to Crm.createContact;',
            '    map {',
            '        name: string = upper(user.name);',
            '    }',
            '}',
        );
        self::assertSame(
            [self::expectedError($rejected, 'upper', "função desconhecida 'upper'; funções disponíveis: env, slug")],
            self::errorsOf($rejected, $analyzer),
        );
    }

    /**
     * Tabelas de funções malformadas são rejeitadas na construção.
     *
     * @param array<mixed> $functions Tabela inválida.
     *
     * @return void
     */
    #[DataProvider('invalidFunctionTables')]
    public function testInvalidFunctionTableIsRejected(array $functions): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Analyzer($functions);
    }

    /**
     * Fornece tabelas de funções fora do contrato.
     *
     * @return iterable<string, array{array<mixed>}>
     */
    public static function invalidFunctionTables(): iterable
    {
        yield 'lista em vez de nome => aridade' => [['env']];
        yield 'aridade que não é inteiro' => [['env' => '1']];
        yield 'aridade negativa' => [['env' => -1]];
        yield 'nome vazio' => [['' => 1]];
    }

    // ------------------------------------------------------------------
    // Vários erros, exceção e reutilização
    // ------------------------------------------------------------------

    /**
     * Erros de partes diferentes do programa são coletados juntos e
     * ordenados por posição no fonte, não pela ordem de verificação (o
     * Analyzer verifica as APIs antes das integrações).
     *
     * @return void
     */
    public function testErrorsAreCollectedAndSortedByPosition(): void
    {
        // O PHP não aceita argumento posicional depois de "...": o array é montado
        // por inteiro e expandido uma única vez.
        $source = self::programOf(...[
            'integration first {',
            '    from Missing.list;',
            '    to Crm.createContact;',
            '}',
            '',
            ...self::APIS,
            'api Crm {',
            '    base "https://other.example.com";',
            '    endpoint other GET "/other";',
            '}',
        ]);
        $exception = self::exceptionOf($source);

        $first = self::expectedError(
            $source,
            'Missing',
            "API 'Missing' não declarada na cláusula 'from' da integração 'first'; APIs declaradas: Users, Crm",
        );
        $second = self::expectedError(
            $source,
            'api Crm',
            sprintf("API 'Crm' já declarada na %s", self::placeOf($source, 'api Crm', 1)),
            2,
        );

        self::assertCount(2, $exception->errors());
        self::assertSame(
            sprintf("2 erros semânticos encontrados:\n  - %s\n  - %s", $first, $second),
            $exception->getMessage(),
        );
        self::assertInstanceOf(CompilerException::class, $exception);
        self::assertSame(self::positionOf($source, 'Missing')[0], $exception->getSourceLine());
        self::assertSame(self::positionOf($source, 'Missing')[1], $exception->getSourceColumn());
    }

    /**
     * Com um único erro, a mensagem da exceção é só a do erro (sem cabeçalho
     * de contagem), igual ao comportamento da SyntaxException.
     *
     * @return void
     */
    public function testSingleErrorMessageHasNoCountHeader(): void
    {
        $source = self::programWithApis(
            'integration sync {',
            '    from Missing.list;',
            '    to Crm.createContact;',
            '}',
        );

        self::assertSame(
            self::expectedError(
                $source,
                'Missing',
                "API 'Missing' não declarada na cláusula 'from' da integração 'sync'; APIs declaradas: Users, Crm",
            ),
            self::exceptionOf($source)->getMessage(),
        );
    }

    /**
     * O mesmo Analyzer pode ser reutilizado: os erros de uma análise não
     * vazam para a seguinte.
     *
     * @return void
     */
    public function testAnalyzerCanBeReused(): void
    {
        $analyzer = new Analyzer();
        $invalid = self::programWithApis('integration a {', '    from Missing.x;', '    to Crm.createContact;', '}');
        $valid = self::programWithApis('integration a {', '    from Users.listUsers;', '    to Crm.createContact;', '}');

        self::assertCount(1, self::exceptionOf($invalid, $analyzer)->errors());
        self::assertNotEmpty(self::runAnalysis($valid, $analyzer)->integrations);
        self::assertCount(1, self::exceptionOf($invalid, $analyzer)->errors());
    }

    /**
     * SemanticException não pode ser criada sem nenhum erro.
     *
     * @return void
     */
    public function testSemanticExceptionRequiresAtLeastOneError(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SemanticException([]);
    }
}
