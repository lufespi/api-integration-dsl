<?php

declare(strict_types=1);

namespace Tests\Unit\Cli;

use App\Cli\Application;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApplicationTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures';

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    protected function setUp(): void
    {
        $this->stdout = fopen('php://memory', 'w+');
        $this->stderr = fopen('php://memory', 'w+');
    }

    protected function tearDown(): void
    {
        fclose($this->stdout);
        fclose($this->stderr);
    }

    /** @param list<string> $args */
    private function runCli(array $args): int
    {
        return (new Application($this->stdout, $this->stderr))->run(['main.php', ...$args]);
    }

    /** @param resource $stream */
    private function contents($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    /**
     * Verifica que --help e -h imprimem a ajuda na saída padrão, sem escrever
     * na saída de erro, e terminam com código de sucesso.
     *
     * @param string $flag Opção de ajuda fornecida pelo data provider.
     *
     * @return void
     */
    #[DataProvider('helpFlags')]
    public function testHelpIsPrintedToStdoutWithSuccessExitCode(string $flag): void
    {
        $exitCode = $this->runCli([$flag]);

        self::assertSame(Application::EXIT_SUCCESS, $exitCode);
        self::assertStringContainsString('Uso:', $this->contents($this->stdout));
        self::assertStringContainsString('--help', $this->contents($this->stdout));
        self::assertSame('', $this->contents($this->stderr));
    }

    /**
     * Fornece as formas aceitas de solicitar a ajuda da CLI.
     *
     * @return iterable<string, array{string}>
     */
    public static function helpFlags(): iterable
    {
        yield 'long flag' => ['--help'];
        yield 'short flag' => ['-h'];
    }

    /**
     * Verifica que executar a CLI sem argumentos é erro de uso: mensagem em
     * stderr, nada em stdout e código de saída EXIT_USAGE_ERROR.
     *
     * @return void
     */
    public function testMissingArgumentIsAUsageError(): void
    {
        $exitCode = $this->runCli([]);

        self::assertSame(Application::EXIT_USAGE_ERROR, $exitCode);
        self::assertStringContainsString('nenhum arquivo', $this->contents($this->stderr));
        self::assertSame('', $this->contents($this->stdout));
    }

    /**
     * Verifica que uma opção desconhecida (ex.: --verbose) é rejeitada com
     * EXIT_USAGE_ERROR e que a mensagem cita a opção informada.
     *
     * @return void
     */
    public function testUnknownOptionIsAUsageError(): void
    {
        $exitCode = $this->runCli(['--verbose']);

        self::assertSame(Application::EXIT_USAGE_ERROR, $exitCode);
        self::assertStringContainsString('--verbose', $this->contents($this->stderr));
    }

    /**
     * Verifica que informar mais de um arquivo de entrada é erro de uso, pois
     * a CLI compila apenas um arquivo .dsl por execução.
     *
     * @return void
     */
    public function testMoreThanOneInputFileIsAUsageError(): void
    {
        $file = self::FIXTURES . '/placeholder.dsl';

        $exitCode = $this->runCli([$file, $file]);

        self::assertSame(Application::EXIT_USAGE_ERROR, $exitCode);
        self::assertStringContainsString('apenas um arquivo', $this->contents($this->stderr));
    }

    /**
     * Verifica que um caminho inexistente gera erro "não encontrado" com
     * EXIT_USAGE_ERROR, citando o arquivo na mensagem.
     *
     * @return void
     */
    public function testNonexistentFileIsReported(): void
    {
        $missing = self::FIXTURES . '/does-not-exist.dsl';

        $exitCode = $this->runCli([$missing]);

        self::assertSame(Application::EXIT_USAGE_ERROR, $exitCode);
        self::assertStringContainsString('não encontrado', $this->contents($this->stderr));
        self::assertStringContainsString('does-not-exist.dsl', $this->contents($this->stderr));
    }

    /**
     * Verifica que um diretório passado como entrada é rejeitado, pois o
     * caminho precisa apontar para um arquivo.
     *
     * @return void
     */
    public function testDirectoryIsRejected(): void
    {
        $exitCode = $this->runCli([self::FIXTURES]);

        self::assertSame(Application::EXIT_USAGE_ERROR, $exitCode);
        self::assertStringContainsString('não é um arquivo', $this->contents($this->stderr));
    }

    /**
     * Verifica que um arquivo existente com extensão diferente de .dsl é
     * rejeitado com EXIT_USAGE_ERROR.
     *
     * @return void
     */
    public function testFileWithoutDslExtensionIsRejected(): void
    {
        $exitCode = $this->runCli([self::FIXTURES . '/not-a-dsl.txt']);

        self::assertSame(Application::EXIT_USAGE_ERROR, $exitCode);
        self::assertStringContainsString('.dsl', $this->contents($this->stderr));
    }

    /**
     * Verifica que, com um .dsl válido, a CLI informa que o pipeline ainda não
     * foi integrado (EXIT_NOT_IMPLEMENTED) e não simula sucesso de compilação.
     *
     * @return void
     */
    public function testValidInputReportsThatThePipelineIsNotIntegratedYet(): void
    {
        $exitCode = $this->runCli([self::FIXTURES . '/placeholder.dsl']);

        self::assertSame(Application::EXIT_NOT_IMPLEMENTED, $exitCode);
        self::assertStringContainsString('ainda não foi integrado', $this->contents($this->stderr));
        // Nada pode sugerir sucesso de compilação.
        self::assertSame('', $this->contents($this->stdout));
    }

    /**
     * Verifica que cada constante EXIT_* da CLI possui um valor diferente,
     * para que scripts e testes distingam o tipo de falha.
     *
     * @return void
     */
    public function testExitCodesAreDistinct(): void
    {
        $codes = [
            Application::EXIT_SUCCESS,
            Application::EXIT_COMPILATION_ERROR,
            Application::EXIT_USAGE_ERROR,
            Application::EXIT_NOT_IMPLEMENTED,
        ];

        self::assertSame($codes, array_values(array_unique($codes)));
    }
}
