<?php

declare(strict_types=1);

namespace App\Generator;

/**
 * Funções auxiliares de runtime copiadas para todo script gerado.
 *
 * É código PHP fixo (não depende do programa .dsl): requisições HTTP com
 * cURL, leitura de campos do registro, operadores da DSL, conversão de tipos
 * e execução de uma integração. O PhpGenerator o inclui uma única vez por
 * script, mesmo com várias integrações, e gera apenas o código específico de
 * cada programa (cabeçalhos, condições, mapeamentos e URLs), que chama estas
 * funções.
 *
 * Todas as funções usam o prefixo "dsl_" para não colidir com nomes do PHP.
 * Erros operacionais (rede, HTTP, JSON, dados, configuração) são lançados
 * como DslRuntimeException e tratados em dsl_run_integration(), que os
 * reporta sem exibir valores de cabeçalhos ou variáveis de ambiente.
 *
 * A semântica dos operadores e conversões está em docs/generator.md.
 *
 * @internal Usada apenas pelo PhpGenerator.
 */
final class RuntimeLibrary
{
    /** Código PHP da biblioteca, sem a tag de abertura "<?php". */
    public const CODE = <<<'PHP'
        /** Tempo máximo, em segundos, para estabelecer a conexão HTTP. */
        const DSL_CONNECT_TIMEOUT = 10;

        /** Tempo máximo, em segundos, para concluir cada requisição HTTP. */
        const DSL_REQUEST_TIMEOUT = 30;

        /**
         * Falha operacional: rede, status HTTP, JSON, dados do registro ou
         * configuração (ex.: variável de ambiente ausente).
         */
        final class DslRuntimeException extends RuntimeException
        {
        }

        /**
         * Função env("NOME") da DSL: lê uma variável de ambiente obrigatória.
         * O valor nunca é exibido em mensagens de erro.
         */
        function dsl_env(string $name): string
        {
            $value = getenv($name);
            if ($value === false || $value === '') {
                throw new DslRuntimeException(sprintf('variável de ambiente obrigatória "%s" não definida', $name));
            }

            return $value;
        }

        /**
         * Lê um campo (possivelmente aninhado) do registro. Campo ausente, ou
         * caminho que atravessa um valor que não é objeto JSON, resulta em null.
         *
         * @param list<string> $path Segmentos após o alias (ex.: ['cliente', 'email']).
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

            return $value;
        }

        /** Nome do tipo de um valor, para mensagens de erro (sem expor o valor). */
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

        /** Converte um valor simples em texto (bool vira "true"/"false"). */
        function dsl_text(mixed $value): string
        {
            return match (true) {
                is_string($value) => $value,
                is_int($value), is_float($value) => (string) $value,
                is_bool($value) => $value ? 'true' : 'false',
                default => throw new DslRuntimeException(
                    sprintf('não é possível usar um valor %s como texto', dsl_type($value)),
                ),
            };
        }

        /**
         * Operador "+": concatenação quando ao menos um operando é texto;
         * caso contrário, soma numérica. Decidido em tempo de execução, pois o
         * tipo dos campos do registro só é conhecido com os dados reais.
         */
        function dsl_add(mixed $left, mixed $right): int|float|string
        {
            if (is_string($left) || is_string($right)) {
                return dsl_text($left) . dsl_text($right);
            }

            return dsl_arith('+', $left, $right);
        }

        /** Operadores aritméticos "+", "-", "*", "/" e "%" entre números. */
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

            return match ($operator) {
                '+' => $left + $right,
                '-' => $left - $right,
                '*' => $left * $right,
                '/' => $left / $right,
                '%' => is_int($left) && is_int($right) ? $left % $right : fmod($left, $right),
            };
        }

        /** Menos unário. */
        function dsl_negate(mixed $value): int|float
        {
            if (!dsl_is_number($value)) {
                throw new DslRuntimeException(
                    sprintf('o operador "-" unário exige um número, mas recebeu %s', dsl_type($value)),
                );
            }

            return -$value;
        }

        /**
         * Operador "==": números são comparados pelo valor (100 == 100.0);
         * os demais valores, de forma estrita (tipo e valor).
         */
        function dsl_equals(mixed $left, mixed $right): bool
        {
            if (dsl_is_number($left) && dsl_is_number($right)) {
                return $left == $right;
            }

            return $left === $right;
        }

        /** Operadores "<", "<=", ">" e ">=": entre dois números ou entre dois textos. */
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
         * Converte o valor de um campo do bloco "map" para o tipo declarado.
         * Conversões que perderiam informação (ex.: 3.7 para int) são erros.
         */
        function dsl_convert(mixed $value, string $type, string $field): string|int|float|bool
        {
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

            return $result;
        }

        /**
         * Monta um cabeçalho HTTP a partir do valor calculado. Rejeita valores
         * que não sejam texto ou número e quebras de linha (injeção de cabeçalho).
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

        /**
         * Executa uma requisição HTTP com cURL.
         *
         * Sem body para GET; com body, serializa em JSON e envia
         * "Content-Type: application/json" se a API não declarou esse cabeçalho.
         * Status fora de 2xx, falhas de conexão e JSON inválido (quando
         * $expectJson) geram DslRuntimeException. A verificação TLS do cURL
         * permanece ativa (padrão) e só HTTP/HTTPS são aceitos.
         *
         * @param list<array{string, string}> $headers Pares [nome, valor].
         *
         * @return mixed Resposta decodificada (objetos JSON viram stdClass), ou null se !$expectJson.
         */
        function dsl_http_request(string $method, string $url, array $headers, mixed $body, bool $expectJson): mixed
        {
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
                throw new DslRuntimeException(sprintf('não foi possível iniciar o cURL para %s %s', $method, $url));
            }
            try {
                curl_setopt_array($handle, $options);
                $response = curl_exec($handle);
                if (!is_string($response)) {
                    throw new DslRuntimeException(
                        sprintf('falha de conexão em %s %s: %s', $method, $url, curl_error($handle)),
                    );
                }
                $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            } finally {
                // Libera o handle cURL (no PHP 8+ basta remover a última referência).
                unset($handle);
            }

            if ($status < 200 || $status > 299) {
                throw new DslRuntimeException(sprintf('%s %s respondeu com status HTTP %d', $method, $url, $status));
            }
            if (!$expectJson) {
                return null;
            }

            try {
                return json_decode($response, false, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new DslRuntimeException(
                    sprintf('a resposta de %s %s não é JSON válido: %s', $method, $url, $exception->getMessage()),
                );
            }
        }

        /**
         * Obtém os registros da resposta da origem: um objeto JSON é um
         * registro; uma lista JSON é processada item a item (cada item deve
         * ser um objeto). Qualquer outro formato é incompatível.
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
         * Executa uma integração: consulta a origem (GET), aplica a condição
         * e o mapeamento a cada registro e envia os elegíveis ao destino.
         *
         * Falha na configuração ou na origem interrompe a integração; falha
         * em um registro é reportada e os demais continuam sendo processados.
         *
         * @param callable(): list<array{string, string}> $sourceHeaders Cabeçalhos da API de origem.
         * @param callable(): list<array{string, string}> $targetHeaders Cabeçalhos da API de destino.
         * @param (callable(stdClass): bool)|null         $condition     Cláusula "when" (null: todos).
         * @param (callable(stdClass): stdClass)|null     $mapping       Bloco "map" (null: registro original).
         *
         * @return bool true se não houve nenhuma falha.
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
            dsl_log($name, sprintf('GET %s -> %s %s', $sourceUrl, $targetMethod, $targetUrl));

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
         * Executa as integrações na ordem do programa .dsl.
         *
         * @param list<callable(): bool> $integrations
         *
         * @return int Código de saída: 0 sem falhas, 1 se alguma integração falhou.
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
