<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Cli\Application;
use PHPUnit\Framework\TestCase;

/**
 * Executa main.php em um processo PHP separado, como o usuário faria no
 * terminal. Não depende de rede nem de APIs externas.
 */
final class MainScriptTest extends TestCase
{
    private const PROJECT_ROOT = __DIR__ . '/../..';

    /**
     * @param list<string> $args
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private function runMain(array $args): array
    {
        $process = proc_open(
            [PHP_BINARY, 'main.php', ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            self::PROJECT_ROOT,
        );
        self::assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exitCode' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * Verifica que "php main.php --help" imprime a ajuda em stdout e termina
     * com código de sucesso, executando o script em um processo real.
     *
     * @return void
     */
    public function testHelpFlag(): void
    {
        $result = $this->runMain(['--help']);

        self::assertSame(Application::EXIT_SUCCESS, $result['exitCode']);
        self::assertStringContainsString('Uso:', $result['stdout']);
    }

    /**
     * Verifica que "php main.php" sem argumentos termina com EXIT_USAGE_ERROR
     * e orienta o usuário a consultar --help em stderr.
     *
     * @return void
     */
    public function testNoArgumentsExitsWithUsageError(): void
    {
        $result = $this->runMain([]);

        self::assertSame(Application::EXIT_USAGE_ERROR, $result['exitCode']);
        self::assertStringContainsString('--help', $result['stderr']);
    }

    /**
     * Verifica que, ao compilar um .dsl existente, o script informa que o
     * pipeline ainda não foi integrado (EXIT_NOT_IMPLEMENTED) sem escrever
     * nada em stdout, ou seja, sem simular sucesso.
     *
     * @return void
     */
    public function testCompilationRequestReportsPipelineNotIntegrated(): void
    {
        $result = $this->runMain(['tests/Fixtures/placeholder.dsl']);

        self::assertSame(Application::EXIT_NOT_IMPLEMENTED, $result['exitCode']);
        self::assertStringContainsString('ainda não foi integrado', $result['stderr']);
        self::assertSame('', $result['stdout']);
    }
}
