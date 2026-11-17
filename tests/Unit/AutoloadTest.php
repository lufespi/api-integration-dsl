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
 */
final class AutoloadTest extends TestCase
{
    /**
     * Verifica que o autoloader do Composer registra o prefixo PSR-4 "App\"
     * apontando exclusivamente para o diretório src/.
     *
     * @return void
     */
    public function testAppNamespaceIsMappedToSrcDirectory(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $loader = ClassLoader::getRegisteredLoaders()[$projectRoot . '/vendor'] ?? null;

        self::assertInstanceOf(ClassLoader::class, $loader, 'Autoloader do Composer não registrado para este projeto.');

        $prefixes = $loader->getPrefixesPsr4();
        self::assertArrayHasKey('App\\', $prefixes);
        self::assertSame(
            [realpath($projectRoot . '/src')],
            array_map('realpath', $prefixes['App\\']),
        );
    }

    /**
     * Verifica que uma classe do namespace "App\" (Application) é carregada do
     * arquivo esperado em src/, confirmando o autoload na prática.
     *
     * @return void
     */
    public function testClassInAppNamespaceIsResolvedFromSrc(): void
    {
        $file = (new \ReflectionClass(Application::class))->getFileName();

        self::assertSame(realpath(dirname(__DIR__, 2) . '/src/Cli/Application.php'), $file);
    }
}
