<?php

declare(strict_types=1);

namespace App\Generator;

use App\Ast\Expression\BinaryExpression;
use App\Ast\Expression\BinaryOperator;
use App\Ast\Expression\Expression;
use App\Ast\Expression\FieldReference;
use App\Ast\Expression\FunctionCall;
use App\Ast\Expression\Literal;
use App\Ast\Expression\LiteralType;
use App\Ast\Expression\UnaryExpression;
use App\Ast\Expression\UnaryOperator;

/**
 * Traduz expressões da AST (App\Ast\Expression) para expressões PHP.
 *
 * O código produzido chama as funções de RuntimeLibrary (dsl_add,
 * dsl_compare, dsl_field...) e lê o registro corrente da variável $record.
 * Cada nó vira uma chamada de função ou uma expressão entre parênteses, então
 * a precedência e a associatividade são exatamente as da árvore montada pelo
 * Parser.
 *
 * Segurança: nenhum texto vindo da AST é concatenado como código. Literais,
 * nomes de campos e de variáveis de ambiente são emitidos com var_export(),
 * que produz literais PHP corretamente escapados, e só a função embutida
 * env() pode ser chamada.
 *
 * @internal Usada pelo PhpGenerator; pública para ser testada isoladamente.
 */
final class ExpressionCompiler
{
    /** Funções embutidas aceitas pela DSL (pendência P7: outras dependem do grupo). */
    public const FUNCTIONS = ['env'];

    /** Precedência dos operadores binários (docs/grammar.md, seção 6), para describe(). */
    private const PRECEDENCE = [
        '||' => 1,
        '&&' => 2,
        '==' => 3, '!=' => 3,
        '<' => 4, '<=' => 4, '>' => 4, '>=' => 4,
        '+' => 5, '-' => 5,
        '*' => 6, '/' => 6, '%' => 6,
    ];

    /**
     * Traduz uma expressão avaliada sem registro (valor de cabeçalho).
     *
     * @param Expression $expression Expressão da AST.
     *
     * @return string Expressão PHP.
     *
     * @throws GenerationException Se a expressão usar campos ou funções inválidas.
     */
    public function compileWithoutRecord(Expression $expression): string
    {
        return $this->compile($expression, null, null);
    }

    /**
     * Traduz uma expressão avaliada para cada registro ("when" ou "map").
     *
     * Regra provisória (pendência P12): referências de campo exigem um alias
     * explícito ("from Api.endpoint as alias") e devem começar por ele.
     *
     * @param Expression  $expression  Expressão da AST.
     * @param string      $integration Nome da integração (para mensagens de erro).
     * @param string|null $alias       Alias do registro, ou null se omitido.
     *
     * @return string Expressão PHP que lê o registro de $record.
     *
     * @throws GenerationException Se a expressão usar campos ou funções inválidas.
     */
    public function compileForRecord(Expression $expression, string $integration, ?string $alias): string
    {
        return $this->compile($expression, $integration, $alias);
    }

    /**
     * Reconstrói o texto da expressão na sintaxe da DSL, com o mínimo de
     * parênteses. Usado em comentários do código gerado.
     *
     * @param Expression $expression Expressão da AST.
     *
     * @return string
     */
    public function describe(Expression $expression): string
    {
        return match (true) {
            $expression instanceof Literal => is_string($expression->value)
                ? '"' . addcslashes($expression->value, "\"\\\n\t") . '"'
                : self::phpLiteral($expression->value),
            $expression instanceof FieldReference => implode('.', $expression->path),
            $expression instanceof FunctionCall => sprintf(
                '%s(%s)',
                $expression->name,
                implode(', ', array_map($this->describe(...), $expression->arguments)),
            ),
            $expression instanceof UnaryExpression => $expression->operator->value . (
                $expression->operand instanceof BinaryExpression
                    ? '(' . $this->describe($expression->operand) . ')'
                    : $this->describe($expression->operand)
            ),
            $expression instanceof BinaryExpression => sprintf(
                '%s %s %s',
                $this->describeOperand($expression->left, $expression->operator, false),
                $expression->operator->value,
                $this->describeOperand($expression->right, $expression->operator, true),
            ),
            default => '?',
        };
    }

    /**
     * Converte um valor escalar em literal PHP seguro.
     *
     * @param string|int|float|bool|null $value Valor a emitir.
     *
     * @return string Literal PHP; números negativos entre parênteses.
     */
    public static function phpLiteral(string|int|float|bool|null $value): string
    {
        if ($value === null) {
            return 'null';
        }
        $code = var_export($value, true);

        return (is_int($value) || is_float($value)) && str_starts_with($code, '-') ? '(' . $code . ')' : $code;
    }

    /**
     * @param Expression  $expression  Expressão a traduzir.
     * @param string|null $integration Integração corrente; null quando não há registro.
     * @param string|null $alias       Alias do registro.
     *
     * @return string
     */
    private function compile(Expression $expression, ?string $integration, ?string $alias): string
    {
        return match (true) {
            $expression instanceof Literal => $this->literal($expression),
            $expression instanceof FieldReference => $this->fieldReference($expression, $integration, $alias),
            $expression instanceof FunctionCall => $this->functionCall($expression),
            $expression instanceof UnaryExpression => $this->unary($expression, $integration, $alias),
            $expression instanceof BinaryExpression => $this->binary($expression, $integration, $alias),
            default => throw new GenerationException(
                sprintf('tipo de expressão não suportado: %s', $expression::class),
            ),
        };
    }

    /**
     * @param Literal $literal Literal da AST.
     *
     * @return string
     */
    private function literal(Literal $literal): string
    {
        $value = $literal->value;
        $consistent = match ($literal->literalType) {
            LiteralType::String => is_string($value),
            LiteralType::Int => is_int($value),
            LiteralType::Float => is_float($value),
            LiteralType::Bool => is_bool($value),
            LiteralType::Null => $value === null,
        };
        if (!$consistent) {
            throw new GenerationException(
                sprintf('literal do tipo %s com valor PHP do tipo %s', $literal->literalType->value, get_debug_type($value)),
                $literal->line,
                $literal->column,
            );
        }

        return self::phpLiteral($value);
    }

    /**
     * @param FieldReference $reference   Referência da AST.
     * @param string|null    $integration Integração corrente; null quando não há registro.
     * @param string|null    $alias       Alias do registro.
     *
     * @return string
     */
    private function fieldReference(FieldReference $reference, ?string $integration, ?string $alias): string
    {
        $path = $reference->path;
        foreach ($path as $segment) {
            if (!is_string($segment) || $segment === '') {
                throw new GenerationException('referência de campo com segmento vazio ou inválido', $reference->line, $reference->column);
            }
        }
        if ($path === []) {
            throw new GenerationException('referência de campo vazia', $reference->line, $reference->column);
        }

        $text = implode('.', $path);
        if ($integration === null) {
            throw new GenerationException(
                sprintf("a referência de campo '%s' não pode ser usada em cabeçalho: cabeçalhos são calculados sem registro", $text),
                $reference->line,
                $reference->column,
            );
        }
        if ($alias === null) {
            throw new GenerationException(
                sprintf(
                    "a integração '%s' usa a referência de campo '%s', mas não declara alias; "
                    . "use 'from Api.endpoint as nome;' (regra provisória, pendência P12)",
                    $integration,
                    $text,
                ),
                $reference->line,
                $reference->column,
            );
        }
        if ($path[0] !== $alias) {
            throw new GenerationException(
                sprintf("a referência de campo '%s' deve começar pelo alias '%s' da integração '%s'", $text, $alias, $integration),
                $reference->line,
                $reference->column,
            );
        }

        $segments = array_map(static fn (string $segment): string => var_export($segment, true), array_slice($path, 1));

        return sprintf('dsl_field($record, [%s])', implode(', ', $segments));
    }

    /**
     * Apenas env("NOME") é aceita: o nome deve ser um texto literal, para que
     * dados do registro nunca escolham qual variável de ambiente é lida.
     *
     * @param FunctionCall $call Chamada da AST.
     *
     * @return string
     */
    private function functionCall(FunctionCall $call): string
    {
        if (!in_array($call->name, self::FUNCTIONS, true)) {
            throw new GenerationException(
                sprintf("função desconhecida '%s'; funções disponíveis: %s", $call->name, implode(', ', self::FUNCTIONS)),
                $call->line,
                $call->column,
            );
        }

        $arguments = array_values($call->arguments);
        if (count($arguments) !== 1) {
            throw new GenerationException(
                sprintf("a função 'env' espera exatamente 1 argumento, mas recebeu %d", count($arguments)),
                $call->line,
                $call->column,
            );
        }
        $argument = $arguments[0];
        if (!$argument instanceof Literal || !is_string($argument->value)) {
            throw new GenerationException(
                "o argumento de 'env' deve ser um texto literal com o nome da variável, ex.: env(\"API_TOKEN\")",
                $call->line,
                $call->column,
            );
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $argument->value) !== 1) {
            throw new GenerationException(
                sprintf('nome de variável de ambiente inválido em env(): "%s"', addcslashes($argument->value, "\0..\37\"\\")),
                $argument->line,
                $argument->column,
            );
        }

        return sprintf('dsl_env(%s)', var_export($argument->value, true));
    }

    /**
     * @param UnaryExpression $expression  Expressão unária da AST.
     * @param string|null     $integration Integração corrente.
     * @param string|null     $alias       Alias do registro.
     *
     * @return string
     */
    private function unary(UnaryExpression $expression, ?string $integration, ?string $alias): string
    {
        $operand = $this->compile($expression->operand, $integration, $alias);

        return match ($expression->operator) {
            UnaryOperator::Not => sprintf('(!%s)', $operand),
            UnaryOperator::Negate => sprintf('dsl_negate(%s)', $operand),
            default => throw new GenerationException(
                sprintf("operador unário '%s' não suportado", $expression->operator->value),
                $expression->line,
                $expression->column,
            ),
        };
    }

    /**
     * Lógicos usam os operadores nativos do PHP (curto-circuito, conversão
     * booleana do PHP); os demais são funções de runtime com checagem de tipos.
     *
     * @param BinaryExpression $expression  Expressão binária da AST.
     * @param string|null      $integration Integração corrente.
     * @param string|null      $alias       Alias do registro.
     *
     * @return string
     */
    private function binary(BinaryExpression $expression, ?string $integration, ?string $alias): string
    {
        $left = $this->compile($expression->left, $integration, $alias);
        $right = $this->compile($expression->right, $integration, $alias);
        $operator = var_export($expression->operator->value, true);

        return match ($expression->operator) {
            BinaryOperator::Or => sprintf('(%s || %s)', $left, $right),
            BinaryOperator::And => sprintf('(%s && %s)', $left, $right),
            BinaryOperator::Equal => sprintf('dsl_equals(%s, %s)', $left, $right),
            BinaryOperator::NotEqual => sprintf('(!dsl_equals(%s, %s))', $left, $right),
            BinaryOperator::Less,
            BinaryOperator::LessEqual,
            BinaryOperator::Greater,
            BinaryOperator::GreaterEqual => sprintf('dsl_compare(%s, %s, %s)', $operator, $left, $right),
            BinaryOperator::Add => sprintf('dsl_add(%s, %s)', $left, $right),
            BinaryOperator::Subtract,
            BinaryOperator::Multiply,
            BinaryOperator::Divide,
            BinaryOperator::Modulo => sprintf('dsl_arith(%s, %s, %s)', $operator, $left, $right),
            default => throw new GenerationException(
                sprintf("operador binário '%s' não suportado", $expression->operator->value),
                $expression->line,
                $expression->column,
            ),
        };
    }

    /**
     * Descreve um operando de operação binária, com parênteses apenas quando
     * a precedência ou a associatividade (à esquerda) os exigem.
     *
     * @param Expression     $operand Operando.
     * @param BinaryOperator $parent  Operador da expressão que contém o operando.
     * @param bool           $isRight Se o operando está à direita.
     *
     * @return string
     */
    private function describeOperand(Expression $operand, BinaryOperator $parent, bool $isRight): string
    {
        $text = $this->describe($operand);
        if (!$operand instanceof BinaryExpression) {
            return $text;
        }

        $child = self::PRECEDENCE[$operand->operator->value];
        $outer = self::PRECEDENCE[$parent->value];
        $nonAssociative = $outer === 3 || $outer === 4;
        $needsParentheses = $child < $outer || ($child === $outer && ($isRight || $nonAssociative));

        return $needsParentheses ? '(' . $text . ')' : $text;
    }
}
