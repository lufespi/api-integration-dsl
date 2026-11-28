<?php

declare(strict_types=1);

namespace App\Cli;

use App\Exceptions\CompilerException;
use App\Generator\PhpGenerator;
use App\Lexer\Lexer;
use App\Parser\Parser;
use App\Semantic\Analyzer;
use Throwable;

/**
 * Ponto de entrada da linha de comando.
 *
 * Responsabilidades:
 *  - interpretar os argumentos recebidos;
 *  - exibir a ajuda (--help / -h);
 *  - validar o arquivo de entrada (.dsl existente e legível);
 *  - coordenar o pipeline de compilação:
 *      texto-fonte -> Lexer -> tokens -> Parser -> AST -> Analyzer -> PhpGenerator -> arquivo .php
 *  - gravar o PHP gerado em output/<nome-do-arquivo>.php;
 *  - devolver códigos de saída consistentes.
 *
 * A CLI não executa o PHP gerado e não faz requisições HTTP. Em caso de falha,
 * nenhum arquivo é gravado e um arquivo de mesmo nome deixado em output/ por
 * uma compilação anterior é removido, para não ser confundido com o resultado
 * da compilação atual.
 *
 * As saídas são recebidas como streams e o diretório de saída é configurável
 * para que a classe possa ser testada sem processos externos (ver
 * tests/Unit/Cli/ApplicationTest.php).
 */
final class Application
{
    public const EXIT_SUCCESS = 0;

    /** Erro de compilação (léxico, sintático, semântico ou de geração) ou falha de leitura/escrita. */
    public const EXIT_COMPILATION_ERROR = 1;

    /** Argumentos inválidos ou arquivo de entrada inacessível. */
    public const EXIT_USAGE_ERROR = 2;

    private const SOURCE_EXTENSION = 'dsl';

    private readonly string $outputDirectory;

    /**
     * @param resource    $stdout
     * @param resource    $stderr
     * @param string|null $outputDirectory Diretório onde o PHP gerado é gravado (padrão: output/ na raiz do projeto).
     */
    public function __construct(
        private $stdout,
        private $stderr,
        ?string $outputDirectory = null,
    ) {
        $this->outputDirectory = rtrim($outputDirectory ?? dirname(__DIR__, 2) . '/output', '/\\');
    }

    /**
     * @param list<string> $argv argumentos no formato de $argv (índice 0 = nome do script)
     */
    public function run(array $argv): int
    {
        $script = $argv[0] ?? 'main.php';
        $args = array_slice($argv, 1);

        if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
            $this->write($this->stdout, $this->helpText($script));

            return self::EXIT_SUCCESS;
        }

        $options = array_values(array_filter($args, static fn (string $arg): bool => str_starts_with($arg, '-')));
        if ($options !== []) {
            return $this->usageError($script, sprintf('opção desconhecida: %s', $options[0]));
        }

        if ($args === []) {
            return $this->usageError($script, 'nenhum arquivo de entrada informado.');
        }

        if (count($args) > 1) {
            return $this->usageError($script, 'informe apenas um arquivo .dsl por execução.');
        }

        $path = $args[0];
        $inputError = $this->validateInputFile($path);
        if ($inputError !== null) {
            return $this->usageError($script, $inputError);
        }

        return $this->compile($path);
    }

    /**
     * Executa o pipeline e grava o resultado em output/<nome>.php.
     *
     * @param string $path Arquivo .dsl já validado.
     *
     * @return int Código de saída.
     */
    private function compile(string $path): int
    {
        $target = $this->outputDirectory . DIRECTORY_SEPARATOR . pathinfo($path, PATHINFO_FILENAME) . '.php';

        try {
            $source = @file_get_contents($path);
            if ($source === false) {
                return $this->failure($target, sprintf('Erro: não foi possível ler o arquivo de entrada: %s', $path));
            }

            $tokens = (new Lexer($source))->tokenize();
            $program = (new Parser($tokens))->parse();
            $program = (new Analyzer())->analyze($program);
            $php = (new PhpGenerator())->generate($program);
        } catch (CompilerException $e) {
            return $this->failure($target, $e->getMessage());
        } catch (Throwable $e) {
            // Defeito do compilador (tratado como erro interno por main.php): o
            // arquivo antigo também não pode passar por resultado desta execução.
            $this->discardStaleOutput($target);

            throw $e;
        }

        $writeError = $this->writeOutput($target, $php);
        if ($writeError !== null) {
            return $this->failure($target, 'Erro: ' . $writeError);
        }

        $this->write($this->stdout, sprintf(
            "Compilação concluída: %s -> %s\n",
            $path,
            $this->displayPath($target),
        ));

        return self::EXIT_SUCCESS;
    }

    /**
     * Relata uma falha de compilação ou de leitura/escrita em stderr.
     *
     * @param string $target  Arquivo de saída que esta compilação produziria.
     * @param string $message Mensagem já formatada para o usuário.
     *
     * @return int EXIT_COMPILATION_ERROR.
     */
    private function failure(string $target, string $message): int
    {
        $this->write($this->stderr, rtrim($message) . "\n");
        $this->discardStaleOutput($target);
        $this->write($this->stderr, "Nenhum arquivo PHP foi gerado.\n");

        return self::EXIT_COMPILATION_ERROR;
    }

    /**
     * Remove o arquivo de saída deixado por uma compilação anterior, para que
     * ele não seja confundido com o resultado de uma compilação que falhou.
     *
     * @param string $target Arquivo de saída.
     *
     * @return void
     */
    private function discardStaleOutput(string $target): void
    {
        if (!is_file($target)) {
            return;
        }

        if (@unlink($target)) {
            $this->write($this->stderr, sprintf(
                "Arquivo de uma compilação anterior removido: %s\n",
                $this->displayPath($target),
            ));

            return;
        }

        $this->write($this->stderr, sprintf(
            "Atenção: não foi possível remover %s, gerado por uma compilação anterior; ele NÃO corresponde ao arquivo de entrada atual.\n",
            $this->displayPath($target),
        ));
    }

    /**
     * Grava o PHP de forma atômica: escreve em um arquivo temporário no mesmo
     * diretório e o renomeia para o destino. Assim, uma falha no meio da
     * escrita nunca deixa um arquivo .php parcial em output/.
     *
     * @param string $target Caminho final do arquivo .php.
     * @param string $php    Código gerado.
     *
     * @return string|null Mensagem de erro, ou null em caso de sucesso.
     */
    private function writeOutput(string $target, string $php): ?string
    {
        $directory = dirname($target);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            return sprintf('não foi possível criar o diretório de saída: %s', $this->displayPath($directory));
        }

        $temporary = sprintf('%s%s.%s.%s.tmp', $directory, DIRECTORY_SEPARATOR, basename($target), bin2hex(random_bytes(6)));
        $handle = @fopen($temporary, 'x');
        if ($handle === false) {
            return sprintf('não foi possível gravar no diretório de saída: %s', $this->displayPath($directory));
        }

        $written = @fwrite($handle, $php);
        $closed = @fclose($handle);

        if ($written !== strlen($php) || !$closed || !@rename($temporary, $target)) {
            @unlink($temporary);

            return sprintf('não foi possível gravar o arquivo de saída: %s', $this->displayPath($target));
        }

        return null;
    }

    private function validateInputFile(string $path): ?string
    {
        if (!file_exists($path)) {
            return sprintf('arquivo não encontrado: %s', $path);
        }

        if (!is_file($path)) {
            return sprintf('o caminho informado não é um arquivo: %s', $path);
        }

        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== self::SOURCE_EXTENSION) {
            return sprintf('o arquivo de entrada deve ter extensão .%s: %s', self::SOURCE_EXTENSION, $path);
        }

        if (pathinfo($path, PATHINFO_FILENAME) === '') {
            return sprintf('o nome do arquivo de entrada não pode ser só a extensão: %s', $path);
        }

        if (!is_readable($path)) {
            return sprintf('sem permissão de leitura: %s', $path);
        }

        return null;
    }

    /**
     * Caminho relativo ao diretório atual, quando estiver dentro dele.
     *
     * @param string $path Caminho absoluto ou relativo.
     *
     * @return string
     */
    private function displayPath(string $path): string
    {
        $cwd = getcwd();
        if ($cwd === false) {
            return $path;
        }

        $prefix = rtrim($cwd, '/\\') . DIRECTORY_SEPARATOR;

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }

    private function usageError(string $script, string $message): int
    {
        $this->write($this->stderr, sprintf(
            "Erro: %s\nUse \"php %s --help\" para ver as opções disponíveis.\n",
            $message,
            $script,
        ));

        return self::EXIT_USAGE_ERROR;
    }

    private function helpText(string $script): string
    {
        return <<<TXT
            API Integration DSL - compilador de integrações entre APIs HTTP para PHP

            Uso:
              php {$script} <arquivo.dsl>
              php {$script} --help

            Argumentos:
              <arquivo.dsl>   Arquivo-fonte escrito na DSL.

            Opções:
              -h, --help      Exibe esta ajuda.

            Saída:
              O código gerado é gravado em output/<nome-do-arquivo>.php (ex.:
              exemplo1.dsl -> output/exemplo1.php). O arquivo não é executado
              pelo compilador e nenhuma requisição HTTP é feita na compilação.
              Em caso de erro, nenhum arquivo é gerado.

            Códigos de saída:
              0  Sucesso (ou ajuda exibida)
              1  Erro de compilação (léxico, sintático, semântico ou de geração)
                 ou falha de leitura/escrita
              2  Uso incorreto ou arquivo de entrada inválido

            TXT;
    }

    /** @param resource $stream */
    private function write($stream, string $text): void
    {
        fwrite($stream, $text);
    }
}
