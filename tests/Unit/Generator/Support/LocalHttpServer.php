<?php

declare(strict_types=1);

namespace Tests\Unit\Generator\Support;

use RuntimeException;

/**
 * Servidor embutido do PHP ("php -S") em 127.0.0.1 que substitui as APIs nos testes.
 * Rotas não configuradas respondem 201 com {"ok":true}.
 *
 * @author lufespi
 * @since 2026-11-26
 */
final class LocalHttpServer
{
    private const ROUTER = <<<'PHP'
        <?php
        declare(strict_types=1);
        $routesFile = __DIR__ . '/routes.json';
        $routes = is_file($routesFile) ? json_decode((string) file_get_contents($routesFile), true) : [];
        $method = $_SERVER['REQUEST_METHOD'];
        $path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        file_put_contents(__DIR__ . '/requests.log', json_encode([
            'method' => $method,
            'path' => $path,
            'query' => (string) ($_SERVER['QUERY_STRING'] ?? ''),
            'headers' => array_change_key_case(getallheaders(), CASE_LOWER),
            'body' => (string) file_get_contents('php://input'),
        ]) . "\n", FILE_APPEND | LOCK_EX);
        $route = $routes[$method . ' ' . $path] ?? ['status' => 201, 'body' => '{"ok":true}'];
        http_response_code($route['status']);
        header('Content-Type: application/json');
        echo $route['body'];
        return true;
        PHP;

    /** @var resource */
    private $process;

    private function __construct(private readonly string $directory, public readonly int $port)
    {
    }

    public static function start(): self
    {
        $directory = sys_get_temp_dir() . '/dsl-http-test-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        file_put_contents($directory . '/router.php', self::ROUTER);

        $server = new self($directory, self::freePort());
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $server->port, $directory . '/router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $directory . '/server.log', 'w'], 2 => ['file', $directory . '/server.log', 'a']],
            $pipes,
            $directory,
        );
        if ($process === false) {
            throw new RuntimeException('Não foi possível iniciar o servidor HTTP de testes.');
        }
        $server->process = $process;

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $socket = @fsockopen('127.0.0.1', $server->port, $errorCode, $errorMessage, 0.1);
            if ($socket !== false) {
                fclose($socket);

                return $server;
            }
            usleep(50_000);
        }
        $server->stop();

        throw new RuntimeException('O servidor HTTP de testes não respondeu na porta ' . $server->port);
    }

    /** Porta TCP livre em 127.0.0.1 (também útil para simular conexão recusada). */
    public static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if ($socket === false) {
            throw new RuntimeException('Não foi possível reservar uma porta: ' . $errorMessage);
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    public function url(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    /** Define a resposta de "MÉTODO /caminho". */
    public function respond(string $method, string $path, int $status, string $body): void
    {
        $routes = $this->routes();
        $routes[$method . ' ' . $path] = ['status' => $status, 'body' => $body];
        file_put_contents($this->directory . '/routes.json', json_encode($routes));
    }

    /** Remove as respostas configuradas e o registro de requisições. */
    public function reset(): void
    {
        @unlink($this->directory . '/routes.json');
        @unlink($this->directory . '/requests.log');
    }

    /**
     * Requisições recebidas, na ordem de chegada.
     *
     * @return list<array{method: string, path: string, query: string, headers: array<string, string>, body: string}>
     */
    public function requests(): array
    {
        $log = $this->directory . '/requests.log';
        if (!is_file($log)) {
            return [];
        }
        $lines = array_filter(explode("\n", (string) file_get_contents($log)));

        return array_values(array_map(static fn (string $line): array => json_decode($line, true), $lines));
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->directory);
    }

    /** @return array<string, array{status: int, body: string}> */
    private function routes(): array
    {
        $file = $this->directory . '/routes.json';

        return is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
    }
}
