<?php

declare(strict_types=1);

namespace App\Cli;

/**
 * Ponto de entrada da linha de comando.
 *
 * Responsabilidades atuais:
 *  - interpretar os argumentos recebidos;
 *  - exibir a ajuda (--help / -h);
 *  - validar o arquivo de entrada (.dsl existente e legível);
 *  - devolver códigos de saída consistentes.
 *
 * Quando Lexer, Parser e Generator estiverem prontos, o método compile()
 * passará a coordenar o pipeline:
 *   texto-fonte -> Lexer -> tokens -> Parser -> AST -> PhpGenerator -> arquivo .php
 *
 * As saídas são recebidas como streams para que a classe possa ser testada
 * sem processos externos (ver tests/Unit/Cli/ApplicationTest.php).
 */
final class Application
{
    public const EXIT_SUCCESS = 0;

    /** Reservado para erros léxicos/sintáticos quando o pipeline estiver integrado. */
    public const EXIT_COMPILATION_ERROR = 1;

    /** Argumentos inválidos ou arquivo de entrada inacessível. */
    public const EXIT_USAGE_ERROR = 2;

    /** A funcionalidade solicitada ainda não foi implementada/integrada. */
    public const EXIT_NOT_IMPLEMENTED = 3;

    private const SOURCE_EXTENSION = 'dsl';

    /**
     * @param resource $stdout
     * @param resource $stderr
     */
    public function __construct(
        private $stdout,
        private $stderr,
    ) {
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

    private function compile(string $path): int
    {
        // Pipeline ainda não integrado: não há Lexer, Parser nem Generator.
        // Não simulamos sucesso nem produzimos arquivo em output/.
        $this->write($this->stderr, sprintf(
            "Arquivo de entrada encontrado: %s\n"
            . "O pipeline de compilação (Lexer -> Parser -> Generator) ainda não foi integrado.\n"
            . "Nenhum código PHP foi gerado.\n",
            $path,
        ));

        return self::EXIT_NOT_IMPLEMENTED;
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

        if (!is_readable($path)) {
            return sprintf('sem permissão de leitura: %s', $path);
        }

        return null;
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

            Códigos de saída:
              0  Sucesso (ou ajuda exibida)
              1  Erro de compilação (léxico/sintático) - reservado
              2  Uso incorreto ou arquivo de entrada inválido
              3  Funcionalidade ainda não integrada

            Situação: o pipeline Lexer -> Parser -> Generator ainda não foi integrado.

            TXT;
    }

    /** @param resource $stream */
    private function write($stream, string $text): void
    {
        fwrite($stream, $text);
    }
}
