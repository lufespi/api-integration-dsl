<?php

declare(strict_types=1);

namespace Tests\Unit\Cli;

use App\Cli\Application;
use App\Generator\PhpGenerator;
use App\Lexer\Lexer;
use App\Parser\Parser;
use App\Semantic\Analyzer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Testes da CLI: argumentos, pipeline completo (Lexer -> Parser -> Analyzer ->
 * PhpGenerator), gravação em output/ e códigos de saída.
 *
 * Cada teste usa um diretório de saída temporário próprio, nunca o output/
 * do projeto. O PHP gerado só é validado com "php -l": não é executado e
 * nenhuma requisição HTTP é feita.
 */
final class ApplicationTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures';

    private const EXAMPLES = __DIR__ . '/../../../examples';

    /** Prefixo das linhas de comentário que documentam o erro esperado de um exemplo. */
    private const EXPECTED_ERROR_MARKER = '// Erro esperado: ';

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    /** Diretório temporário de trabalho do teste. */
    private string $workDirectory;

    /** Diretório de saída usado pela CLI no teste. */
    private string $outputDirectory;

    protected function setUp(): void
    {
        $this->stdout = fopen('php://memory', 'w+');
        $this->stderr = fopen('php://memory', 'w+');

        $this->workDirectory = sys_get_temp_dir() . '/dsl-cli-test-' . bin2hex(random_bytes(6));
        if (!mkdir($this->workDirectory, 0700)) {
            throw new RuntimeException('Não foi possível criar ' . $this->workDirectory);
        }
        $this->outputDirectory = $this->workDirectory . '/output';
    }

    protected function tearDown(): void
    {
        fclose($this->stdout);
        fclose($this->stderr);
        self::removeTree($this->workDirectory);
    }

    /**
     * Remove um diretório e todo o seu conteúdo.
     *
     * @param string $path Diretório ou arquivo.
     *
     * @return void
     */
    private static function removeTree(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            @chmod($path, 0700);
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::removeTree($path . '/' . $entry);
                }
            }
            @rmdir($path);

            return;
        }

        @unlink($path);
    }

    /** @param list<string> $args */
    private function runCli(array $args, ?string $outputDirectory = null): int
    {
        return (new Application($this->stdout, $this->stderr, $outputDirectory ?? $this->outputDirectory))
            ->run(['main.php', ...$args]);
    }

    /** @param resource $stream */
    private function contents($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    /**
     * Arquivos presentes no diretório de saída (inclusive ocultos/temporários).
     *
     * @return list<string>
     */
    private function outputFiles(): array
    {
        if (!is_dir($this->outputDirectory)) {
            return [];
        }

        $files = array_values(array_diff(scandir($this->outputDirectory) ?: [], ['.', '..']));
        sort($files);

        return $files;
    }

    /**
     * Grava um arquivo .dsl no diretório de trabalho e devolve seu caminho.
     *
     * @param string $name   Nome do arquivo.
     * @param string $source Conteúdo.
     *
     * @return string
     */
    private function writeSource(string $name, string $source): string
    {
        $path = $this->workDirectory . '/' . $name;
        file_put_contents($path, $source);

        return $path;
    }

    /**
     * Valida a sintaxe de um arquivo PHP com "php -l", em processo separado.
     *
     * @param string $path Arquivo PHP.
     *
     * @return array{exitCode: int, output: string}
     */
    private static function lint(string $path): array
    {
        $process = proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exitCode' => proc_close($process), 'output' => $output];
    }

    /**
     * Linhas "// Erro esperado: ..." de um exemplo, sem o prefixo.
     *
     * @param string $path Exemplo .dsl.
     *
     * @return list<string>
     */
    private static function expectedErrors(string $path): array
    {
        $expected = [];
        foreach (explode("\n", (string) file_get_contents($path)) as $line) {
            if (str_starts_with($line, self::EXPECTED_ERROR_MARKER)) {
                $expected[] = rtrim(substr($line, strlen(self::EXPECTED_ERROR_MARKER)), "\r");
            }
        }

        return $expected;
    }

    /**
     * Arquivos .dsl de um subdiretório de examples/, indexados pelo caminho relativo.
     *
     * @param string $directory Subdiretório de examples/.
     *
     * @return array<string, array{string}>
     */
    private static function examplesIn(string $directory): array
    {
        $files = glob(self::EXAMPLES . '/' . $directory . '/*.dsl') ?: [];
        sort($files);

        $examples = [];
        foreach ($files as $file) {
            $examples[$directory . '/' . basename($file)] = [$file];
        }

        return $examples;
    }

    // ------------------------------------------------------------------
    // Argumentos e ajuda
    // ------------------------------------------------------------------

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
        self::assertStringContainsString('output/', $this->contents($this->stdout));
        self::assertStringNotContainsString('ainda não', $this->contents($this->stdout));
        self::assertSame('', $this->contents($this->stderr));
        self::assertSame([], $this->outputFiles());
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
        self::assertSame([], $this->outputFiles());
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
     * Um arquivo chamado apenas ".dsl" não tem nome para o arquivo de saída.
     *
     * @return void
     */
    public function testFileNamedOnlyByTheExtensionIsRejected(): void
    {
        $exitCode = $this->runCli([$this->writeSource('.dsl', '')]);

        self::assertSame(Application::EXIT_USAGE_ERROR, $exitCode);
        self::assertStringContainsString('só a extensão', $this->contents($this->stderr));
        self::assertSame([], $this->outputFiles());
    }

    /**
     * Arquivo sem permissão de leitura é erro de uso, sem tentar compilar.
     *
     * @return void
     */
    public function testUnreadableFileIsAUsageError(): void
    {
        $path = $this->writeSource('secreto.dsl', (string) file_get_contents(self::EXAMPLES . '/valid/exemplo1.dsl'));
        chmod($path, 0000);
        if (is_readable($path)) {
            self::markTestSkipped('O usuário atual lê arquivos sem permissão (ex.: root).');
        }

        $exitCode = $this->runCli([$path]);

        self::assertSame(Application::EXIT_USAGE_ERROR, $exitCode);
        self::assertStringContainsString('sem permissão de leitura', $this->contents($this->stderr));
        self::assertSame([], $this->outputFiles());
    }

    // ------------------------------------------------------------------
    // Compilação bem-sucedida
    // ------------------------------------------------------------------

    /**
     * Cada exemplo válido é compilado para output/<nome>.php: código 0,
     * mensagem de sucesso em stdout, stderr vazio, conteúdo idêntico ao do
     * pipeline Lexer -> Parser -> Analyzer -> PhpGenerator e sintaxe válida.
     *
     * @param string $path Exemplo válido.
     *
     * @return void
     */
    #[DataProvider('validExamples')]
    public function testValidExampleIsCompiledToOutputDirectory(string $path): void
    {
        $target = $this->outputDirectory . '/' . basename($path, '.dsl') . '.php';

        $exitCode = $this->runCli([$path]);

        self::assertSame(Application::EXIT_SUCCESS, $exitCode, $this->contents($this->stderr));
        self::assertSame('', $this->contents($this->stderr));
        self::assertSame(
            sprintf("Compilação concluída: %s -> %s\n", $path, $target),
            $this->contents($this->stdout),
        );
        self::assertSame([basename($target)], $this->outputFiles(), 'Nenhum arquivo temporário pode sobrar.');

        $program = (new Parser((new Lexer((string) file_get_contents($path)))->tokenize()))->parse();
        $expected = (new PhpGenerator())->generate((new Analyzer())->analyze($program));
        self::assertSame($expected, file_get_contents($target));

        $lint = self::lint($target);
        self::assertSame(0, $lint['exitCode'], $lint['output']);
    }

    /**
     * Fornece os exemplos de examples/valid/.
     *
     * @return array<string, array{string}>
     */
    public static function validExamples(): array
    {
        return self::examplesIn('valid');
    }

    /**
     * Os exemplos distribuídos com o projeto estão todos presentes: 3 válidos,
     * 3 com erros léxicos, 4 com erros sintáticos e 3 com erros semânticos.
     *
     * @return void
     */
    public function testAllExamplesArePresent(): void
    {
        self::assertCount(3, self::examplesIn('valid'));
        self::assertCount(3, self::examplesIn('invalid/lexical'));
        self::assertCount(4, self::examplesIn('invalid/syntactic'));
        self::assertCount(3, self::examplesIn('invalid/semantic'));
    }

    /**
     * A extensão é reconhecida sem diferenciar maiúsculas, e o arquivo de
     * saída usa o nome do arquivo de entrada sem a extensão.
     *
     * @return void
     */
    public function testOutputNameComesFromInputFileName(): void
    {
        $path = $this->writeSource(
            'Minha.Integracao.DSL',
            (string) file_get_contents(self::EXAMPLES . '/valid/exemplo1.dsl'),
        );

        $exitCode = $this->runCli([$path]);

        self::assertSame(Application::EXIT_SUCCESS, $exitCode, $this->contents($this->stderr));
        self::assertSame(['Minha.Integracao.php'], $this->outputFiles());
    }

    /**
     * O diretório de saída é criado se ainda não existir.
     *
     * @return void
     */
    public function testMissingOutputDirectoryIsCreated(): void
    {
        $nested = $this->workDirectory . '/a/b/output';

        $exitCode = $this->runCli([self::EXAMPLES . '/valid/exemplo1.dsl'], $nested);

        self::assertSame(Application::EXIT_SUCCESS, $exitCode, $this->contents($this->stderr));
        self::assertFileExists($nested . '/exemplo1.php');
    }

    /**
     * Uma nova compilação bem-sucedida substitui o arquivo anterior.
     *
     * @return void
     */
    public function testSuccessfulCompilationReplacesPreviousOutput(): void
    {
        mkdir($this->outputDirectory);
        $target = $this->outputDirectory . '/exemplo2.php';
        file_put_contents($target, '<?php // versão antiga');

        $exitCode = $this->runCli([self::EXAMPLES . '/valid/exemplo2.dsl']);

        self::assertSame(Application::EXIT_SUCCESS, $exitCode, $this->contents($this->stderr));
        self::assertStringNotContainsString('versão antiga', (string) file_get_contents($target));
        self::assertStringStartsWith('<?php', (string) file_get_contents($target));
        self::assertSame(['exemplo2.php'], $this->outputFiles());
    }

    // ------------------------------------------------------------------
    // Erros de compilação
    // ------------------------------------------------------------------

    /**
     * Cada exemplo inválido (léxico, sintático ou semântico) termina com
     * EXIT_COMPILATION_ERROR, imprime em stderr exatamente os erros
     * documentados no próprio arquivo e não grava nada em output/.
     *
     * @param string $path Exemplo inválido.
     *
     * @return void
     */
    #[DataProvider('invalidExamples')]
    public function testInvalidExampleFailsWithDocumentedErrors(string $path): void
    {
        $expected = self::expectedErrors($path);
        self::assertNotEmpty($expected, 'O exemplo deve documentar ao menos um "// Erro esperado:".');

        $exitCode = $this->runCli([$path]);
        $stderr = $this->contents($this->stderr);

        self::assertSame(Application::EXIT_COMPILATION_ERROR, $exitCode);
        self::assertSame('', $this->contents($this->stdout));
        foreach ($expected as $error) {
            self::assertStringContainsString($error, $stderr);
        }
        self::assertSame(count($expected), substr_count($stderr, 'Erro '), $stderr);
        self::assertStringEndsWith("Nenhum arquivo PHP foi gerado.\n", $stderr);
        self::assertSame([], $this->outputFiles());
    }

    /**
     * Fornece os exemplos de examples/invalid/ (léxicos, sintáticos e semânticos).
     *
     * @return array<string, array{string}>
     */
    public static function invalidExamples(): array
    {
        return [
            ...self::examplesIn('invalid/lexical'),
            ...self::examplesIn('invalid/syntactic'),
            ...self::examplesIn('invalid/semantic'),
        ];
    }

    /**
     * Programa válido para o Analyzer, mas rejeitado pelo Gerador (origem
     * POST): erro de geração com posição, código 1 e nenhum arquivo.
     *
     * @return void
     */
    public function testGenerationErrorFailsWithoutWritingOutput(): void
    {
        $path = self::FIXTURES . '/generation-error.dsl';

        $exitCode = $this->runCli([$path]);

        self::assertSame(Application::EXIT_COMPILATION_ERROR, $exitCode);
        self::assertSame('', $this->contents($this->stdout));
        self::assertSame(
            self::expectedErrors($path)[0] . "\nNenhum arquivo PHP foi gerado.\n",
            $this->contents($this->stderr),
        );
        self::assertStringStartsWith('Erro de geração na linha 17, coluna 10:', $this->contents($this->stderr));
        self::assertSame([], $this->outputFiles());
    }

    /**
     * Se a compilação falhar, o arquivo de mesmo nome deixado por uma
     * compilação anterior é removido, para não ser confundido com o
     * resultado atual.
     *
     * @return void
     */
    public function testFailedCompilationRemovesStaleOutput(): void
    {
        $valid = (string) file_get_contents(self::EXAMPLES . '/valid/exemplo1.dsl');
        $path = $this->writeSource('programa.dsl', $valid);
        self::assertSame(Application::EXIT_SUCCESS, $this->runCli([$path]));
        self::assertSame(['programa.php'], $this->outputFiles());

        file_put_contents($path, str_replace('to Crm.createContact;', 'to Crm.missing;', $valid));
        $exitCode = $this->runCli([$path]);

        self::assertSame(Application::EXIT_COMPILATION_ERROR, $exitCode);
        self::assertStringContainsString("a API 'Crm' não declara o endpoint 'missing'", $this->contents($this->stderr));
        self::assertStringContainsString('compilação anterior removido', $this->contents($this->stderr));
        self::assertStringContainsString('programa.php', $this->contents($this->stderr));
        self::assertSame([], $this->outputFiles());
    }

    // ------------------------------------------------------------------
    // Falhas de escrita
    // ------------------------------------------------------------------

    /**
     * Diretório de saída que não pode ser criado (o caminho é um arquivo):
     * código 1, mensagem em stderr.
     *
     * @return void
     */
    public function testOutputDirectoryThatCannotBeCreatedIsReported(): void
    {
        $blocker = $this->workDirectory . '/not-a-directory';
        file_put_contents($blocker, '');

        $exitCode = $this->runCli([self::EXAMPLES . '/valid/exemplo1.dsl'], $blocker . '/output');

        self::assertSame(Application::EXIT_COMPILATION_ERROR, $exitCode);
        self::assertSame('', $this->contents($this->stdout));
        self::assertStringContainsString('não foi possível criar o diretório de saída', $this->contents($this->stderr));
        self::assertStringContainsString('Nenhum arquivo PHP foi gerado.', $this->contents($this->stderr));
    }

    /**
     * Destino ocupado por um diretório: a renomeação falha, o temporário é
     * apagado e nada parcial fica em output/.
     *
     * @return void
     */
    public function testOutputFileThatCannotBeReplacedIsReported(): void
    {
        mkdir($this->outputDirectory . '/exemplo1.php', 0700, true);

        $exitCode = $this->runCli([self::EXAMPLES . '/valid/exemplo1.dsl']);

        self::assertSame(Application::EXIT_COMPILATION_ERROR, $exitCode);
        self::assertSame('', $this->contents($this->stdout));
        self::assertStringContainsString('não foi possível gravar o arquivo de saída', $this->contents($this->stderr));
        self::assertSame(['exemplo1.php'], $this->outputFiles(), 'O temporário deve ser removido.');
        self::assertDirectoryExists($this->outputDirectory . '/exemplo1.php');
    }

    /**
     * Diretório de saída sem permissão de escrita: código 1. O arquivo de
     * uma compilação anterior também não pode ser apagado, então a CLI avisa
     * que ele não corresponde à entrada atual.
     *
     * @return void
     */
    public function testReadOnlyOutputDirectoryIsReported(): void
    {
        mkdir($this->outputDirectory);
        file_put_contents($this->outputDirectory . '/exemplo1.php', '<?php // versão antiga');
        chmod($this->outputDirectory, 0500);
        if (is_writable($this->outputDirectory)) {
            self::markTestSkipped('O usuário atual escreve em diretórios sem permissão (ex.: root).');
        }

        $exitCode = $this->runCli([self::EXAMPLES . '/valid/exemplo1.dsl']);

        self::assertSame(Application::EXIT_COMPILATION_ERROR, $exitCode);
        self::assertSame('', $this->contents($this->stdout));
        self::assertStringContainsString('não foi possível gravar no diretório de saída', $this->contents($this->stderr));
        self::assertStringContainsString('NÃO corresponde ao arquivo de entrada atual', $this->contents($this->stderr));
        self::assertSame(['exemplo1.php'], $this->outputFiles());
    }

    // ------------------------------------------------------------------
    // Códigos de saída
    // ------------------------------------------------------------------

    /**
     * Verifica que cada constante EXIT_* da CLI possui um valor diferente e
     * que os valores são os documentados (0, 1 e 2).
     *
     * @return void
     */
    public function testExitCodesAreDistinct(): void
    {
        self::assertSame(0, Application::EXIT_SUCCESS);
        self::assertSame(1, Application::EXIT_COMPILATION_ERROR);
        self::assertSame(2, Application::EXIT_USAGE_ERROR);
        self::assertFalse(
            defined(Application::class . '::EXIT_NOT_IMPLEMENTED'),
            'O código "não implementado" não existe mais: o pipeline está integrado.',
        );
    }
}
