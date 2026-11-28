<?php

declare(strict_types=1);

namespace Tests\Unit\Parser;

use App\Exceptions\SyntaxException;
use App\Lexer\Lexer;
use App\Parser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exemplos válidos são aceitos; os de examples/invalid/syntactic/ são rejeitados
 * exatamente com os erros das suas linhas "// Erro esperado: ...".
 *
 * @author paulacsilva
 * @since 2026-11-20
 */
final class ExamplesTest extends TestCase
{
    private const EXAMPLES = __DIR__ . '/../../../examples';

    /** Prefixo das linhas de comentário que documentam o erro esperado. */
    private const EXPECTED_ERROR_MARKER = '// Erro esperado: ';

    /**
     * Verifica que cada exemplo válido é reconhecido pela gramática e produz
     * uma AST com ao menos uma API e uma integração.
     */
    #[DataProvider('validExamples')]
    public function testValidExampleIsAccepted(string $path): void
    {
        $program = (new Parser((new Lexer((string) file_get_contents($path)))->tokenize()))->parse();

        self::assertNotEmpty($program->apis);
        self::assertNotEmpty($program->integrations);
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
     * Verifica que cada exemplo com erro sintático é rejeitado e que a
     * SyntaxException contém exatamente os erros documentados no próprio
     * arquivo, na mesma ordem.
     */
    #[DataProvider('syntacticErrorExamples')]
    public function testSyntacticErrorExampleIsRejectedWithDocumentedErrors(string $path): void
    {
        $source = (string) file_get_contents($path);
        $expected = [];
        foreach (explode("\n", $source) as $line) {
            if (str_starts_with($line, self::EXPECTED_ERROR_MARKER)) {
                $expected[] = substr($line, strlen(self::EXPECTED_ERROR_MARKER));
            }
        }
        self::assertNotEmpty($expected, 'O exemplo deve documentar ao menos um "// Erro esperado:".');

        try {
            (new Parser((new Lexer($source))->tokenize()))->parse();
            self::fail('O exemplo deveria conter erro sintático: ' . $path);
        } catch (SyntaxException $exception) {
            self::assertSame(
                $expected,
                array_map(static fn ($error): string => $error->format(), $exception->errors()),
            );
        }
    }

    /**
     * Fornece os arquivos .dsl de examples/invalid/syntactic/.
     *
     * @return iterable<string, array{string}>
     */
    public static function syntacticErrorExamples(): iterable
    {
        return self::examplesIn('invalid/syntactic');
    }

    /**
     * Verifica que as posições citadas em docs/grammar.md (seção 10) para
     * o exemplo 3 correspondem às produzidas pelo Parser, mantendo a
     * documentação fiel à implementação.
     */
    public function testPositionsDocumentedInAstContractMatchExample3(): void
    {
        $source = (string) file_get_contents(self::EXAMPLES . '/valid/exemplo3.dsl');
        $program = (new Parser((new Lexer($source))->tokenize()))->parse();

        $header = $program->apis[0]->headers[0]->toArray();
        self::assertSame([12, 5], [$header['line'], $header['column']]);
        self::assertSame([12, 30], [$header['value']['left']['line'], $header['value']['left']['column']]);
        self::assertSame([12, 42], [$header['value']['right']['line'], $header['value']['right']['column']]);

        $mappings = $program->integrations[0]->mappings;
        $age = $mappings[2]->toArray();
        self::assertSame(['age', 33, 9], [$age['field'], $age['line'], $age['column']]);
        self::assertSame([33, 25], [$age['value']['line'], $age['value']['column']]);
        self::assertSame([33, 27], [$age['value']['right']['line'], $age['value']['right']['column']]);

        $active = $mappings[3]->toArray();
        self::assertSame(['!', 34, 24], [$active['value']['operator'], $active['value']['line'], $active['value']['column']]);
    }

    /**
     * Verifica que existem ao menos os exemplos exigidos pelo enunciado do
     * trabalho: 3 programas válidos e 2 com erros sintáticos.
     */
    public function testMinimumNumberOfExamplesRequiredByTheAssignment(): void
    {
        self::assertGreaterThanOrEqual(3, iterator_count(self::examplesIn('valid')));
        self::assertGreaterThanOrEqual(2, iterator_count(self::examplesIn('invalid/syntactic')));
    }

    /** @return \Generator<string, array{string}> */
    private static function examplesIn(string $directory): \Generator
    {
        $files = glob(self::EXAMPLES . '/' . $directory . '/*.dsl') ?: [];
        sort($files);

        foreach ($files as $file) {
            yield basename($file) => [$file];
        }
    }
}
