<?php

declare(strict_types=1);

namespace Tests\Unit\Generator\Support;

use App\Generator\RuntimeLibrary;
use RuntimeException;
use stdClass;

/**
 * Script gerado, gravado em diretório temporário e executado em processo separado,
 * pois declara funções globais e chama exit().
 *
 * @author lufespi
 * @since 2026-11-26
 */
final class GeneratedScript
{
    private readonly string $directory;

    public readonly string $path;

    public function __construct(public readonly string $code)
    {
        $this->directory = sys_get_temp_dir() . '/dsl-generator-test-' . bin2hex(random_bytes(6));
        if (!mkdir($this->directory, 0700) && !is_dir($this->directory)) {
            throw new RuntimeException('Não foi possível criar ' . $this->directory);
        }
        $this->path = $this->directory . '/script.php';
        file_put_contents($this->path, $code);
    }

    /**
     * Script com apenas a biblioteca de runtime e uma função de teste
     * dsl_test_expression(stdClass $record) que devolve a expressão PHP dada.
     */
    public static function forExpression(string $phpExpression): self
    {
        return new self(
            "<?php\n\ndeclare(strict_types=1);\n\n" . RuntimeLibrary::CODE
            . "\nfunction dsl_test_expression(stdClass \$record): mixed\n{\n    return {$phpExpression};\n}\n",
        );
    }

    public function __destruct()
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->directory);
    }

    /**
     * Valida a sintaxe com "php -l".
     *
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    public function lint(): array
    {
        return $this->process([PHP_BINARY, '-l', $this->path]);
    }

    /**
     * Carrega o script com DSL_NO_AUTORUN e executa $snippet, que deve terminar com
     * "return <valor>;".
     *
     * @param array<string,string> $env
     *
     * @return array{value?: mixed, error?: string, class?: string, stderr: string}
     */
    public function call(string $snippet, array $env = []): array
    {
        $harness = $this->directory . '/harness.php';
        file_put_contents($harness, sprintf(
            <<<'PHP'
                <?php
                declare(strict_types=1);
                define('DSL_NO_AUTORUN', true);
                require %s;
                try {
                    $result = ['value' => (function (): mixed {
                        %s
                    })()];
                } catch (Throwable $exception) {
                    $result = ['error' => $exception->getMessage(), 'class' => $exception::class];
                }
                echo serialize($result);
                PHP,
            var_export($this->path, true),
            $snippet,
        ));

        $process = $this->process([PHP_BINARY, $harness], $env);
        $result = @unserialize($process['stdout'], ['allowed_classes' => [stdClass::class]]);
        if (!is_array($result)) {
            throw new RuntimeException(sprintf(
                "Saída inesperada do harness (código %d):\nstdout: %s\nstderr: %s",
                $process['exitCode'],
                $process['stdout'],
                $process['stderr'],
            ));
        }
        $result['stderr'] = $process['stderr'];

        return $result;
    }

    /**
     * @param array<string,string> $env
     *
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    public function run(array $env = []): array
    {
        return $this->process([PHP_BINARY, $this->path], $env);
    }

    /**
     * @param list<string>         $command
     * @param array<string,string> $env
     *
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private function process(array $command, array $env = []): array
    {
        $stdout = $this->directory . '/stdout.txt';
        $stderr = $this->directory . '/stderr.txt';
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['file', $stdout, 'w'], 2 => ['file', $stderr, 'w']],
            $pipes,
            $this->directory,
            ['PATH' => (string) getenv('PATH')] + $env,
        );
        if ($process === false) {
            throw new RuntimeException('Não foi possível executar ' . implode(' ', $command));
        }
        fclose($pipes[0]);
        $exitCode = proc_close($process);

        return [
            'exitCode' => $exitCode,
            'stdout' => (string) file_get_contents($stdout),
            'stderr' => (string) file_get_contents($stderr),
        ];
    }
}
