<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Cli\Application;
use PHPUnit\Framework\TestCase;

/**
 * Executa main.php em um processo PHP separado, como o usuário faria no
 * terminal. Não depende de rede nem de APIs externas: o PHP gerado é apenas
 * validado com "php -l", nunca executado.
 *
 * As compilações bem-sucedidas gravam no output/ do projeto; por isso cada
 * teste usa um arquivo de entrada com nome único e remove o .php gerado.
 */
final class MainScriptTest extends TestCase
{
    private const PROJECT_ROOT = __DIR__ . '/../..';

    /** @var list<string> Arquivos a remover ao final do teste. */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $file) {
            @unlink($file);
        }
    }

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
     * Copia um programa para um .dsl temporário de nome único.
     *
     * @param string $source Arquivo .dsl de origem.
     *
     * @return array{input: string, output: string} Caminho de entrada e do .php esperado em output/.
     */
    private function uniqueCopyOf(string $source): array
    {
        $name = 'main-script-test-' . bin2hex(random_bytes(6));
        $input = sys_get_temp_dir() . '/' . $name . '.dsl';
        $output = self::PROJECT_ROOT . '/output/' . $name . '.php';
        copy($source, $input);
        $this->cleanup[] = $input;
        $this->cleanup[] = $output;

        return ['input' => $input, 'output' => $output];
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
     * "php main.php <arquivo.dsl>" grava output/<arquivo>.php, termina com
     * código 0 e o arquivo gerado tem sintaxe PHP válida.
     *
     * @return void
     */
    public function testValidProgramIsCompiledIntoOutputDirectory(): void
    {
        $paths = $this->uniqueCopyOf(self::PROJECT_ROOT . '/examples/valid/exemplo3.dsl');

        $result = $this->runMain([$paths['input']]);

        self::assertSame(Application::EXIT_SUCCESS, $result['exitCode'], $result['stderr']);
        self::assertSame('', $result['stderr']);
        self::assertStringContainsString('Compilação concluída', $result['stdout']);
        self::assertStringContainsString('output/' . basename($paths['output']), $result['stdout']);
        self::assertFileExists($paths['output']);

        $lint = $this->runLint($paths['output']);
        self::assertSame(0, $lint['exitCode'], $lint['stdout'] . $lint['stderr']);
    }

    /**
     * Programa com erro semântico: código 1, mensagem em stderr, nada em
     * stdout e nenhum arquivo em output/.
     *
     * @return void
     */
    public function testInvalidProgramExitsWithCompilationError(): void
    {
        $paths = $this->uniqueCopyOf(self::PROJECT_ROOT . '/examples/invalid/semantic/01-api-inexistente.dsl');

        $result = $this->runMain([$paths['input']]);

        self::assertSame(Application::EXIT_COMPILATION_ERROR, $result['exitCode']);
        self::assertSame('', $result['stdout']);
        self::assertStringContainsString("Erro semântico na linha 13, coluna 8: API 'Crm' não declarada", $result['stderr']);
        self::assertStringContainsString('Nenhum arquivo PHP foi gerado.', $result['stderr']);
        self::assertFileDoesNotExist($paths['output']);
    }

    /**
     * Executa "php -l" em um arquivo.
     *
     * @param string $path Arquivo PHP.
     *
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private function runLint(string $path): array
    {
        $process = proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exitCode' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
