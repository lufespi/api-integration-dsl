<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Cli\Application;
use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\TestCase;

/**
 * Garante que o mapeamento PSR-4 declarado no composer.json está ativo,
 * para que as classes de Lexer, Parser, Ast e Generator sejam encontradas
 * assim que forem criadas em src/.
 *
 * @author lufespi
 * @since 2026-11-17
 */
final class AutoloadTest extends TestCase
{
    /**
     * Verifica que o autoloader do Composer registra o prefixo PSR-4 "App\"
     * apontando exclusivamente para o diretório src/.
     */
    public function testAppNamespaceIsMappedToSrcDirectory(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $vendorDir = self::normalizePath($projectRoot . '/vendor');
        $loader = null;
        foreach (ClassLoader::getRegisteredLoaders() as $directory => $registered) {
            // No Windows a chave usa "\" (ex.: C:\proj\vendor); compara normalizado.
            if (self::normalizePath($directory) === $vendorDir) {
                $loader = $registered;
            }
        }

        self::assertInstanceOf(ClassLoader::class, $loader, 'Autoloader do Composer não registrado para este projeto.');

        $prefixes = $loader->getPrefixesPsr4();
        self::assertArrayHasKey('App\\', $prefixes);
        self::assertSame(
            [self::normalizePath($projectRoot . '/src')],
            array_map(self::normalizePath(...), $prefixes['App\\']),
        );
    }

    /**
     * Verifica que uma classe do namespace "App\" (Application) é carregada do
     * arquivo esperado em src/, confirmando o autoload na prática.
     */
    public function testClassInAppNamespaceIsResolvedFromSrc(): void
    {
        $file = (new \ReflectionClass(Application::class))->getFileName();

        self::assertIsString($file);
        self::assertSame(self::normalizePath(dirname(__DIR__, 2) . '/src/Cli/Application.php'), self::normalizePath($file));
    }

    /**
     * Normaliza um caminho para comparação entre plataformas: resolve ".." e
     * links via realpath(), usa "/" como separador e,
     * no Windows (sistema de arquivos sem distinção de caixa), ignora a caixa.
     */
    private static function normalizePath(string $path): string
    {
        $normalized = str_replace('\\', '/', realpath($path) ?: $path);

        return PHP_OS_FAMILY === 'Windows' ? strtolower($normalized) : $normalized;
    }
}
