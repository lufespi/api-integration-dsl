<?php

declare(strict_types=1);

namespace App\Generator;

/**
 * Código PHP fixo copiado uma vez para cada script gerado: HTTP com cURL, leitura
 * de campos, operadores, conversões de tipo e execução das integrações.
 *
 * As funções usam o prefixo "dsl_". Falhas operacionais viram DslRuntimeException
 * e são reportadas por dsl_run_integration() sem exibir valores de cabeçalhos ou
 * de variáveis de ambiente.
 *
 * @author lufespi
 * @since 2026-11-26
 */
final class RuntimeLibrary
{
    /** Código PHP da biblioteca, sem a tag de abertura "<?php". */
    public const CODE = <<<'PHP'
        // Tempos limite, em segundos: conexão e requisição completa.
        const DSL_CONNECT_TIMEOUT = 10;
        const DSL_REQUEST_TIMEOUT = 30;

        /** Falha operacional: rede, status HTTP, JSON, dados do registro ou configuração. */
        final class DslRuntimeException extends RuntimeException
        {
        }

        /** env("NOME"): variável obrigatória; o valor nunca aparece em mensagens. */
        function dsl_env(string $name): string
        {
            $value = getenv($name);
            if ($value === false || $value === '') {
                throw new DslRuntimeException(sprintf('variável de ambiente obrigatória "%s" não definida', $name));
            }

            return $value;
        }

        /**
         * Campo ausente, ou caminho que atravessa um valor que não é objeto, resulta
         * em null. Número fora do intervalo de float (1e400 vira INF) é rejeitado.
         *
         * @param list<string> $path segmentos após o alias
         */
        function dsl_field(mixed $record, array $path): mixed
        {
            $value = $record;
            foreach ($path as $segment) {
                if (!$value instanceof stdClass || !property_exists($value, $segment)) {
                    return null;
                }
                $value = $value->{$segment};
            }
            if (is_float($value) && !is_finite($value)) {
                throw new DslRuntimeException(
                    sprintf('o campo "%s" contém um número fora do intervalo suportado', implode('.', $path)),
                );
            }

            return $value;
        }

        /** Tipo do valor para mensagens de erro, sem expor o valor. */
        function dsl_type(mixed $value): string
        {
            return match (true) {
                $value === null => 'null',
                is_bool($value) => 'bool',
                is_int($value) => 'int',
                is_float($value) => 'float',
                is_string($value) => 'string',
                is_array($value) => 'lista',
                default => 'objeto',
            };
        }

        function dsl_is_number(mixed $value): bool
        {
            return is_int($value) || is_float($value);
        }

        /**
         * Menor texto que reconstrói o decimal (1234567890.123456 não vira
         * "1234567890.1235"), independentemente da configuração "precision".
         */
        function dsl_float_text(float $value): string
        {
            $precision = ini_set('precision', '-1');
            try {
                return (string) $value;
            } finally {
                if ($precision !== false) {
                    ini_set('precision', $precision);
                }
            }
        }

        function dsl_text(mixed $value): string
        {
            return match (true) {
                is_string($value) => $value,
                is_int($value) => (string) $value,
                is_float($value) => dsl_float_text($value),
                is_bool($value) => $value ? 'true' : 'false',
                default => throw new DslRuntimeException(
                    sprintf('não é possível usar um valor %s como texto', dsl_type($value)),
                ),
            };
        }

        /** "+": concatena se algum operando for texto (decidido em execução); senão, soma. */
        function dsl_add(mixed $left, mixed $right): int|float|string
        {
            if (is_string($left) || is_string($right)) {
                return dsl_text($left) . dsl_text($right);
            }

            return dsl_arith('+', $left, $right);
        }

        function dsl_arith(string $operator, mixed $left, mixed $right): int|float
        {
            if (!dsl_is_number($left) || !dsl_is_number($right)) {
                throw new DslRuntimeException(sprintf(
                    'o operador "%s" exige números, mas recebeu %s e %s',
                    $operator,
                    dsl_type($left),
                    dsl_type($right),
                ));
            }
            if (($operator === '/' || $operator === '%') && $right == 0) {
                throw new DslRuntimeException(sprintf('divisão por zero (operador "%s")', $operator));
            }

            $result = match ($operator) {
                '+' => $left + $right,
                '-' => $left - $right,
                '*' => $left * $right,
                '/' => $left / $right,
                '%' => is_int($left) && is_int($right) ? $left % $right : fmod($left, $right),
            };
            if (is_float($result) && !is_finite($result)) {
                throw new DslRuntimeException(
                    sprintf('o operador "%s" produziu um resultado fora do intervalo numérico suportado', $operator),
                );
            }

            return $result;
        }

        function dsl_negate(mixed $value): int|float
        {
            if (!dsl_is_number($value)) {
                throw new DslRuntimeException(
                    sprintf('o operador "-" unário exige um número, mas recebeu %s', dsl_type($value)),
                );
            }

            return -$value;
        }

        /** "==": números pelo valor (100 == 100.0); demais valores, estritamente. */
        function dsl_equals(mixed $left, mixed $right): bool
        {
            if (dsl_is_number($left) && dsl_is_number($right)) {
                return $left == $right;
            }

            return $left === $right;
        }

        /** Relacionais: entre dois números ou entre dois textos. */
        function dsl_compare(string $operator, mixed $left, mixed $right): bool
        {
            if (dsl_is_number($left) && dsl_is_number($right)) {
                $order = $left <=> $right;
            } elseif (is_string($left) && is_string($right)) {
                $order = strcmp($left, $right);
            } else {
                throw new DslRuntimeException(sprintf(
                    'o operador "%s" exige dois números ou dois textos, mas recebeu %s e %s',
                    $operator,
                    dsl_type($left),
                    dsl_type($right),
                ));
            }

            return match ($operator) {
                '<' => $order < 0,
                '<=' => $order <= 0,
                '>' => $order > 0,
                '>=' => $order >= 0,
            };
        }

        /**
         * Conversão para o tipo declarado no "map". Perdas de informação (3.7 para
         * int) e valores não finitos (sem representação em JSON) são erros.
         */
        function dsl_convert(mixed $value, string $type, string $field): string|int|float|bool
        {
            if (is_float($value) && !is_finite($value)) {
                throw new DslRuntimeException(sprintf('campo "%s": o valor não é um número finito', $field));
            }
            $result = match ($type) {
                'string' => is_string($value) || dsl_is_number($value) || is_bool($value) ? dsl_text($value) : null,
                'int' => match (true) {
                    is_int($value) => $value,
                    is_float($value) && is_finite($value) && floor($value) === $value
                        && $value >= -9.2233720368547758E+18 && $value < 9.2233720368547758E+18 => (int) $value,
                    is_string($value) && preg_match('/^-?(0|[1-9][0-9]*)$/', $value) === 1
                        && filter_var($value, FILTER_VALIDATE_INT) !== false => (int) $value,
                    default => null,
                },
                'float' => match (true) {
                    dsl_is_number($value) => (float) $value,
                    is_string($value) && is_numeric($value) => (float) $value,
                    default => null,
                },
                'bool' => match (true) {
                    is_bool($value) => $value,
                    $value === 1, $value === '1', $value === 'true' => true,
                    $value === 0, $value === '0', $value === 'false' => false,
                    default => null,
                },
            };
            if ($result === null) {
                throw new DslRuntimeException(sprintf(
                    'campo "%s": não é possível converter um valor %s para %s',
                    $field,
                    dsl_type($value),
                    $type,
                ));
            }
            if (is_float($result) && !is_finite($result)) {
                // Ex.: o texto "1e400" é numérico, mas vira INF ao ser convertido.
                throw new DslRuntimeException(sprintf('campo "%s": o valor não é um número finito', $field));
            }

            return $result;
        }

        /**
         * Aceita texto ou número, sem quebras de linha (injeção de cabeçalho).
         *
         * @return array{string, string}
         */
        function dsl_header(string $name, mixed $value): array
        {
            if (!is_string($value) && !dsl_is_number($value)) {
                throw new DslRuntimeException(
                    sprintf('cabeçalho "%s": valor %s inválido, esperado texto ou número', $name, dsl_type($value)),
                );
            }
            $text = dsl_text($value);
            if (preg_match('/[\r\n\0]/', $text) === 1) {
                throw new DslRuntimeException(sprintf('cabeçalho "%s": o valor contém quebra de linha', $name));
            }

            return [$name, $text];
        }

        function dsl_json_encode(mixed $data): string
        {
            try {
                return json_encode(
                    $data,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
                );
            } catch (JsonException $exception) {
                throw new DslRuntimeException('não foi possível serializar o registro em JSON: ' . $exception->getMessage());
            }
        }

        /** URL para mensagens, com a query string ocultada (pode conter tokens). */
        function dsl_safe_url(string $url): string
        {
            $position = strpos($url, '?');

            return $position === false ? $url : substr($url, 0, $position) . '?[REDACTED]';
        }

        /**
         * Requisição cURL. Com body, envia JSON e "Content-Type: application/json" se
         * a API não declarou esse cabeçalho. Status fora de 2xx, falha de conexão e
         * JSON inválido geram DslRuntimeException, com dsl_safe_url() e a descrição
         * genérica do erro do cURL. TLS verificado, só HTTP/HTTPS, sem redirecionamento.
         * Inteiros acima de PHP_INT_MAX são decodificados como texto.
         *
         * @param list<array{string, string}> $headers pares [nome, valor]
         *
         * @return mixed objetos JSON viram stdClass; null se !$expectJson
         */
        function dsl_http_request(string $method, string $url, array $headers, mixed $body, bool $expectJson): mixed
        {
            $safeUrl = dsl_safe_url($url);
            $lines = [];
            $hasContentType = false;
            foreach ($headers as [$name, $value]) {
                $lines[] = $name . ': ' . $value;
                $hasContentType = $hasContentType || strcasecmp($name, 'Content-Type') === 0;
            }

            $options = [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT => DSL_CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT => DSL_REQUEST_TIMEOUT,
            ];
            if ($method === 'GET') {
                $options[CURLOPT_HTTPGET] = true;
            } else {
                $options[CURLOPT_CUSTOMREQUEST] = $method;
            }
            if ($body !== null) {
                $options[CURLOPT_POSTFIELDS] = dsl_json_encode($body);
                if (!$hasContentType) {
                    $lines[] = 'Content-Type: application/json';
                }
            }
            $options[CURLOPT_HTTPHEADER] = $lines;

            $handle = curl_init();
            if ($handle === false) {
                throw new DslRuntimeException(sprintf('não foi possível iniciar o cURL para %s %s', $method, $safeUrl));
            }
            try {
                curl_setopt_array($handle, $options);
                $response = curl_exec($handle);
                if (!is_string($response)) {
                    $errorCode = curl_errno($handle);
                    throw new DslRuntimeException(sprintf(
                        'falha de conexão em %s %s: %s (cURL %d)',
                        $method,
                        $safeUrl,
                        (string) curl_strerror($errorCode),
                        $errorCode,
                    ));
                }
                $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            } finally {
                // No PHP 8+, remover a última referência libera o handle.
                unset($handle);
            }

            if ($status < 200 || $status > 299) {
                throw new DslRuntimeException(sprintf('%s %s respondeu com status HTTP %d', $method, $safeUrl, $status));
            }
            if (!$expectJson) {
                return null;
            }

            try {
                return json_decode($response, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            } catch (JsonException $exception) {
                throw new DslRuntimeException(
                    sprintf('a resposta de %s %s não é JSON válido: %s', $method, $safeUrl, $exception->getMessage()),
                );
            }
        }

        /**
         * Objeto JSON = um registro; lista = um registro por item (cada item deve
         * ser objeto). Outro formato é incompatível.
         *
         * @return list<stdClass>
         */
        function dsl_records(mixed $response): array
        {
            if ($response instanceof stdClass) {
                return [$response];
            }
            if (!is_array($response)) {
                throw new DslRuntimeException(sprintf(
                    'formato de resposta incompatível: esperado objeto ou lista de objetos JSON, recebido %s',
                    dsl_type($response),
                ));
            }
            foreach ($response as $index => $item) {
                if (!$item instanceof stdClass) {
                    throw new DslRuntimeException(sprintf(
                        'formato de resposta incompatível: o item %d da lista é %s, esperado objeto JSON',
                        $index + 1,
                        dsl_type($item),
                    ));
                }
            }

            return $response;
        }

        function dsl_log(string $integration, string $message): void
        {
            fwrite(STDOUT, sprintf("[%s] %s\n", $integration, $message));
        }

        function dsl_error(string $integration, string $message): void
        {
            fwrite(STDERR, sprintf("[%s] ERRO: %s\n", $integration, $message));
        }

        /**
         * Consulta a origem, aplica condição e mapeamento a cada registro e envia os
         * elegíveis ao destino. Falha na configuração ou na origem interrompe a
         * integração; falha em um registro é reportada e os demais continuam.
         *
         * @param callable(): list<array{string, string}> $sourceHeaders
         * @param callable(): list<array{string, string}> $targetHeaders
         * @param (callable(stdClass): bool)|null         $condition     null: todos os registros
         * @param (callable(stdClass): stdClass)|null     $mapping       null: registro original
         *
         * @return bool true se não houve falha
         */
        function dsl_run_integration(
            string $name,
            string $sourceUrl,
            callable $sourceHeaders,
            string $targetMethod,
            string $targetUrl,
            callable $targetHeaders,
            ?callable $condition,
            ?callable $mapping,
        ): bool {
            dsl_log($name, sprintf('GET %s -> %s %s', dsl_safe_url($sourceUrl), $targetMethod, dsl_safe_url($targetUrl)));

            try {
                $sourceHeaderList = $sourceHeaders();
                $targetHeaderList = $targetHeaders();
                $records = dsl_records(dsl_http_request('GET', $sourceUrl, $sourceHeaderList, null, true));
            } catch (DslRuntimeException $exception) {
                dsl_error($name, $exception->getMessage());

                return false;
            }

            $sent = 0;
            $skipped = 0;
            $failed = 0;
            foreach ($records as $index => $record) {
                try {
                    if ($condition !== null && !$condition($record)) {
                        $skipped++;
                        continue;
                    }
                    $payload = $mapping === null ? $record : $mapping($record);
                    dsl_http_request($targetMethod, $targetUrl, $targetHeaderList, $payload, false);
                    $sent++;
                } catch (DslRuntimeException $exception) {
                    $failed++;
                    dsl_error($name, sprintf('registro %d: %s', $index + 1, $exception->getMessage()));
                }
            }

            dsl_log($name, sprintf(
                '%d registro(s) recebido(s): %d enviado(s), %d ignorado(s) pela condição, %d com falha.',
                count($records),
                $sent,
                $skipped,
                $failed,
            ));

            return $failed === 0;
        }

        /**
         * @param list<callable(): bool> $integrations na ordem do programa
         *
         * @return int 0 sem falhas, 1 se alguma integração falhou
         */
        function dsl_main(array $integrations): int
        {
            if (!extension_loaded('curl')) {
                fwrite(STDERR, "ERRO: este script exige a extensão curl do PHP.\n");

                return 1;
            }

            $failures = 0;
            foreach ($integrations as $integration) {
                if (!$integration()) {
                    $failures++;
                }
            }

            if ($failures > 0) {
                fwrite(STDERR, sprintf("Concluído com falhas: %d de %d integração(ões).\n", $failures, count($integrations)));

                return 1;
            }
            fwrite(STDOUT, sprintf("Concluído: %d integração(ões) sem falhas.\n", count($integrations)));

            return 0;
        }
        PHP;
}
