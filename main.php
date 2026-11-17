<?php

declare(strict_types=1);

/*
 * API Integration DSL - ponto de entrada da linha de comando.
 *
 * Uso:
 *   php main.php <arquivo.dsl>
 *   php main.php --help
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script deve ser executado pela linha de comando.\n");
    exit(1);
}

$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Dependências não instaladas. Execute \"composer install\" antes de usar a CLI.\n");
    exit(1);
}

require $autoload;

use App\Cli\Application;

try {
    $exitCode = (new Application(STDOUT, STDERR))->run($argv);
} catch (Throwable $e) {
    // Falha inesperada (bug): mensagem curta para o usuário, sem stack trace.
    fwrite(STDERR, sprintf("Erro interno: %s\n", $e->getMessage()));
    $exitCode = 1;
}

exit($exitCode);
