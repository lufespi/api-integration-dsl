<?php

declare(strict_types=1);

namespace Tests\Unit\Generator;

use App\Ast\Expression\BinaryOperator as Op;
use App\Ast\Expression\Expression;
use App\Ast\Expression\Literal;
use App\Ast\Expression\LiteralType;
use App\Generator\ExpressionCompiler;
use App\Generator\GenerationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\Unit\Generator\Support\Ast;
use Tests\Unit\Generator\Support\GeneratedScript;

/**
 * Tradução de expressões (App\Generator\ExpressionCompiler).
 *
 * Além do texto gerado, os testes EXECUTAM a expressão traduzida, com a
 * biblioteca de runtime real, sobre registros simulados (em processo PHP
 * separado), verificando valor e tipo do resultado.
 */
final class ExpressionGenerationTest extends TestCase
{
    /** Alias usado nas referências de campo dos testes. */
    private const ALIAS = 'c';

    /**
     * Traduz a expressão e a avalia para o registro JSON informado.
     *
     * @param Expression           $expression Expressão da AST.
     * @param string               $recordJson Registro de origem (objeto JSON).
     * @param array<string,string> $env        Variáveis de ambiente.
     *
     * @return array{value?: mixed, error?: string, class?: string, stderr: string}
     */
    private function evaluate(Expression $expression, string $recordJson = '{}', array $env = []): array
    {
        $php = (new ExpressionCompiler())->compileForRecord($expression, 'teste', self::ALIAS);
        $script = GeneratedScript::forExpression($php);
        self::assertSame(0, $script->lint()['exitCode'], $script->code);

        return $script->call(
            sprintf('return dsl_test_expression(json_decode(%s, false, 512, JSON_THROW_ON_ERROR));', var_export($recordJson, true)),
            $env,
        );
    }

    /**
     * Avalia e exige sucesso, sem warnings/notices em stderr.
     */
    private function valueOf(Expression $expression, string $recordJson = '{}', array $env = []): mixed
    {
        $result = $this->evaluate($expression, $recordJson, $env);
        self::assertArrayNotHasKey('error', $result, $result['error'] ?? '');
        self::assertSame('', $result['stderr'], 'A avaliação não deve emitir avisos.');

        return $result['value'];
    }

    /**
     * Avalia e exige falha de runtime (DslRuntimeException), devolvendo a mensagem.
     */
    private function runtimeErrorOf(Expression $expression, string $recordJson = '{}', array $env = []): string
    {
        $result = $this->evaluate($expression, $recordJson, $env);
        self::assertArrayHasKey('error', $result, 'Era esperada uma falha de runtime.');
        self::assertSame('DslRuntimeException', $result['class']);
        self::assertSame('', $result['stderr']);

        return $result['error'];
    }

    /**
     * Cada tipo de literal vira um literal PHP que, executado, tem o mesmo
     * valor e o mesmo tipo do literal da AST.
     */
    #[DataProvider('literals')]
    public function testLiteralIsTranslatedPreservingValueAndType(Literal $literal, string $expectedPhp): void
    {
        self::assertSame($expectedPhp, (new ExpressionCompiler())->compileWithoutRecord($literal));
        self::assertSame($literal->value, $this->valueOf($literal));
    }

    /** @return iterable<string, array{Literal, string}> */
    public static function literals(): iterable
    {
        yield 'texto' => [Ast::str('paid'), "'paid'"];
        yield 'texto vazio' => [Ast::str(''), "''"];
        yield 'inteiro' => [Ast::int(18), '18'];
        yield 'inteiro negativo (AST manual)' => [Ast::int(-5), '(-5)'];
        yield 'menor inteiro' => [Ast::int(PHP_INT_MIN), '(-9223372036854775807-1)'];
        yield 'decimal' => [Ast::float(99.5), '99.5'];
        yield 'decimal com parte fracionária zero continua float' => [Ast::float(100.0), '100.0'];
        yield 'verdadeiro' => [Ast::bool(true), 'true'];
        yield 'falso' => [Ast::bool(false), 'false'];
        yield 'nulo' => [Ast::null(), 'null'];
    }

    /**
     * Textos com aspas, barras, quebras de linha, "$", "?>" e trechos de
     * código PHP são escapados: viram dados, nunca código executável.
     */
    #[DataProvider('hostileStrings')]
    public function testStringLiteralsCannotInjectPhpCode(string $value): void
    {
        self::assertSame($value, $this->valueOf(Ast::str($value)));
    }

    /** @return iterable<string, array{string}> */
    public static function hostileStrings(): iterable
    {
        yield 'aspas simples e chamada' => ["'); system('id'); //"];
        yield 'aspas duplas e variável' => ['"$record" {$x} ${y}'];
        yield 'barras invertidas' => ['C:\\dir\\\'x\\'];
        yield 'fecha tag PHP' => ['?> <?php echo 1; ?>'];
        yield 'quebras de linha e tab' => ["linha1\nlinha2\r\n\tfim"];
        yield 'byte nulo' => ["a\0b"];
        yield 'unicode' => ['São Paulo – ✓'];
    }

    /**
     * Literal cujo valor PHP não corresponde ao literalType é rejeitado.
     */
    public function testLiteralWithInconsistentTypeIsRejected(): void
    {
        $this->expectException(GenerationException::class);
        $this->expectExceptionMessage('literal do tipo int com valor PHP do tipo string');

        (new ExpressionCompiler())->compileWithoutRecord(new Literal(LiteralType::Int, '1', 4, 2));
    }

    /**
     * Operadores aritméticos, relacionais, de igualdade, lógicos e unários
     * produzem o resultado esperado para valores do registro.
     */
    #[DataProvider('operations')]
    public function testOperatorIsEvaluatedCorrectly(Expression $expression, string $record, mixed $expected): void
    {
        self::assertSame($expected, $this->valueOf($expression, $record));
    }

    /** @return iterable<string, array{Expression, string, mixed}> */
    public static function operations(): iterable
    {
        $a = Ast::field('c.a');
        $b = Ast::field('c.b');
        $nums = '{"a": 7, "b": 2}';

        yield '7 - 2' => [Ast::bin(Op::Subtract, $a, $b), $nums, 5];
        yield '7 * 2' => [Ast::bin(Op::Multiply, $a, $b), $nums, 14];
        yield '7 / 2' => [Ast::bin(Op::Divide, $a, $b), $nums, 3.5];
        yield '7 % 2' => [Ast::bin(Op::Modulo, $a, $b), $nums, 1];
        yield '7.5 % 2 (decimal)' => [Ast::bin(Op::Modulo, Ast::float(7.5), $b), $nums, 1.5];
        yield '7 + 2' => [Ast::bin(Op::Add, $a, $b), $nums, 9];
        yield '7 + 0.5' => [Ast::bin(Op::Add, $a, Ast::float(0.5)), $nums, 7.5];
        yield '-a' => [Ast::neg($a), $nums, -7];
        yield '7 < 2' => [Ast::bin(Op::Less, $a, $b), $nums, false];
        yield '7 <= 7' => [Ast::bin(Op::LessEqual, $a, Ast::int(7)), $nums, true];
        yield '7 > 2' => [Ast::bin(Op::Greater, $a, $b), $nums, true];
        yield '7 >= 7.0' => [Ast::bin(Op::GreaterEqual, $a, Ast::float(7.0)), $nums, true];
        yield '"abc" < "abd"' => [Ast::bin(Op::Less, Ast::str('abc'), Ast::str('abd')), '{}', true];
        yield '100 == 100.0' => [Ast::bin(Op::Equal, Ast::int(100), Ast::float(100.0)), '{}', true];
        yield '"1" == 1 é falso (igualdade estrita fora de números)' => [Ast::bin(Op::Equal, Ast::str('1'), Ast::int(1)), '{}', false];
        yield 'status == "paid"' => [Ast::bin(Op::Equal, Ast::field('c.status'), Ast::str('paid')), '{"status":"paid"}', true];
        yield 'status != "paid"' => [Ast::bin(Op::NotEqual, Ast::field('c.status'), Ast::str('paid')), '{"status":"paid"}', false];
        yield 'campo ausente == null' => [Ast::bin(Op::Equal, Ast::field('c.email'), Ast::null()), '{}', true];
        yield 'campo null != null' => [Ast::bin(Op::NotEqual, Ast::field('c.email'), Ast::null()), '{"email":null}', false];
        yield 'flag == true' => [Ast::bin(Op::Equal, Ast::field('c.flag'), Ast::bool(true)), '{"flag":true}', true];
        yield 'true && false' => [Ast::bin(Op::And, Ast::bool(true), Ast::bool(false)), '{}', false];
        yield 'false || true' => [Ast::bin(Op::Or, Ast::bool(false), Ast::bool(true)), '{}', true];
        yield '!true' => [Ast::not(Ast::bool(true)), '{}', false];
        yield '!campo ausente' => [Ast::not(Ast::field('c.archived')), '{}', true];
        yield '!!flag' => [Ast::not(Ast::not(Ast::field('c.flag'))), '{"flag":true}', true];
    }

    /**
     * A árvore define precedência e associatividade; o código gerado as
     * respeita (os parênteses da DSL não viram nós, apenas formam a árvore).
     */
    #[DataProvider('precedence')]
    public function testTreeShapeDefinesPrecedenceAndAssociativity(Expression $expression, mixed $expected, string $dsl): void
    {
        self::assertSame($expected, $this->valueOf($expression));
        self::assertSame($dsl, (new ExpressionCompiler())->describe($expression));
    }

    /** @return iterable<string, array{Expression, mixed, string}> */
    public static function precedence(): iterable
    {
        $ten = Ast::int(10);
        $five = Ast::int(5);
        $two = Ast::int(2);

        yield '(10 - 5) - 2: associatividade à esquerda' => [
            Ast::bin(Op::Subtract, Ast::bin(Op::Subtract, $ten, $five), $two), 3, '10 - 5 - 2',
        ];
        yield '10 - (5 - 2): agrupamento à direita' => [
            Ast::bin(Op::Subtract, $ten, Ast::bin(Op::Subtract, $five, $two)), 7, '10 - (5 - 2)',
        ];
        yield '10 + 5 * 2' => [
            Ast::bin(Op::Add, $ten, Ast::bin(Op::Multiply, $five, $two)), 20, '10 + 5 * 2',
        ];
        yield '(10 + 5) * 2' => [
            Ast::bin(Op::Multiply, Ast::bin(Op::Add, $ten, $five), $two), 30, '(10 + 5) * 2',
        ];
        yield '-(10 - 5)' => [Ast::neg(Ast::bin(Op::Subtract, $ten, $five)), -5, '-(10 - 5)'];
        yield 'true || false && false' => [
            Ast::bin(Op::Or, Ast::bool(true), Ast::bin(Op::And, Ast::bool(false), Ast::bool(false))), true, 'true || false && false',
        ];
        yield '(true || false) && false' => [
            Ast::bin(Op::And, Ast::bin(Op::Or, Ast::bool(true), Ast::bool(false)), Ast::bool(false)), false, '(true || false) && false',
        ];
        yield '!(true && false)' => [Ast::not(Ast::bin(Op::And, Ast::bool(true), Ast::bool(false))), true, '!(true && false)'];
        yield '(1 < 2) == true' => [
            Ast::bin(Op::Equal, Ast::bin(Op::Less, Ast::int(1), $two), Ast::bool(true)), true, '1 < 2 == true',
        ];
    }

    /**
     * "&&" e "||" mantêm o curto-circuito: o lado direito não é avaliado
     * quando o esquerdo já decide (aqui, evitaria uma divisão por zero).
     */
    public function testLogicalOperatorsShortCircuit(): void
    {
        $divisionByZero = Ast::bin(Op::Greater, Ast::bin(Op::Divide, Ast::int(1), Ast::int(0)), Ast::int(0));

        self::assertFalse($this->valueOf(Ast::bin(Op::And, Ast::bool(false), $divisionByZero)));
        self::assertTrue($this->valueOf(Ast::bin(Op::Or, Ast::bool(true), $divisionByZero)));
    }

    /**
     * "+" concatena quando ao menos um operando é texto e soma caso
     * contrário; a decisão é tomada em runtime, com os dados reais.
     */
    #[DataProvider('additions')]
    public function testPlusConcatenatesTextAndAddsNumbers(Expression $expression, string $record, mixed $expected): void
    {
        self::assertSame($expected, $this->valueOf($expression, $record));
    }

    /** @return iterable<string, array{Expression, string, mixed}> */
    public static function additions(): iterable
    {
        $fullName = Ast::bin(
            Op::Add,
            Ast::bin(Op::Add, Ast::field('c.firstName'), Ast::str(' ')),
            Ast::field('c.lastName'),
        );

        yield 'nome completo (campos de texto)' => [$fullName, '{"firstName":"Ana","lastName":"Souza"}', 'Ana Souza'];
        yield 'texto + inteiro' => [Ast::bin(Op::Add, Ast::str('pedido-'), Ast::field('c.id')), '{"id":42}', 'pedido-42'];
        yield 'inteiro + texto' => [Ast::bin(Op::Add, Ast::field('c.id'), Ast::str('º')), '{"id":3}', '3º'];
        yield 'texto + decimal' => [Ast::bin(Op::Add, Ast::str('v='), Ast::float(1.5)), '{}', 'v=1.5'];
        yield 'texto + booleano' => [Ast::bin(Op::Add, Ast::str('ativo='), Ast::bool(true)), '{}', 'ativo=true'];
        yield 'campos numéricos somam' => [Ast::bin(Op::Add, Ast::field('c.a'), Ast::field('c.b')), '{"a":1,"b":2}', 3];
        yield '"1" + 2 concatena (texto não é convertido em número)' => [Ast::bin(Op::Add, Ast::str('1'), Ast::int(2)), '{}', '12'];
    }

    /**
     * Operações com tipos incompatíveis geram DslRuntimeException clara
     * (o registro falha), em vez de warnings ou resultados arbitrários.
     */
    #[DataProvider('invalidOperations')]
    public function testIncompatibleOperandsFailWithClearMessage(Expression $expression, string $record, string $message): void
    {
        self::assertSame($message, $this->runtimeErrorOf($expression, $record));
    }

    /** @return iterable<string, array{Expression, string, string}> */
    public static function invalidOperations(): iterable
    {
        yield 'texto + campo ausente' => [
            Ast::bin(Op::Add, Ast::str('x'), Ast::field('c.ausente')), '{}', 'não é possível usar um valor null como texto',
        ];
        yield 'subtração com campo ausente' => [
            Ast::bin(Op::Subtract, Ast::int(2026), Ast::field('c.birthYear')), '{}', 'o operador "-" exige números, mas recebeu int e null',
        ];
        yield 'multiplicação com texto' => [
            Ast::bin(Op::Multiply, Ast::field('c.score'), Ast::float(1.5)), '{"score":"10"}', 'o operador "*" exige números, mas recebeu string e float',
        ];
        yield 'divisão por zero' => [Ast::bin(Op::Divide, Ast::int(1), Ast::int(0)), '{}', 'divisão por zero (operador "/")'];
        yield 'resto por zero' => [Ast::bin(Op::Modulo, Ast::int(1), Ast::float(0.0)), '{}', 'divisão por zero (operador "%")'];
        yield 'número < texto' => [
            Ast::bin(Op::Less, Ast::int(1), Ast::str('2')), '{}', 'o operador "<" exige dois números ou dois textos, mas recebeu int e string',
        ];
        yield 'menos unário em texto' => [Ast::neg(Ast::str('a')), '{}', 'o operador "-" unário exige um número, mas recebeu string'];
        yield 'soma com objeto' => [
            Ast::bin(Op::Add, Ast::field('c.obj'), Ast::int(1)), '{"obj":{}}', 'o operador "+" exige números, mas recebeu objeto e int',
        ];
    }

    /**
     * Referências de campo acessam caminhos aninhados; campo ausente, nulo
     * ou caminho que atravessa um valor não-objeto resulta em null, sem
     * notices nem warnings.
     */
    #[DataProvider('fieldAccesses')]
    public function testFieldReferenceReadsNestedPathsAndMissingFieldsAreNull(string $path, string $record, mixed $expected): void
    {
        self::assertSame($expected, $this->valueOf(Ast::field($path), $record));
    }

    /** @return iterable<string, array{string, string, mixed}> */
    public static function fieldAccesses(): iterable
    {
        $record = '{"email":"a@b.c","cliente":{"contato":{"email":"x@y.z"}},"vazio":null,"nome":"Ana","from":"crm"}';

        yield 'campo simples' => ['c.email', $record, 'a@b.c'];
        yield 'campo aninhado' => ['c.cliente.contato.email', $record, 'x@y.z'];
        yield 'palavra reservada como campo' => ['c.from', $record, 'crm'];
        yield 'campo ausente' => ['c.telefone', $record, null];
        yield 'aninhado ausente' => ['c.cliente.endereco.cidade', $record, null];
        yield 'atravessa null' => ['c.vazio.x', $record, null];
        yield 'atravessa texto' => ['c.nome.x', $record, null];
    }

    /**
     * O alias sozinho referencia o registro inteiro.
     */
    public function testAliasAloneReferencesTheWholeRecord(): void
    {
        $value = $this->valueOf(Ast::field('c'), '{"a":1}');

        self::assertInstanceOf(stdClass::class, $value);
        self::assertSame(1, $value->a);
    }

    /**
     * Segmentos de campo são emitidos como literais escapados: nomes
     * estranhos (possíveis em ASTs montadas à mão) não viram código.
     */
    public function testFieldSegmentsAreEscaped(): void
    {
        $php = (new ExpressionCompiler())->compileForRecord(
            new \App\Ast\Expression\FieldReference(['c', "x'] ); system('id'); //"], 1, 1),
            'teste',
            'c',
        );

        self::assertSame("dsl_field(\$record, ['x\\'] ); system(\\'id\\'); //'])", $php);
        self::assertNull($this->valueOf(new \App\Ast\Expression\FieldReference(['c', "x'] ); system('id'); //"], 1, 1)));
    }

    /**
     * env("NOME") lê a variável de ambiente em runtime.
     */
    public function testEnvReadsEnvironmentVariableAtRuntime(): void
    {
        $header = Ast::bin(Op::Add, Ast::str('Bearer '), Ast::env('CRM_TOKEN'));

        self::assertSame("dsl_add('Bearer ', dsl_env('CRM_TOKEN'))", (new ExpressionCompiler())->compileWithoutRecord($header));
        self::assertSame('Bearer s3cr3t', $this->valueOf($header, '{}', ['CRM_TOKEN' => 's3cr3t']));
    }

    /**
     * Variável ausente (ou vazia) gera erro claro, que nomeia a variável.
     */
    public function testMissingEnvironmentVariableFailsWithClearMessage(): void
    {
        $message = $this->runtimeErrorOf(Ast::env('CRM_TOKEN'), '{}', ['OUTRA' => 'valor']);
        self::assertSame('variável de ambiente obrigatória "CRM_TOKEN" não definida', $message);

        $message = $this->runtimeErrorOf(Ast::env('CRM_TOKEN'), '{}', ['CRM_TOKEN' => '']);
        self::assertSame('variável de ambiente obrigatória "CRM_TOKEN" não definida', $message);
    }

    /**
     * Funções desconhecidas, aridade errada ou argumento não literal em
     * env() são erros de geração (não PHP inválido nem chamada arbitrária).
     */
    #[DataProvider('invalidFunctionCalls')]
    public function testInvalidFunctionCallIsAGenerationError(Expression $expression, string $message): void
    {
        $this->expectException(GenerationException::class);
        $this->expectExceptionMessage($message);

        (new ExpressionCompiler())->compileForRecord($expression, 'teste', self::ALIAS);
    }

    /** @return iterable<string, array{Expression, string}> */
    public static function invalidFunctionCalls(): iterable
    {
        yield 'função desconhecida' => [Ast::call('upper', Ast::str('a')), "função desconhecida 'upper'; funções disponíveis: env"];
        yield 'função do PHP' => [Ast::call('system', Ast::str('id')), "função desconhecida 'system'"];
        yield 'nome com caixa diferente' => [Ast::call('ENV', Ast::str('A')), "função desconhecida 'ENV'"];
        yield 'env sem argumento' => [Ast::call('env'), "a função 'env' espera exatamente 1 argumento, mas recebeu 0"];
        yield 'env com 2 argumentos' => [Ast::call('env', Ast::str('A'), Ast::str('B')), 'mas recebeu 2'];
        yield 'env com campo do registro' => [Ast::call('env', Ast::field('c.var')), "o argumento de 'env' deve ser um texto literal"];
        yield 'env com número' => [Ast::call('env', Ast::int(1)), "o argumento de 'env' deve ser um texto literal"];
        yield 'env com nome inválido' => [Ast::env('A-B'), 'nome de variável de ambiente inválido em env(): "A-B"'];
    }

    /**
     * Referências de campo exigem contexto de registro e o alias correto
     * (regra provisória P12); a posição do erro é a do nó.
     */
    public function testFieldReferenceRules(): void
    {
        $compiler = new ExpressionCompiler();
        $reference = new \App\Ast\Expression\FieldReference(['pedido', 'total'], 7, 10);

        $cases = [
            "a referência de campo 'pedido.total' não pode ser usada em cabeçalho" => static fn () => $compiler->compileWithoutRecord($reference),
            "a integração 'x' usa a referência de campo 'pedido.total', mas não declara alias" => static fn () => $compiler->compileForRecord($reference, 'x', null),
            "a referência de campo 'pedido.total' deve começar pelo alias 'contato' da integração 'x'" => static fn () => $compiler->compileForRecord($reference, 'x', 'contato'),
        ];
        foreach ($cases as $message => $compile) {
            try {
                $compile();
                self::fail('Era esperada GenerationException: ' . $message);
            } catch (GenerationException $exception) {
                self::assertStringContainsString($message, $exception->getMessage());
                self::assertStringStartsWith('Erro de geração na linha 7, coluna 10: ', $exception->getMessage());
                self::assertSame([7, 10], [$exception->getSourceLine(), $exception->getSourceColumn()]);
            }
        }

        self::assertSame("dsl_field(\$record, ['total'])", $compiler->compileForRecord($reference, 'x', 'pedido'));
    }
    /**
     * FIX-04: literais decimais não finitos (ex.: um decimal enorme que o
     * Lexer converte em INF) são erro de geração, não código com INF/NAN.
     */
    #[DataProvider('nonFiniteFloats')]
    public function testNonFiniteFloatLiteralIsAGenerationError(float $value): void
    {
        $this->expectException(GenerationException::class);
        $this->expectExceptionMessage('Erro de geração na linha 2, coluna 7: literal decimal fora do intervalo numérico suportado');

        (new ExpressionCompiler())->compileWithoutRecord(new Literal(LiteralType::Float, $value, 2, 7));
    }

    /** @return iterable<string, array{float}> */
    public static function nonFiniteFloats(): iterable
    {
        yield 'INF' => [INF];
        yield '-INF' => [-INF];
        yield 'NAN' => [NAN];
    }

    /**
     * FIX-04: valores extremos, mas finitos, continuam válidos.
     */
    public function testExtremeFiniteValuesAreAccepted(): void
    {
        self::assertSame(PHP_FLOAT_MAX, $this->valueOf(Ast::float(PHP_FLOAT_MAX)));
        self::assertSame(-PHP_FLOAT_MAX, $this->valueOf(Ast::field('c.v'), '{"v": -1.7976931348623157e308}'));
        self::assertSame(PHP_FLOAT_MAX, $this->valueOf(Ast::bin(Op::Multiply, Ast::field('c.v'), Ast::int(1)), '{"v": 1.7976931348623157e308}'));
        self::assertSame(5.0e-324, $this->valueOf(Ast::field('c.v'), '{"v": 5e-324}'));
    }

    /**
     * FIX-04: resultados aritméticos não finitos (overflow) e números JSON
     * fora do intervalo de float (decodificados como INF) geram erro claro,
     * em vez de chegar silenciosamente a condições e mapeamentos.
     */
    #[DataProvider('nonFiniteOperations')]
    public function testNonFiniteValuesFailAtRuntime(Expression $expression, string $record, string $message): void
    {
        self::assertSame($message, $this->runtimeErrorOf($expression, $record));
    }

    /** @return iterable<string, array{Expression, string, string}> */
    public static function nonFiniteOperations(): iterable
    {
        $v = Ast::field('c.v');
        $overflow = 'o operador "%s" produziu um resultado fora do intervalo numérico suportado';

        yield 'multiplicação com overflow' => [Ast::bin(Op::Multiply, $v, Ast::int(10)), '{"v": 1e308}', sprintf($overflow, '*')];
        yield 'soma com overflow' => [Ast::bin(Op::Add, $v, $v), '{"v": 1.7976931348623157e308}', sprintf($overflow, '+')];
        yield 'subtração com overflow' => [Ast::bin(Op::Subtract, Ast::neg($v), $v), '{"v": 1e308}', sprintf($overflow, '-')];
        yield 'divisão com overflow' => [Ast::bin(Op::Divide, $v, Ast::float(1e-10)), '{"v": 1e308}', sprintf($overflow, '/')];
        yield 'JSON 1e400 (INF)' => [$v, '{"v": 1e400}', 'o campo "v" contém um número fora do intervalo suportado'];
        yield 'JSON -1e400 aninhado' => [Ast::field('c.a.b'), '{"a": {"b": -1e400}}', 'o campo "a.b" contém um número fora do intervalo suportado'];
        yield 'comparação com 1e400' => [Ast::bin(Op::Greater, $v, Ast::int(0)), '{"v": 1e400}', 'o campo "v" contém um número fora do intervalo suportado'];
    }

    /**
     * FIX-04: dsl_convert() rejeita INF, -INF e NAN para qualquer tipo, e
     * textos numéricos que viram INF ao converter para float; conversões
     * válidas continuam iguais.
     */
    public function testConvertRejectsNonFiniteValues(): void
    {
        $script = GeneratedScript::forExpression('null');
        $result = $script->call(<<<'PHP'
            $outcome = static function (mixed $value, string $type): mixed {
                try {
                    return dsl_convert($value, $type, 'preco');
                } catch (DslRuntimeException $exception) {
                    return $exception->getMessage();
                }
            };

            return [
                $outcome(INF, 'float'),
                $outcome(-INF, 'float'),
                $outcome(NAN, 'float'),
                $outcome(INF, 'string'),
                $outcome(NAN, 'int'),
                $outcome('1e400', 'float'),
                $outcome('-1e400', 'float'),
                $outcome(1.5, 'float'),
                $outcome(2, 'float'),
                $outcome('2.5', 'float'),
                $outcome(PHP_FLOAT_MAX, 'float'),
            ];
            PHP);

        $notFinite = 'campo "preco": o valor não é um número finito';
        self::assertSame(
            [$notFinite, $notFinite, $notFinite, $notFinite, $notFinite, $notFinite, $notFinite, 1.5, 2.0, 2.5, PHP_FLOAT_MAX],
            $result['value'] ?? $result,
        );
    }

    /**
     * FIX-02: dsl_convert() para int rejeita inteiros fora do intervalo do
     * PHP (que chegam como texto por JSON_BIGINT_AS_STRING) e aceita os limites.
     */
    public function testConvertToIntRejectsIntegersOutsidePhpRange(): void
    {
        $script = GeneratedScript::forExpression('null');
        $result = $script->call(<<<'PHP'
            $outcome = static function (mixed $value, string $type): mixed {
                try {
                    return dsl_convert($value, $type, 'id');
                } catch (DslRuntimeException $exception) {
                    return $exception->getMessage();
                }
            };

            return [
                $outcome('9223372036854775807', 'int'),
                $outcome('-9223372036854775808', 'int'),
                $outcome('9223372036854775808', 'int'),
                $outcome('-9223372036854775809', 'int'),
                $outcome('12345678901234567890', 'string'),
            ];
            PHP);

        $outOfRange = 'campo "id": não é possível converter um valor string para int';
        self::assertSame(
            [PHP_INT_MAX, PHP_INT_MIN, $outOfRange, $outOfRange, '12345678901234567890'],
            $result['value'] ?? $result,
        );
    }

    /**
     * FIX-03: dsl_safe_url() oculta toda a query string e preserva o resto.
     */
    public function testSafeUrlRedactsTheWholeQueryString(): void
    {
        $result = GeneratedScript::forExpression('null')->call(<<<'PHP'
            return [
                dsl_safe_url('https://api.example.com/users?api_key=secret123'),
                dsl_safe_url('https://api.example.com/users?a=1&token=x'),
                dsl_safe_url('https://api.example.com/users?'),
                dsl_safe_url('http://127.0.0.1:8080/v1/users'),
            ];
            PHP);

        self::assertSame([
            'https://api.example.com/users?[REDACTED]',
            'https://api.example.com/users?[REDACTED]',
            'https://api.example.com/users?[REDACTED]',
            'http://127.0.0.1:8080/v1/users',
        ], $result['value'] ?? $result);
    }
}
