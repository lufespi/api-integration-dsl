<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Ast\Expression\BinaryExpression;
use App\Ast\Expression\Expression;
use App\Ast\Expression\FieldReference;
use App\Ast\Expression\FunctionCall;
use App\Ast\Expression\Literal;
use App\Ast\Expression\UnaryExpression;
use LogicException;

/** Imprime a expressão totalmente parentizada, ex.: "a + b * c" → "(a + (b * c))". */
final class ExpressionPrinter
{
    public static function print(Expression $expression): string
    {
        return match (true) {
            $expression instanceof BinaryExpression => sprintf(
                '(%s %s %s)',
                self::print($expression->left),
                $expression->operator->value,
                self::print($expression->right),
            ),
            $expression instanceof UnaryExpression => sprintf(
                '(%s%s)',
                $expression->operator->value,
                self::print($expression->operand),
            ),
            $expression instanceof FieldReference => implode('.', $expression->path),
            $expression instanceof FunctionCall => sprintf(
                '%s(%s)',
                $expression->name,
                implode(', ', array_map(self::print(...), $expression->arguments)),
            ),
            $expression instanceof Literal => var_export($expression->value, true),
            default => throw new LogicException('Expressão desconhecida: ' . $expression::class),
        };
    }
}
