<?php

declare(strict_types=1);

namespace Tests\Unit\Generator\Support;

use App\Ast\Api;
use App\Ast\DataType;
use App\Ast\Endpoint;
use App\Ast\EndpointRef;
use App\Ast\Expression\BinaryExpression;
use App\Ast\Expression\BinaryOperator;
use App\Ast\Expression\Expression;
use App\Ast\Expression\FieldReference;
use App\Ast\Expression\FunctionCall;
use App\Ast\Expression\Literal;
use App\Ast\Expression\LiteralType;
use App\Ast\Expression\UnaryExpression;
use App\Ast\Expression\UnaryOperator;
use App\Ast\FieldMapping;
use App\Ast\Header;
use App\Ast\HttpMethod;
use App\Ast\Integration;
use App\Ast\Program;

/**
 * Atalhos para montar ASTs à mão nos testes do Gerador, sem Lexer nem Parser.
 * Posições são sempre linha 1, coluna 1, exceto quando o teste constrói o nó.
 *
 * @author lufespi
 * @since 2026-11-26
 */
final class Ast
{
    /**
     * @param list<Api>         $apis
     * @param list<Integration> $integrations
     */
    public static function program(array $apis, array $integrations): Program
    {
        return new Program($apis, $integrations);
    }

    /**
     * @param list<Endpoint> $endpoints
     * @param list<Header>   $headers
     */
    public static function api(string $name, string $baseUrl, array $endpoints, array $headers = []): Api
    {
        return new Api($name, $baseUrl, $headers, $endpoints, 1, 1);
    }

    public static function endpoint(string $name, HttpMethod $method, string $path): Endpoint
    {
        return new Endpoint($name, $method, $path, 1, 1);
    }

    public static function header(string $name, Expression $value): Header
    {
        return new Header($name, $value, 1, 1);
    }

    /** @param list<FieldMapping> $mappings */
    public static function integration(
        string $name,
        string $source,
        string $target,
        ?string $alias = null,
        ?Expression $condition = null,
        array $mappings = [],
    ): Integration {
        return new Integration($name, self::ref($source), $alias, self::ref($target), $condition, $mappings, 1, 1);
    }

    public static function ref(string $reference): EndpointRef
    {
        [$api, $endpoint] = explode('.', $reference, 2);

        return new EndpointRef($api, $endpoint, 1, 1);
    }

    public static function map(string $field, DataType $type, Expression $value): FieldMapping
    {
        return new FieldMapping($field, $type, $value, 1, 1);
    }

    public static function str(string $value): Literal
    {
        return new Literal(LiteralType::String, $value, 1, 1);
    }

    public static function int(int $value): Literal
    {
        return new Literal(LiteralType::Int, $value, 1, 1);
    }

    public static function float(float $value): Literal
    {
        return new Literal(LiteralType::Float, $value, 1, 1);
    }

    public static function bool(bool $value): Literal
    {
        return new Literal(LiteralType::Bool, $value, 1, 1);
    }

    public static function null(): Literal
    {
        return new Literal(LiteralType::Null, null, 1, 1);
    }

    public static function field(string $path): FieldReference
    {
        return new FieldReference(explode('.', $path), 1, 1);
    }

    public static function call(string $name, Expression ...$arguments): FunctionCall
    {
        return new FunctionCall($name, array_values($arguments), 1, 1);
    }

    public static function env(string $variable): FunctionCall
    {
        return self::call('env', self::str($variable));
    }

    public static function not(Expression $operand): UnaryExpression
    {
        return new UnaryExpression(UnaryOperator::Not, $operand, 1, 1);
    }

    public static function neg(Expression $operand): UnaryExpression
    {
        return new UnaryExpression(UnaryOperator::Negate, $operand, 1, 1);
    }

    public static function bin(BinaryOperator $operator, Expression $left, Expression $right): BinaryExpression
    {
        return new BinaryExpression($operator, $left, $right, 1, 1);
    }

    /**
     * Programa mínimo Src.list (GET) -> Dst.create, com partes opcionais.
     *
     * @param list<FieldMapping> $mappings
     * @param list<Header>       $sourceHeaders
     * @param list<Header>       $targetHeaders
     */
    public static function simpleProgram(
        string $sourceBase = 'https://src.example.com/api',
        string $targetBase = 'https://dst.example.com/v1',
        ?string $alias = null,
        ?Expression $condition = null,
        array $mappings = [],
        HttpMethod $targetMethod = HttpMethod::Post,
        array $sourceHeaders = [],
        array $targetHeaders = [],
    ): Program {
        return self::program(
            [
                self::api('Src', $sourceBase, [self::endpoint('list', HttpMethod::Get, '/items')], $sourceHeaders),
                self::api('Dst', $targetBase, [self::endpoint('create', $targetMethod, '/records')], $targetHeaders),
            ],
            [self::integration('sync', 'Src.list', 'Dst.create', $alias, $condition, $mappings)],
        );
    }
}
