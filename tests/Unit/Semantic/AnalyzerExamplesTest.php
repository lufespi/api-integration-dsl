<?php

declare(strict_types=1);

namespace Tests\Unit\Semantic;

use App\Exceptions\SemanticException;
use App\Lexer\Lexer;
use App\Parser\Parser;
use App\Semantic\Analyzer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exemplos válidos passam pelo Analyzer; os de examples/invalid/semantic/ são aceitos
 * pelo Parser e rejeitados exatamente com os erros das linhas "// Erro esperado: ...".
 *
 * @author pedro.augusto
 * @since 2026-11-28
 */
final class AnalyzerExamplesTest extends TestCase
{
    private const EXAMPLES = __DIR__ . '/../../../examples';

    /** Prefixo das linhas de comentário que documentam o erro esperado. */
    private const EXPECTED_ERROR_MARKER = '// Erro esperado: ';

    /** Verifica que cada exemplo válido passa pelo Analyzer sem erro. */
    #[DataProvider('validExamples')]
    public function testValidExampleHasNoSemanticErrors(string $path): void
    {
        $program = (new Parser((new Lexer((string) file_get_contents($path)))->tokenize()))->parse();

        self::assertSame($program, (new Analyzer())->analyze($program));
    }

    /**
     * Fornece os arquivos .dsl de examples/valid/.
     *
     * @return iterable<string, array{string}>
     */
    public static function validExamples(): iterable
    {
        return self::examplesIn('valid');
    }

    /**
     * Verifica que cada exemplo com erro semântico é sintaticamente correto,
     * é rejeitado pelo Analyzer e que a SemanticException contém exatamente
     * os erros documentados no próprio arquivo, na mesma ordem.
     */
    #[DataProvider('semanticErrorExamples')]
    public function testSemanticErrorExampleIsRejectedWithDocumentedErrors(string $path): void
    {
        $source = (string) file_get_contents($path);
        $expected = [];
        foreach (explode("\n", $source) as $line) {
            if (str_starts_with($line, self::EXPECTED_ERROR_MARKER)) {
                $expected[] = substr($line, strlen(self::EXPECTED_ERROR_MARKER));
            }
        }
        self::assertNotEmpty($expected, 'O exemplo deve documentar ao menos um "// Erro esperado:".');

        // Erro semântico só existe em programa sintaticamente válido: o Parser deve aceitá-lo.
        $program = (new Parser((new Lexer($source))->tokenize()))->parse();

        try {
            (new Analyzer())->analyze($program);
            self::fail('O exemplo deveria conter erro semântico: ' . $path);
        } catch (SemanticException $exception) {
            self::assertSame(
                $expected,
                array_map(static fn ($error): string => $error->format(), $exception->errors()),
            );
        }
    }

    /**
     * Fornece os arquivos .dsl de examples/invalid/semantic/.
     *
     * @return iterable<string, array{string}>
     */
    public static function semanticErrorExamples(): iterable
    {
        return self::examplesIn('invalid/semantic');
    }

    /**
     * Lista os .dsl de um subdiretório de examples/, indexados pelo nome do arquivo.
     *
     * @return iterable<string, array{string}>
     */
    private static function examplesIn(string $directory): iterable
    {
        $files = glob(self::EXAMPLES . '/' . $directory . '/*.dsl') ?: [];
        sort($files);

        foreach ($files as $file) {
            yield $directory . '/' . basename($file) => [$file];
        }
    }
}
