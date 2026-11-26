<?php

declare(strict_types=1);

namespace App\Generator;

use App\Ast\Api;
use App\Ast\Endpoint;
use App\Ast\EndpointRef;
use App\Ast\FieldMapping;
use App\Ast\HttpMethod;
use App\Ast\Integration;
use App\Ast\Program;

/**
 * Gerador de código: traduz a AST (App\Ast\Program) em um script PHP
 * autônomo que executa as integrações com cURL.
 *
 * O script gerado contém, nesta ordem:
 *  1. a biblioteca de runtime (RuntimeLibrary), uma única vez;
 *  2. uma função de cabeçalhos por API usada (dsl_api_headers_N);
 *  3. por integração: condição (dsl_condition_N), mapeamento (dsl_map_N) e
 *     a função que a executa (dsl_integration_N);
 *  4. a chamada a dsl_main(), que roda as integrações e define o código de
 *     saída (omitida se a constante DSL_NO_AUTORUN estiver definida).
 *
 * O Gerador não grava arquivos, não executa o código e não faz requisições.
 * Antes de gerar, faz apenas as verificações semânticas mínimas para uma
 * geração segura (ver docs/generator.md); problemas viram GenerationException.
 * A saída é determinística: a mesma AST produz sempre o mesmo texto.
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

    /**
     * Gera o código-fonte PHP do programa.
     *
     * @param Program $program AST produzida pelo Parser (ou montada à mão).
     *
     * @return string Código PHP completo, iniciado por "<?php".
     *
     * @throws GenerationException Se a AST tiver referências inválidas ou
     *                             construções não suportadas.
     */
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
            "// ===== Biblioteca de runtime (igual em todo script gerado) =====\n\n" . RuntimeLibrary::CODE . "\n",
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
     * @param Program $program Programa.
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
     * Resolve uma referência "Api.endpoint".
     *
     * @param EndpointRef                                                                    $ref         Referência.
     * @param array<string, array{number: int, api: Api, endpoints: array<string, Endpoint>}> $apis        APIs indexadas.
     * @param string                                                                         $role        "origem" ou "destino".
     * @param Integration                                                                    $integration Integração.
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
     * Rejeita métodos HTTP que esta versão não traduz corretamente na posição
     * indicada (ex.: DELETE como destino, POST como origem).
     *
     * @param EndpointRef      $ref      Referência (posição do erro).
     * @param Endpoint         $endpoint Endpoint resolvido.
     * @param list<HttpMethod> $allowed  Métodos aceitos.
     * @param string           $role     "origem" ou "destino".
     *
     * @return void
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
     * Monta a URL do endpoint (endereço-base + caminho), validando-a.
     *
     * @param Api      $api      API.
     * @param Endpoint $endpoint Endpoint.
     *
     * @return string
     */
    private function endpointUrl(Api $api, Endpoint $endpoint): string
    {
        if (preg_match('~^https?://[^/?#\s]+~i', $api->baseUrl) !== 1 || preg_match('/[\s\x00-\x1F\x7F]/', $api->baseUrl) === 1) {
            throw new GenerationException(
                sprintf("o endereço-base da API '%s' deve ser uma URL http:// ou https:// sem espaços", $api->name),
                $api->line,
                $api->column,
            );
        }
        if (!str_starts_with($endpoint->path, '/') || preg_match('/[\s\x00-\x1F\x7F]/', $endpoint->path) === 1) {
            throw new GenerationException(
                sprintf("o caminho do endpoint '%s' deve começar com '/' e não conter espaços", $endpoint->name),
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

        return rtrim($api->baseUrl, '/') . $endpoint->path;
    }

    /**
     * Função que calcula os cabeçalhos de uma API. Cada API tem a sua: os
     * cabeçalhos (e credenciais) de uma API nunca são enviados a outra.
     *
     * @param int $number Número da API no programa (a partir de 1).
     * @param Api $api    API.
     *
     * @return string
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
            "/**\n * Cabeçalhos da API %s (enviados somente a ela).\n *\n * @return list<array{string, string}>\n */\n"
            . "function dsl_api_headers_%d(): array\n{\n%s}\n",
            self::comment($api->name),
            $number,
            $body,
        );
    }

    /**
     * Código de uma integração: condição, mapeamento e função de execução.
     *
     * @param int        $number       Número da integração (a partir de 1).
     * @param Integration $integration Integração.
     * @param int        $sourceApi    Número da API de origem.
     * @param string     $sourceUrl    URL da origem.
     * @param int        $targetApi    Número da API de destino.
     * @param HttpMethod $targetMethod Método do destino.
     * @param string     $targetUrl    URL do destino.
     *
     * @return string
     */
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
                "/**\n * Condição \"when\": %s\n */\nfunction dsl_condition_%d(stdClass \$record): bool\n{\n    return (bool) %s;\n}\n\n",
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

        $mappingNote = $integration->mappings === []
            ? ' * Sem bloco "map": o registro de origem é enviado sem alterações.' . "\n"
            : '';

        $code .= sprintf(
            "/**\n * Executa a integração %s.\n%s */\nfunction dsl_integration_%d(): bool\n{\n"
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
            self::comment($integration->name),
            $mappingNote,
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

    /**
     * Função do bloco "map": monta o registro de destino campo a campo,
     * convertendo cada valor para o tipo declarado.
     *
     * @param int         $number      Número da integração.
     * @param Integration $integration Integração.
     *
     * @return string
     */
    private function mappingFunction(int $number, Integration $integration): string
    {
        $fields = [];
        $entries = [];
        foreach ($integration->mappings as $mapping) {
            /** @var FieldMapping $mapping */
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
            "/**\n * Mapeamento \"map\": monta o registro enviado ao destino.\n */\n"
            . "function dsl_map_%d(stdClass \$record): stdClass\n{\n    return (object) [\n%s    ];\n}\n\n",
            $number,
            implode('', $entries),
        );
    }

    /**
     * Comentário inicial do script.
     *
     * @param Program $program Programa.
     *
     * @return string
     */
    private function fileHeader(Program $program): string
    {
        $names = array_map(static fn (Integration $integration): string => $integration->name, $program->integrations);

        return "/*\n"
            . " * Script gerado pelo compilador API Integration DSL (App\\Generator\\PhpGenerator).\n"
            . " * Não edite: altere o programa .dsl e compile novamente.\n"
            . " *\n"
            . " * Requisitos: PHP 8.3+ com as extensões curl e json (sem Composer).\n"
            . " * Execução:   php <este-arquivo>.php\n"
            . " * Credenciais usadas em env(\"...\") devem estar definidas no ambiente.\n"
            . " * Código de saída: 0 sem falhas; 1 se alguma integração falhar.\n"
            . " *\n"
            . ' * Integrações: ' . ($names === [] ? '(nenhuma)' : self::comment(implode(', ', $names))) . "\n"
            . " */\n";
    }

    /**
     * Bloco final que executa as integrações.
     *
     * @param int $count Quantidade de integrações.
     *
     * @return string
     */
    private function mainCode(int $count): string
    {
        $calls = '';
        for ($number = 1; $number <= $count; $number++) {
            $calls .= sprintf("        dsl_integration_%d(...),\n", $number);
        }
        $list = $calls === '' ? '[]' : "[\n" . $calls . '    ]';

        return "// ===== Execução =====\n"
            . "// Defina a constante DSL_NO_AUTORUN antes de incluir este arquivo para\n"
            . "// carregar as funções sem executar as integrações.\n"
            . "if (!defined('DSL_NO_AUTORUN')) {\n"
            . '    exit(dsl_main(' . $list . "));\n"
            . "}\n";
    }

    /**
     * Neutraliza texto vindo da AST antes de colocá-lo em um comentário:
     * remove quebras de linha e sequências que encerrariam o comentário
     * ou o modo PHP ("* /" e "? >").
     *
     * @param string $text Texto livre.
     *
     * @return string
     */
    private static function comment(string $text): string
    {
        return str_replace(['?>', '*/'], ['? >', '* /'], (string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $text));
    }
}
