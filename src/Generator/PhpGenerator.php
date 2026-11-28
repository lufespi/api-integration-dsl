<?php

declare(strict_types=1);

namespace App\Generator;

use App\Ast\Api;
use App\Ast\Endpoint;
use App\Ast\EndpointRef;
use App\Ast\HttpMethod;
use App\Ast\Integration;
use App\Ast\Program;

/**
 * Gera um script PHP autônomo (cURL + JSON) a partir da AST.
 *
 * O script contém a RuntimeLibrary, uma função de cabeçalhos por API usada, as
 * funções de condição, mapeamento e execução de cada integração e a chamada a
 * dsl_main(), omitida com DSL_NO_AUTORUN. A saída é determinística.
 *
 * Parte das verificações repete o Analyzer de forma defensiva, para ASTs que não
 * passaram por ele; as demais são restrições próprias da geração.
 */
final class PhpGenerator
{
    /** Métodos aceitos na origem: a origem é consultada, nunca alterada. */
    private const SOURCE_METHODS = [HttpMethod::Get];

    /** Métodos aceitos no destino: todos enviam o registro como body JSON. */
    private const TARGET_METHODS = [HttpMethod::Post, HttpMethod::Put, HttpMethod::Patch];

    private readonly ExpressionCompiler $expressions;

    public function __construct()
    {
        $this->expressions = new ExpressionCompiler();
    }

    /** @throws GenerationException se a AST tiver referências inválidas ou construções não suportadas */
    public function generate(Program $program): string
    {
        $apis = $this->indexApis($program);

        // Cabeçalhos de todas as APIs são traduzidos (e assim validados);
        // só os das APIs usadas por alguma integração são emitidos.
        $headerFunctions = [];
        foreach ($apis as $entry) {
            $headerFunctions[$entry['number']] = $this->headersFunction($entry['number'], $entry['api']);
        }

        $usedApis = [];
        $integrationCode = [];
        $integrationNames = [];
        foreach (array_values($program->integrations) as $index => $integration) {
            if (isset($integrationNames[$integration->name])) {
                throw new GenerationException(
                    sprintf("integração '%s' declarada mais de uma vez", $integration->name),
                    $integration->line,
                    $integration->column,
                );
            }
            $integrationNames[$integration->name] = true;

            $number = $index + 1;
            [$source, $sourceEndpoint] = $this->resolve($integration->source, $apis, 'origem', $integration);
            [$target, $targetEndpoint] = $this->resolve($integration->target, $apis, 'destino', $integration);
            $this->checkMethod($integration->source, $sourceEndpoint, self::SOURCE_METHODS, 'origem');
            $this->checkMethod($integration->target, $targetEndpoint, self::TARGET_METHODS, 'destino');
            $usedApis[$source['number']] = true;
            $usedApis[$target['number']] = true;

            $integrationCode[] = $this->integrationCode(
                $number,
                $integration,
                $source['number'],
                $this->endpointUrl($source['api'], $sourceEndpoint),
                $target['number'],
                $targetEndpoint->method,
                $this->endpointUrl($target['api'], $targetEndpoint),
            );
        }
        ksort($usedApis);

        $sections = [
            "<?php\n\ndeclare(strict_types=1);\n",
            $this->fileHeader($program),
            "// ===== Biblioteca de runtime =====\n\n" . RuntimeLibrary::CODE . "\n",
            "// ===== Cabeçalhos das APIs =====\n",
        ];
        foreach (array_keys($usedApis) as $apiNumber) {
            $sections[] = $headerFunctions[$apiNumber];
        }
        array_push($sections, ...$integrationCode);
        $sections[] = $this->mainCode(count($integrationCode));

        return implode("\n", $sections);
    }

    /**
     * Indexa APIs e endpoints por nome, rejeitando nomes duplicados, que
     * tornariam ambígua a resolução de "Api.endpoint".
     *
     * @return array<string, array{number: int, api: Api, endpoints: array<string, Endpoint>}>
     */
    private function indexApis(Program $program): array
    {
        $apis = [];
        foreach (array_values($program->apis) as $index => $api) {
            if (isset($apis[$api->name])) {
                throw new GenerationException(
                    sprintf(
                        "API '%s' declarada mais de uma vez (primeira declaração na linha %d); a referência seria ambígua",
                        $api->name,
                        $apis[$api->name]['api']->line,
                    ),
                    $api->line,
                    $api->column,
                );
            }

            $endpoints = [];
            foreach ($api->endpoints as $endpoint) {
                if (isset($endpoints[$endpoint->name])) {
                    throw new GenerationException(
                        sprintf("endpoint '%s' declarado mais de uma vez na API '%s'", $endpoint->name, $api->name),
                        $endpoint->line,
                        $endpoint->column,
                    );
                }
                $endpoints[$endpoint->name] = $endpoint;
            }

            $apis[$api->name] = ['number' => $index + 1, 'api' => $api, 'endpoints' => $endpoints];
        }

        return $apis;
    }

    /**
     * @param array<string, array{number: int, api: Api, endpoints: array<string, Endpoint>}> $apis
     *
     * @return array{array{number: int, api: Api, endpoints: array<string, Endpoint>}, Endpoint}
     */
    private function resolve(EndpointRef $ref, array $apis, string $role, Integration $integration): array
    {
        $entry = $apis[$ref->api] ?? throw new GenerationException(
            sprintf("a integração '%s' usa a API '%s' como %s, mas ela não foi declarada", $integration->name, $ref->api, $role),
            $ref->line,
            $ref->column,
        );
        $endpoint = $entry['endpoints'][$ref->endpoint] ?? throw new GenerationException(
            sprintf(
                "a API '%s' não possui o endpoint '%s' (usado como %s da integração '%s')",
                $ref->api,
                $ref->endpoint,
                $role,
                $integration->name,
            ),
            $ref->line,
            $ref->column,
        );

        return [$entry, $endpoint];
    }

    /**
     * Rejeita métodos que esta versão não traduz na posição indicada (ex.: DELETE
     * como destino, POST como origem).
     *
     * @param list<HttpMethod> $allowed
     */
    private function checkMethod(EndpointRef $ref, Endpoint $endpoint, array $allowed, string $role): void
    {
        if (in_array($endpoint->method, $allowed, true)) {
            return;
        }

        throw new GenerationException(
            sprintf(
                "o endpoint '%s.%s' usa %s, que não é suportado como %s; use %s",
                $ref->api,
                $ref->endpoint,
                $endpoint->method->value,
                $role,
                implode(', ', array_map(static fn (HttpMethod $method): string => $method->value, $allowed)),
            ),
            $ref->line,
            $ref->column,
        );
    }

    /**
     * Monta a URL do endpoint (endereço-base + caminho), validando as duas
     * partes para que a concatenação produza uma URL correta.
     *
     * Endereço-base: http/https, host válido (nome, localhost, IPv4 ou
     * [IPv6]), porta opcional, caminho opcional; sem credenciais, query
     * string, fragmento, espaços ou caracteres de controle. Caminho do
     * endpoint: começa com "/", query string fixa permitida, sem fragmento,
     * espaços, caracteres de controle ou parâmetros "{...}".
     */
    private function endpointUrl(Api $api, Endpoint $endpoint): string
    {
        $problem = self::baseUrlProblem($api->baseUrl);
        if ($problem !== null) {
            throw new GenerationException(
                sprintf("o endereço-base da API '%s' deve ser uma URL http:// ou https:// válida: %s", $api->name, $problem),
                $api->line,
                $api->column,
            );
        }
        $path = $endpoint->path;
        if (!str_starts_with($path, '/') || preg_match('/[\s\x00-\x1F\x7F#]/', $path) === 1) {
            throw new GenerationException(
                sprintf(
                    "o caminho do endpoint '%s' deve começar com '/' e não conter espaços, caracteres de controle nem fragmento ('#')",
                    $endpoint->name,
                ),
                $endpoint->line,
                $endpoint->column,
            );
        }
        if (preg_match('/[{}]/', $endpoint->path) === 1) {
            throw new GenerationException(
                sprintf(
                    "o endpoint '%s' usa parâmetros de caminho ('%s'), ainda não suportados (pendência P8)",
                    $endpoint->name,
                    $endpoint->path,
                ),
                $endpoint->line,
                $endpoint->column,
            );
        }

        return rtrim($api->baseUrl, '/') . $path;
    }

    /** Motivo pelo qual o endereço-base é inválido, ou null se for válido. */
    private static function baseUrlProblem(string $url): ?string
    {
        if (preg_match('/[\s\x00-\x1F\x7F]/', $url) === 1) {
            return 'não pode conter espaços nem caracteres de controle';
        }
        if (str_contains($url, '?') || str_contains($url, '#')) {
            return "não pode conter query string ('?') nem fragmento ('#'); coloque a query no caminho do endpoint";
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || preg_match('~^[a-z][a-z0-9+.-]*://~i', $url) !== 1) {
            return 'esperada uma URL absoluta, como https://api.exemplo.com/v1';
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return sprintf("protocolo '%s' não suportado", $parts['scheme']);
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'credenciais embutidas na URL não são permitidas; use um header com env()';
        }
        if (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535)) {
            return 'porta fora do intervalo 1-65535';
        }
        if (!self::isValidHost($parts['host'])) {
            return sprintf("host '%s' inválido", $parts['host']);
        }

        // parse_url() é permissivo: a autoridade escrita deve ser exatamente
        // "host" ou "host:porta" (rejeita, por exemplo, "host:" e "host:080").
        $authority = (string) preg_replace('~^[a-z][a-z0-9+.-]*://([^/]*).*$~is', '$1', $url);
        $expected = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (strcasecmp($authority, $expected) !== 0) {
            return 'host ou porta malformados';
        }

        return null;
    }

    /** Aceita nome de host (inclusive localhost), IPv4 e IPv6 entre colchetes. */
    private static function isValidHost(string $host): bool
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }
        if (preg_match('/^[0-9.]+$/', $host) === 1) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        }

        return filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    /**
     * Função que calcula os cabeçalhos de uma API. Cada API tem a sua: os
     * cabeçalhos (e credenciais) de uma API nunca são enviados a outra.
     */
    private function headersFunction(int $number, Api $api): string
    {
        $entries = [];
        foreach ($api->headers as $header) {
            if (preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/", $header->name) !== 1) {
                throw new GenerationException(
                    sprintf("nome de cabeçalho inválido na API '%s': '%s'", $api->name, $header->name),
                    $header->line,
                    $header->column,
                );
            }
            $value = $this->expressions->compileWithoutRecord($header->value);
            $entries[] = sprintf(
                "        // %s\n        dsl_header(%s, %s),\n",
                self::comment($header->name . ' = ' . $this->expressions->describe($header->value)),
                var_export($header->name, true),
                $value,
            );
        }

        $body = $entries === [] ? "    return [];\n" : "    return [\n" . implode('', $entries) . "    ];\n";

        return sprintf(
            "/** @return list<array{string, string}> cabeçalhos da API %s, enviados somente a ela */\n"
            . "function dsl_api_headers_%d(): array\n{\n%s}\n",
            self::comment($api->name),
            $number,
            $body,
        );
    }

    /** Código de uma integração: condição, mapeamento e função de execução. */
    private function integrationCode(
        int $number,
        Integration $integration,
        int $sourceApi,
        string $sourceUrl,
        int $targetApi,
        HttpMethod $targetMethod,
        string $targetUrl,
    ): string {
        $title = self::comment(sprintf(
            '%s: %s.%s (GET) -> %s.%s (%s)',
            $integration->name,
            $integration->source->api,
            $integration->source->endpoint,
            $integration->target->api,
            $integration->target->endpoint,
            $targetMethod->value,
        ));
        $code = sprintf("// ===== Integração %d: %s =====\n\n", $number, $title);

        $condition = 'null';
        if ($integration->condition !== null) {
            $expression = $this->expressions->compileForRecord($integration->condition, $integration->name, $integration->alias);
            $code .= sprintf(
                "// when %s\nfunction dsl_condition_%d(stdClass \$record): bool\n{\n    return (bool) %s;\n}\n\n",
                self::comment($this->expressions->describe($integration->condition)),
                $number,
                $expression,
            );
            $condition = sprintf('dsl_condition_%d(...)', $number);
        }

        $mapping = 'null';
        if ($integration->mappings !== []) {
            $code .= $this->mappingFunction($number, $integration);
            $mapping = sprintf('dsl_map_%d(...)', $number);
        }

        $code .= sprintf(
            "function dsl_integration_%d(): bool\n{\n"
            . "    return dsl_run_integration(\n"
            . "        name: %s,\n"
            . "        sourceUrl: %s,\n"
            . "        sourceHeaders: dsl_api_headers_%d(...),\n"
            . "        targetMethod: %s,\n"
            . "        targetUrl: %s,\n"
            . "        targetHeaders: dsl_api_headers_%d(...),\n"
            . "        condition: %s,\n"
            . "        mapping: %s,\n"
            . "    );\n}\n",
            $number,
            var_export($integration->name, true),
            var_export($sourceUrl, true),
            $sourceApi,
            var_export($targetMethod->value, true),
            var_export($targetUrl, true),
            $targetApi,
            $condition,
            $mapping,
        );

        return $code;
    }

    private function mappingFunction(int $number, Integration $integration): string
    {
        $fields = [];
        $entries = [];
        foreach ($integration->mappings as $mapping) {
            if ($mapping->field === '' || isset($fields[$mapping->field])) {
                throw new GenerationException(
                    sprintf("campo '%s' vazio ou repetido no bloco map da integração '%s'", $mapping->field, $integration->name),
                    $mapping->line,
                    $mapping->column,
                );
            }
            $fields[$mapping->field] = true;

            $value = $this->expressions->compileForRecord($mapping->value, $integration->name, $integration->alias);
            $entries[] = sprintf(
                "        // %s\n        %s => dsl_convert(%s, %s, %s),\n",
                self::comment(sprintf('%s: %s = %s', $mapping->field, $mapping->dataType->value, $this->expressions->describe($mapping->value))),
                var_export($mapping->field, true),
                $value,
                var_export($mapping->dataType->value, true),
                var_export($mapping->field, true),
            );
        }

        return sprintf(
            "function dsl_map_%d(stdClass \$record): stdClass\n{\n    return (object) [\n%s    ];\n}\n\n",
            $number,
            implode('', $entries),
        );
    }

    private function fileHeader(Program $program): string
    {
        $names = array_map(static fn (Integration $integration): string => $integration->name, $program->integrations);

        return "/*\n"
            . " * Gerado pelo compilador API Integration DSL: altere o .dsl e compile novamente.\n"
            . " * Requisitos: PHP 8.3+ com curl e json; variáveis usadas em env() definidas no ambiente.\n"
            . " * Código de saída: 0 sem falhas, 1 se alguma integração falhar.\n"
            . ' * Integrações: ' . ($names === [] ? '(nenhuma)' : self::comment(implode(', ', $names))) . "\n"
            . " */\n";
    }

    private function mainCode(int $count): string
    {
        $calls = '';
        for ($number = 1; $number <= $count; $number++) {
            $calls .= sprintf("        dsl_integration_%d(...),\n", $number);
        }
        $list = $calls === '' ? '[]' : "[\n" . $calls . '    ]';

        return "// ===== Execução =====\n"
            . "// Com a constante DSL_NO_AUTORUN definida, apenas carrega as funções.\n"
            . "if (!defined('DSL_NO_AUTORUN')) {\n"
            . '    exit(dsl_main(' . $list . "));\n"
            . "}\n";
    }

    /**
     * Neutraliza texto vindo da AST antes de colocá-lo em um comentário:
     * remove quebras de linha e sequências que encerrariam o comentário
     * ou o modo PHP ("* /" e "? >").
     */
    private static function comment(string $text): string
    {
        return str_replace(['?>', '*/'], ['? >', '* /'], (string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $text));
    }
}
