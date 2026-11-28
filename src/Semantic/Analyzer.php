<?php

declare(strict_types=1);

namespace App\Semantic;

use App\Ast\Api;
use App\Ast\Endpoint;
use App\Ast\EndpointRef;
use App\Ast\Expression\BinaryExpression;
use App\Ast\Expression\Expression;
use App\Ast\Expression\FieldReference;
use App\Ast\Expression\FunctionCall;
use App\Ast\Expression\Literal;
use App\Ast\Expression\LiteralType;
use App\Ast\Expression\UnaryExpression;
use App\Ast\FieldMapping;
use App\Ast\Integration;
use App\Ast\Program;
use App\Exceptions\SemanticException;
use InvalidArgumentException;

/**
 * Analisador semântico: verifica sobre a AST as regras que a GLC não expressa
 * (regras S1–S13 de docs/grammar.md). Percorre o programa inteiro e lança todos
 * os erros juntos em uma SemanticException, ordenados por posição no fonte.
 *
 * @author pedro.augusto
 * @since 2026-11-28
 */
final class Analyzer
{
    /** Funções embutidas: nome => número de argumentos. Deve coincidir com ExpressionCompiler::FUNCTIONS. */
    public const DEFAULT_FUNCTIONS = [
        'env' => 1,
    ];

    /** Mesmo padrão aceito pelo Generator. */
    private const ENV_NAME_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /** @var array<string, int> nome => aridade */
    private readonly array $functions;

    /** @var list<SemanticError> */
    private array $errors = [];

    /**
     * @param array<string, int> $functions nome => número de argumentos
     *
     * @throws InvalidArgumentException se a tabela for inválida ou env() não tiver exatamente 1 argumento
     */
    public function __construct(array $functions = self::DEFAULT_FUNCTIONS)
    {
        foreach ($functions as $name => $arity) {
            if (!is_string($name) || $name === '' || !is_int($arity) || $arity < 0) {
                throw new InvalidArgumentException(
                    'A tabela de funções deve mapear o nome (texto não vazio) para o número de argumentos (inteiro >= 0).',
                );
            }
        }

        if (isset($functions['env']) && $functions['env'] !== 1) {
            throw new InvalidArgumentException('A função env() recebe exatamente 1 argumento.');
        }

        $this->functions = $functions;
    }

    /**
     * Devolve o mesmo Program quando não há erros.
     *
     * @throws SemanticException
     */
    public function analyze(Program $program): Program
    {
        $this->errors = [];

        /** @var array<string, Api> $declaredApis Primeira declaração de cada API. */
        $declaredApis = [];
        /** @var array<string, array<string, Endpoint>> $catalog API => endpoints (primeira declaração de cada). */
        $catalog = [];

        foreach ($program->apis as $api) {
            $endpoints = $this->checkApi($api);

            if (isset($declaredApis[$api->name])) {
                $first = $declaredApis[$api->name];
                $this->report(
                    sprintf("API '%s' já declarada na linha %d, coluna %d", $api->name, $first->line, $first->column),
                    $api->line,
                    $api->column,
                );
            } else {
                $declaredApis[$api->name] = $api;
                $catalog[$api->name] = $endpoints;
            }
        }

        /** @var array<string, Integration> $declaredIntegrations */
        $declaredIntegrations = [];

        foreach ($program->integrations as $integration) {
            if (isset($declaredIntegrations[$integration->name])) {
                $first = $declaredIntegrations[$integration->name];
                $this->report(
                    sprintf(
                        "integração '%s' já declarada na linha %d, coluna %d",
                        $integration->name,
                        $first->line,
                        $first->column,
                    ),
                    $integration->line,
                    $integration->column,
                );
            } else {
                $declaredIntegrations[$integration->name] = $integration;
            }

            $this->checkIntegration($integration, $catalog);
        }

        if ($this->errors === []) {
            return $program;
        }

        $errors = $this->errors;
        $this->errors = [];
        usort(
            $errors,
            static fn (SemanticError $a, SemanticError $b): int => [$a->line, $a->column] <=> [$b->line, $b->column],
        );

        throw new SemanticException($errors);
    }

    /** @return array<string, Endpoint> primeira declaração de cada nome */
    private function checkApi(Api $api): array
    {
        $endpoints = [];
        foreach ($api->endpoints as $endpoint) {
            if (isset($endpoints[$endpoint->name])) {
                $first = $endpoints[$endpoint->name];
                $this->report(
                    sprintf(
                        "endpoint '%s' já declarado na API '%s' (linha %d, coluna %d)",
                        $endpoint->name,
                        $api->name,
                        $first->line,
                        $first->column,
                    ),
                    $endpoint->line,
                    $endpoint->column,
                );
            } else {
                $endpoints[$endpoint->name] = $endpoint;
            }
        }

        $headers = [];
        foreach ($api->headers as $header) {
            $key = mb_strtolower($header->name, 'UTF-8');
            if (isset($headers[$key])) {
                $first = $headers[$key];
                $this->report(
                    sprintf(
                        "cabeçalho '%s' repetido na API '%s': já declarado como '%s' (linha %d, coluna %d); "
                        . 'nomes de cabeçalho HTTP não diferenciam maiúsculas de minúsculas',
                        $header->name,
                        $api->name,
                        $first->name,
                        $first->line,
                        $first->column,
                    ),
                    $header->line,
                    $header->column,
                );
            } else {
                $headers[$key] = $header;
            }

            $this->checkExpression(
                $header->value,
                function (FieldReference $reference) use ($api, $header): void {
                    $this->report(
                        sprintf(
                            "o cabeçalho '%s' da API '%s' referencia '%s', mas cabeçalhos não têm acesso a registros "
                            . "da origem; para ler um segredo use env(\"NOME\")",
                            $header->name,
                            $api->name,
                            implode('.', $reference->path),
                        ),
                        $reference->line,
                        $reference->column,
                    );
                },
            );
        }

        return $endpoints;
    }

    /** @param array<string, array<string, Endpoint>> $catalog API => endpoints */
    private function checkIntegration(Integration $integration, array $catalog): void
    {
        $this->checkEndpointReference($integration->source, 'from', $integration, $catalog);
        $this->checkEndpointReference($integration->target, 'to', $integration, $catalog);

        // Sem alias, todas as referências a campo têm a mesma causa; reporta só a primeira.
        $missingAliasReported = false;

        $onReference = function (FieldReference $reference) use ($integration, &$missingAliasReported): void {
            $path = implode('.', $reference->path);

            if ($integration->alias === null) {
                if (!$missingAliasReported) {
                    $missingAliasReported = true;
                    $this->report(
                        sprintf(
                            "a integração '%s' usa o campo '%s' do registro de origem, mas a cláusula 'from' não "
                            . "declara alias; escreva 'from %s.%s as nome'",
                            $integration->name,
                            $path,
                            $integration->source->api,
                            $integration->source->endpoint,
                        ),
                        $reference->line,
                        $reference->column,
                    );
                }

                return;
            }

            if ($reference->path[0] !== $integration->alias) {
                $this->report(
                    sprintf(
                        "a referência '%s' deve começar pelo alias '%s' da integração '%s'",
                        $path,
                        $integration->alias,
                        $integration->name,
                    ),
                    $reference->line,
                    $reference->column,
                );
            }
        };

        if ($integration->condition !== null) {
            $this->checkExpression($integration->condition, $onReference);
        }

        /** @var array<string, FieldMapping> $fields */
        $fields = [];
        foreach ($integration->mappings as $mapping) {
            if (isset($fields[$mapping->field])) {
                $first = $fields[$mapping->field];
                $this->report(
                    sprintf(
                        "campo '%s' repetido no bloco 'map' da integração '%s' (primeira ocorrência na linha %d, coluna %d)",
                        $mapping->field,
                        $integration->name,
                        $first->line,
                        $first->column,
                    ),
                    $mapping->line,
                    $mapping->column,
                );
            } else {
                $fields[$mapping->field] = $mapping;
            }

            $this->checkExpression($mapping->value, $onReference);
        }
    }

    /** @param array<string, array<string, Endpoint>> $catalog API => endpoints */
    private function checkEndpointReference(
        EndpointRef $reference,
        string $clause,
        Integration $integration,
        array $catalog,
    ): void {
        if (!isset($catalog[$reference->api])) {
            $this->report(
                sprintf(
                    "API '%s' não declarada na cláusula '%s' da integração '%s'%s",
                    $reference->api,
                    $clause,
                    $integration->name,
                    $this->available('APIs declaradas', array_keys($catalog)),
                ),
                $reference->line,
                $reference->column,
            );

            return;
        }

        if (!isset($catalog[$reference->api][$reference->endpoint])) {
            $this->report(
                sprintf(
                    "a API '%s' não declara o endpoint '%s' (cláusula '%s' da integração '%s')%s",
                    $reference->api,
                    $reference->endpoint,
                    $clause,
                    $integration->name,
                    $this->available(
                        sprintf("endpoints de '%s'", $reference->api),
                        array_keys($catalog[$reference->api]),
                    ),
                ),
                $reference->line,
                $reference->column,
            );
        }
    }

    /**
     * Valida as chamadas de função e entrega cada referência a campo ao
     * verificador do contexto (cabeçalho, "when" ou "map").
     *
     * @param callable(FieldReference): void $onReference
     */
    private function checkExpression(Expression $expression, callable $onReference): void
    {
        if ($expression instanceof FieldReference) {
            $onReference($expression);

            return;
        }

        if ($expression instanceof FunctionCall) {
            $this->checkCall($expression);
            foreach ($expression->arguments as $argument) {
                $this->checkExpression($argument, $onReference);
            }

            return;
        }

        if ($expression instanceof BinaryExpression) {
            $this->checkExpression($expression->left, $onReference);
            $this->checkExpression($expression->right, $onReference);

            return;
        }

        if ($expression instanceof UnaryExpression) {
            $this->checkExpression($expression->operand, $onReference);
        }

        // Literal: nada a verificar.
    }

    private function checkCall(FunctionCall $call): void
    {
        if (!isset($this->functions[$call->name])) {
            $names = array_keys($this->functions);
            sort($names);

            $this->report(
                sprintf("função desconhecida '%s'%s", $call->name, $this->available('funções disponíveis', $names)),
                $call->line,
                $call->column,
            );

            return;
        }

        $expected = $this->functions[$call->name];
        $received = count($call->arguments);

        if ($received !== $expected) {
            $this->report(
                sprintf(
                    "a função '%s' espera %s, mas recebeu %s",
                    $call->name,
                    self::arguments($expected),
                    self::arguments($received),
                ),
                $call->line,
                $call->column,
            );

            return;
        }

        if ($call->name === 'env') {
            $this->checkEnvArgument($call);
        }
    }

    /**
     * O argumento de env() deve ser um texto literal com nome válido, para que dados
     * do registro nunca escolham qual variável é lida.
     */
    private function checkEnvArgument(FunctionCall $call): void
    {
        $argument = array_values($call->arguments)[0];

        if (!$argument instanceof Literal || $argument->literalType !== LiteralType::String || !is_string($argument->value)) {
            $this->report(
                "o argumento de 'env' deve ser um texto literal com o nome da variável, ex.: env(\"API_TOKEN\")",
                $call->line,
                $call->column,
            );

            return;
        }

        if (preg_match(self::ENV_NAME_PATTERN, $argument->value) !== 1) {
            $this->report(
                sprintf(
                    'nome de variável de ambiente inválido em env(): "%s"; use letras, dígitos e _, sem começar por dígito',
                    addcslashes($argument->value, "\0..\37\"\\"),
                ),
                $argument->line,
                $argument->column,
            );
        }
    }

    /**
     * Sufixo "; rótulo: a, b, c", ou texto vazio se não houver nomes.
     *
     * @param list<string> $names
     */
    private function available(string $label, array $names): string
    {
        if ($names === []) {
            return '';
        }

        return sprintf('; %s: %s', $label, implode(', ', $names));
    }

    /** "1 argumento", "2 argumentos", "0 argumentos". */
    private static function arguments(int $count): string
    {
        return sprintf('%d %s', $count, $count === 1 ? 'argumento' : 'argumentos');
    }

    private function report(string $message, int $line, int $column): void
    {
        $this->errors[] = new SemanticError($message, $line, $column);
    }
}
