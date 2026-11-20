<?php

declare(strict_types=1);

namespace App\Parser;

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
use App\Exceptions\SyntaxException;
use App\Lexer\Token;
use App\Lexer\TokenType;
use InvalidArgumentException;
use LogicException;

/**
 * Analisador sintático da DSL.
 *
 * Técnica: análise descendente recursiva preditiva LL(1), escrita à mão
 * (docs/grammar.md, seção 7). Cada não terminal da GLC corresponde a um
 * método privado desta classe, indicado no docblock de cada método; a escolha
 * da produção é feita olhando apenas o token atual (um símbolo de lookahead).
 *
 * Entrada: lista de tokens produzida pelo Lexer, terminada por um token EOF.
 * Saída:   a raiz da AST (App\Ast\Program).
 *
 * Erros: ao encontrar um token inesperado, o Parser registra um SyntaxError
 * e se recupera em modo pânico, descartando tokens até o início da próxima
 * declaração de nível superior ("api" ou "integration"). Assim, vários erros
 * independentes podem ser relatados em uma única execução. Ao final, se houve
 * erros, todos são lançados juntos em uma SyntaxException.
 *
 * Uso:
 *   $program = (new Parser($tokens))->parse();
 */
final class Parser
{
    /** Contexto usado nas mensagens de erro das expressões. */
    private const EXPRESSION_START = "expressão (literal, identificador, '(', '!' ou '-')";

    /** @var list<Token> */
    private readonly array $tokens;

    /** Índice do token atual (lookahead) em $tokens. */
    private int $position = 0;

    /** @var list<SyntaxError> */
    private array $errors = [];

    /**
     * @param list<Token> $tokens Sequência de tokens terminada por exatamente um EOF.
     *
     * @throws InvalidArgumentException se a sequência violar o contrato com o Lexer.
     */
    public function __construct(array $tokens)
    {
        if ($tokens === []) {
            throw new InvalidArgumentException('A lista de tokens está vazia: o Lexer deve sempre terminá-la com um token EOF.');
        }

        $tokens = array_values($tokens);
        $last = count($tokens) - 1;

        foreach ($tokens as $index => $token) {
            if (!$token instanceof Token) {
                throw new InvalidArgumentException(sprintf('Elemento %d da lista de tokens não é um %s.', $index, Token::class));
            }

            if (($token->type === TokenType::Eof) !== ($index === $last)) {
                throw new InvalidArgumentException('A lista de tokens deve conter exatamente um token EOF, na última posição.');
            }
        }

        $this->tokens = $tokens;
    }

    /**
     * Analisa a sequência de tokens e constrói a AST.
     *
     * programa = declaracao , { declaracao } , EOF ;
     *
     * @return Program Raiz da AST, com APIs e integrações na ordem do fonte.
     *
     * @throws SyntaxException com todos os erros encontrados, se houver algum.
     */
    public function parse(): Program
    {
        $this->position = 0;
        $this->errors = [];

        $apis = [];
        $integrations = [];

        if ($this->check(TokenType::Eof)) {
            $this->errors[] = $this->unexpected("declaração 'api' ou 'integration' (o programa está vazio)")->errors()[0];
        }

        while (!$this->check(TokenType::Eof)) {
            try {
                $declaration = $this->declaration();

                if ($declaration instanceof Api) {
                    $apis[] = $declaration;
                } else {
                    $integrations[] = $declaration;
                }
            } catch (SyntaxException $exception) {
                array_push($this->errors, ...$exception->errors());
                $this->synchronize();
            }
        }

        if ($this->errors !== []) {
            throw new SyntaxException($this->errors);
        }

        return new Program($apis, $integrations);
    }

    // ---------------------------------------------------------------------
    // Declarações de nível superior
    // ---------------------------------------------------------------------

    /**
     * declaracao = decl_api | decl_integracao ;
     *
     * @return Api|Integration
     */
    private function declaration(): Api|Integration
    {
        return match ($this->current()->type) {
            TokenType::Api => $this->apiDeclaration(),
            TokenType::Integration => $this->integrationDeclaration(),
            default => throw $this->unexpected("declaração 'api' ou 'integration'"),
        };
    }

    /**
     * decl_api = "api" , IDENT , "{" , decl_base , { decl_header } ,
     *            decl_endpoint , { decl_endpoint } , "}" ;
     *
     * @return Api
     */
    private function apiDeclaration(): Api
    {
        $keyword = $this->expect(TokenType::Api, "'api'");
        $name = $this->expect(TokenType::Identifier, "nome da API (identificador) após 'api'")->lexeme;
        $this->expect(TokenType::LeftBrace, sprintf("'{' para abrir o bloco da API '%s'", $name));

        $baseUrl = $this->baseDeclaration($name);

        $headers = [];
        while ($this->check(TokenType::Header)) {
            $headers[] = $this->headerDeclaration();
        }

        if (!$this->check(TokenType::Endpoint)) {
            throw $this->unexpected(sprintf(
                "'header' ou 'endpoint' no bloco da API '%s' (toda API deve declarar ao menos um endpoint)",
                $name,
            ));
        }

        $endpoints = [];
        do {
            $endpoints[] = $this->endpointDeclaration();
        } while ($this->check(TokenType::Endpoint));

        $this->expect(TokenType::RightBrace, sprintf("'endpoint' ou '}' para fechar o bloco da API '%s'", $name));

        return new Api($name, $baseUrl, $headers, $endpoints, $keyword->line, $keyword->column);
    }

    /**
     * decl_base = "base" , TEXTO , ";" ;
     *
     * O endereço-base é normalizado sem barra final, como exige o contrato da
     * AST (docs/ast-contract.md).
     *
     * @param string $apiName Nome da API, para as mensagens de erro.
     *
     * @return string Endereço-base sem barra final.
     */
    private function baseDeclaration(string $apiName): string
    {
        $this->expect(TokenType::Base, sprintf(
            "'base' como primeira declaração do bloco da API '%s' (endereço-base)",
            $apiName,
        ));
        $url = $this->stringValue($this->expect(TokenType::StringLiteral, "endereço-base entre aspas após 'base'"));
        $this->expectSemicolon("';' ao final da declaração 'base'");

        return rtrim($url, '/');
    }

    /**
     * decl_header = "header" , TEXTO , "=" , expressao , ";" ;
     *
     * @return Header
     */
    private function headerDeclaration(): Header
    {
        $keyword = $this->expect(TokenType::Header, "'header'");
        $name = $this->stringValue($this->expect(TokenType::StringLiteral, "nome do cabeçalho entre aspas após 'header'"));
        $this->expect(TokenType::Assign, sprintf("'=' após o nome do cabeçalho \"%s\"", $name));
        $value = $this->expression();
        $this->expectSemicolon(sprintf("';' ao final do cabeçalho \"%s\"", $name));

        return new Header($name, $value, $keyword->line, $keyword->column);
    }

    /**
     * decl_endpoint = "endpoint" , IDENT , metodo_http , TEXTO , ";" ;
     *
     * Restrição adicional sobre o literal: o caminho deve começar com "/"
     * (contrato da AST).
     *
     * @return Endpoint
     */
    private function endpointDeclaration(): Endpoint
    {
        $keyword = $this->expect(TokenType::Endpoint, "'endpoint'");
        $name = $this->expect(TokenType::Identifier, "nome do endpoint (identificador) após 'endpoint'")->lexeme;
        $method = $this->httpMethod($name);

        $pathToken = $this->expect(TokenType::StringLiteral, sprintf("caminho entre aspas do endpoint '%s'", $name));
        $path = $this->stringValue($pathToken);
        if (!str_starts_with($path, '/')) {
            throw SyntaxException::at(
                sprintf("o caminho do endpoint '%s' deve começar com '/', encontrado: %s", $name, $pathToken->describe()),
                $pathToken->line,
                $pathToken->column,
            );
        }

        $this->expectSemicolon(sprintf("';' ao final da declaração do endpoint '%s'", $name));

        return new Endpoint($name, $method, $path, $keyword->line, $keyword->column);
    }

    /**
     * metodo_http = "GET" | "POST" | "PUT" | "PATCH" | "DELETE" ;
     *
     * @param string $endpointName Nome do endpoint, para as mensagens de erro.
     *
     * @return HttpMethod
     */
    private function httpMethod(string $endpointName): HttpMethod
    {
        $method = match ($this->current()->type) {
            TokenType::Get => HttpMethod::Get,
            TokenType::Post => HttpMethod::Post,
            TokenType::Put => HttpMethod::Put,
            TokenType::Patch => HttpMethod::Patch,
            TokenType::Delete => HttpMethod::Delete,
            default => throw $this->unexpected(sprintf(
                "método HTTP (GET, POST, PUT, PATCH ou DELETE, em maiúsculas) no endpoint '%s'",
                $endpointName,
            )),
        };
        $this->advance();

        return $method;
    }

    /**
     * decl_integracao = "integration" , IDENT , "{" , clausula_from ,
     *                   clausula_to , [ clausula_when ] , [ bloco_map ] , "}" ;
     *
     * clausula_from = "from" , ref_endpoint , [ "as" , IDENT ] , ";" ;
     * clausula_to   = "to" , ref_endpoint , ";" ;
     * clausula_when = "when" , expressao , ";" ;
     *
     * @return Integration
     */
    private function integrationDeclaration(): Integration
    {
        $keyword = $this->expect(TokenType::Integration, "'integration'");
        $name = $this->expect(TokenType::Identifier, "nome da integração (identificador) após 'integration'")->lexeme;
        $this->expect(TokenType::LeftBrace, sprintf("'{' para abrir o bloco da integração '%s'", $name));

        // clausula_from
        $this->expect(TokenType::From, sprintf(
            "'from' como primeira cláusula da integração '%s' (endpoint de origem)",
            $name,
        ));
        $source = $this->endpointReference("'from'");
        $alias = null;
        if ($this->match(TokenType::As)) {
            $alias = $this->expect(TokenType::Identifier, "nome do registro de origem (identificador) após 'as'")->lexeme;
            $this->expectSemicolon("';' ao final da cláusula 'from'");
        } else {
            $this->expectSemicolon("'as' ou ';' ao final da cláusula 'from'");
        }

        // clausula_to
        $this->expect(TokenType::To, sprintf(
            "'to' após a cláusula 'from' da integração '%s' (endpoint de destino)",
            $name,
        ));
        $target = $this->endpointReference("'to'");
        $this->expectSemicolon("';' ao final da cláusula 'to'");

        // [ clausula_when ]
        $condition = null;
        if ($this->match(TokenType::When)) {
            $condition = $this->expression();
            $this->expectSemicolon("';' ao final da cláusula 'when'");
        }

        // [ bloco_map ]
        $mappings = [];
        if ($this->match(TokenType::Map)) {
            $mappings = $this->mapBlock();
        }

        // O conjunto de tokens aceitos aqui depende das cláusulas opcionais já
        // vistas (FIRST das cláusulas restantes ∪ {"}"}).
        $allowed = match (true) {
            $mappings !== [] => "'}'",
            $condition !== null => "'map' ou '}'",
            default => "'when', 'map' ou '}'",
        };
        $this->expect(TokenType::RightBrace, sprintf('%s para fechar o bloco da integração \'%s\'', $allowed, $name));

        return new Integration($name, $source, $alias, $target, $condition, $mappings, $keyword->line, $keyword->column);
    }

    /**
     * ref_endpoint = IDENT , "." , IDENT ;
     *
     * @param string $clause Cláusula onde a referência aparece, para as mensagens de erro.
     *
     * @return EndpointRef
     */
    private function endpointReference(string $clause): EndpointRef
    {
        $api = $this->expect(TokenType::Identifier, sprintf("nome da API na cláusula %s (formato Api.endpoint)", $clause));
        $this->expect(TokenType::Dot, sprintf("'.' entre a API e o endpoint na cláusula %s (formato Api.endpoint)", $clause));
        $endpoint = $this->expect(TokenType::Identifier, sprintf("nome do endpoint após '.' na cláusula %s", $clause));

        return new EndpointRef($api->lexeme, $endpoint->lexeme, $api->line, $api->column);
    }

    /**
     * bloco_map = "map" , "{" , mapeamento , { mapeamento } , "}" ;
     *
     * Pré-condição: a palavra reservada "map" já foi consumida.
     *
     * @return list<FieldMapping> Ao menos um mapeamento.
     */
    private function mapBlock(): array
    {
        $this->expect(TokenType::LeftBrace, "'{' após 'map'");

        if (!$this->check(TokenType::Identifier)) {
            throw $this->unexpected("nome de campo no bloco 'map' (o bloco deve conter ao menos um mapeamento)");
        }

        $mappings = [];
        do {
            $mappings[] = $this->fieldMapping();
        } while ($this->check(TokenType::Identifier));

        $this->expect(TokenType::RightBrace, "nome de campo ou '}' para fechar o bloco 'map'");

        return $mappings;
    }

    /**
     * mapeamento = IDENT , ":" , tipo , "=" , expressao , ";" ;
     *
     * @return FieldMapping
     */
    private function fieldMapping(): FieldMapping
    {
        $field = $this->expect(TokenType::Identifier, 'nome de campo');
        $this->expect(TokenType::Colon, sprintf("':' após o nome do campo '%s'", $field->lexeme));
        $dataType = $this->dataType($field->lexeme);
        $this->expect(TokenType::Assign, sprintf("'=' após o tipo do campo '%s'", $field->lexeme));
        $value = $this->expression();
        $this->expectSemicolon(sprintf("';' ao final do mapeamento do campo '%s'", $field->lexeme));

        return new FieldMapping($field->lexeme, $dataType, $value, $field->line, $field->column);
    }

    /**
     * tipo = "string" | "int" | "float" | "bool" ;
     *
     * @param string $fieldName Nome do campo, para as mensagens de erro.
     *
     * @return DataType
     */
    private function dataType(string $fieldName): DataType
    {
        $type = match ($this->current()->type) {
            TokenType::TypeString => DataType::String,
            TokenType::TypeInt => DataType::Int,
            TokenType::TypeFloat => DataType::Float,
            TokenType::TypeBool => DataType::Bool,
            default => throw $this->unexpected(sprintf(
                "tipo de dado (string, int, float ou bool) para o campo '%s'",
                $fieldName,
            )),
        };
        $this->advance();

        return $type;
    }

    // ---------------------------------------------------------------------
    // Expressões (um método por nível de precedência, do menor para o maior)
    // ---------------------------------------------------------------------

    /**
     * expressao = expr_ou ;
     *
     * @return Expression
     */
    private function expression(): Expression
    {
        return $this->orExpression();
    }

    /**
     * expr_ou = expr_e , { "||" , expr_e } ;   (associativo à esquerda)
     *
     * @return Expression
     */
    private function orExpression(): Expression
    {
        return $this->leftAssociative(
            fn (): Expression => $this->andExpression(),
            [TokenType::OrOr->name => BinaryOperator::Or],
        );
    }

    /**
     * expr_e = expr_igualdade , { "&&" , expr_igualdade } ;   (associativo à esquerda)
     *
     * @return Expression
     */
    private function andExpression(): Expression
    {
        return $this->leftAssociative(
            fn (): Expression => $this->equalityExpression(),
            [TokenType::AndAnd->name => BinaryOperator::And],
        );
    }

    /**
     * expr_igualdade = expr_relacional , [ ( "==" | "!=" ) , expr_relacional ] ;
     *
     * Não associativo: "a == b == c" é erro sintático (use parênteses).
     *
     * @return Expression
     */
    private function equalityExpression(): Expression
    {
        return $this->nonAssociative(
            fn (): Expression => $this->relationalExpression(),
            [
                TokenType::EqualEqual->name => BinaryOperator::Equal,
                TokenType::BangEqual->name => BinaryOperator::NotEqual,
            ],
            'de igualdade',
        );
    }

    /**
     * expr_relacional = expr_aditiva , [ ( "<" | "<=" | ">" | ">=" ) , expr_aditiva ] ;
     *
     * Não associativo: "a < b < c" é erro sintático (use parênteses).
     *
     * @return Expression
     */
    private function relationalExpression(): Expression
    {
        return $this->nonAssociative(
            fn (): Expression => $this->additiveExpression(),
            [
                TokenType::Less->name => BinaryOperator::Less,
                TokenType::LessEqual->name => BinaryOperator::LessEqual,
                TokenType::Greater->name => BinaryOperator::Greater,
                TokenType::GreaterEqual->name => BinaryOperator::GreaterEqual,
            ],
            'relacionais',
        );
    }

    /**
     * expr_aditiva = expr_multiplicativa , { ( "+" | "-" ) , expr_multiplicativa } ;
     * (associativo à esquerda)
     *
     * @return Expression
     */
    private function additiveExpression(): Expression
    {
        return $this->leftAssociative(
            fn (): Expression => $this->multiplicativeExpression(),
            [
                TokenType::Plus->name => BinaryOperator::Add,
                TokenType::Minus->name => BinaryOperator::Subtract,
            ],
        );
    }

    /**
     * expr_multiplicativa = expr_unaria , { ( "*" | "/" | "%" ) , expr_unaria } ;
     * (associativo à esquerda)
     *
     * @return Expression
     */
    private function multiplicativeExpression(): Expression
    {
        return $this->leftAssociative(
            fn (): Expression => $this->unaryExpression(),
            [
                TokenType::Star->name => BinaryOperator::Multiply,
                TokenType::Slash->name => BinaryOperator::Divide,
                TokenType::Percent->name => BinaryOperator::Modulo,
            ],
        );
    }

    /**
     * expr_unaria = ( "!" | "-" ) , expr_unaria | primario ;   (associativo à direita)
     *
     * @return Expression
     */
    private function unaryExpression(): Expression
    {
        $operator = match ($this->current()->type) {
            TokenType::Bang => UnaryOperator::Not,
            TokenType::Minus => UnaryOperator::Negate,
            default => null,
        };

        if ($operator === null) {
            return $this->primary();
        }

        $token = $this->advance();

        return new UnaryExpression($operator, $this->unaryExpression(), $token->line, $token->column);
    }

    /**
     * primario = literal
     *          | IDENT , chamada
     *          | IDENT , acesso
     *          | "(" , expressao , ")" ;
     *
     * literal = TEXTO | INTEIRO | DECIMAL | "true" | "false" | "null" ;
     *
     * @return Expression
     */
    private function primary(): Expression
    {
        $token = $this->current();

        switch ($token->type) {
            case TokenType::StringLiteral:
                $this->advance();

                return new Literal(LiteralType::String, $this->stringValue($token), $token->line, $token->column);

            case TokenType::IntLiteral:
                $this->advance();

                return new Literal(LiteralType::Int, $this->intValue($token), $token->line, $token->column);

            case TokenType::FloatLiteral:
                $this->advance();

                return new Literal(LiteralType::Float, $this->floatValue($token), $token->line, $token->column);

            case TokenType::True:
            case TokenType::False:
                $this->advance();

                return new Literal(LiteralType::Bool, $token->type === TokenType::True, $token->line, $token->column);

            case TokenType::Null:
                $this->advance();

                return new Literal(LiteralType::Null, null, $token->line, $token->column);

            case TokenType::Identifier:
                $this->advance();

                return $this->check(TokenType::LeftParen)
                    ? $this->functionCall($token)
                    : $this->fieldReference($token);

            case TokenType::LeftParen:
                $this->advance();
                $inner = $this->expression();
                $this->expect(TokenType::RightParen, sprintf(
                    "')' para fechar o '(' aberto na linha %d, coluna %d",
                    $token->line,
                    $token->column,
                ));

                return $inner;

            default:
                throw $this->unexpected(self::EXPRESSION_START);
        }
    }

    /**
     * chamada    = "(" , [ argumentos ] , ")" ;
     * argumentos = expressao , { "," , expressao } ;
     *
     * Pré-condição: o identificador (nome da função) já foi consumido e o
     * token atual é "(".
     *
     * @param Token $name Token do nome da função.
     *
     * @return FunctionCall
     */
    private function functionCall(Token $name): FunctionCall
    {
        $this->expect(TokenType::LeftParen, "'('");

        $arguments = [];
        if (!$this->check(TokenType::RightParen)) {
            do {
                $arguments[] = $this->expression();
            } while ($this->match(TokenType::Comma));
        }

        $this->expect(TokenType::RightParen, sprintf("',' ou ')' na chamada da função '%s'", $name->lexeme));

        return new FunctionCall($name->lexeme, $arguments, $name->line, $name->column);
    }

    /**
     * acesso     = { "." , nome_campo } ;
     * nome_campo = IDENT | palavra_reservada ;
     *
     * Após o ponto, palavras reservadas são aceitas como nomes de campo, pois
     * campos JSON como "from", "to" ou "type" são comuns nas APIs.
     *
     * Pré-condição: o primeiro identificador já foi consumido.
     *
     * @param Token $first Token do primeiro identificador do caminho.
     *
     * @return FieldReference
     */
    private function fieldReference(Token $first): FieldReference
    {
        $path = [$first->lexeme];

        while ($this->match(TokenType::Dot)) {
            $segment = $this->current();
            if ($segment->type !== TokenType::Identifier && !$segment->type->isReservedWord()) {
                throw $this->unexpected(sprintf("nome de campo após '%s.'", implode('.', $path)));
            }

            $path[] = $this->advance()->lexeme;
        }

        return new FieldReference($path, $first->line, $first->column);
    }

    /**
     * Reconhece um nível de precedência com operadores associativos à
     * esquerda: operando { operador operando }.
     *
     * A repetição EBNF substitui a recursão à esquerda da gramática original
     * (E → E op T | T); o laço monta a árvore à esquerda: (a op b) op c.
     *
     * @param callable(): Expression        $operand   Reconhecedor do nível de precedência imediatamente superior.
     * @param array<string, BinaryOperator> $operators Nome do caso de TokenType => operador da AST.
     *
     * @return Expression
     */
    private function leftAssociative(callable $operand, array $operators): Expression
    {
        $left = $operand();

        while (isset($operators[$this->current()->type->name])) {
            $token = $this->advance();
            $left = new BinaryExpression($operators[$token->type->name], $left, $operand(), $token->line, $token->column);
        }

        return $left;
    }

    /**
     * Reconhece um nível de precedência com operadores não associativos:
     * operando [ operador operando ].
     *
     * Se, após a operação, aparecer outro operador do mesmo nível, o erro é
     * relatado aqui com uma mensagem específica (pela GLC, esse token não
     * pertence ao FOLLOW do nível e seria rejeitado de qualquer forma).
     *
     * @param callable(): Expression        $operand   Reconhecedor do nível de precedência imediatamente superior.
     * @param array<string, BinaryOperator> $operators Nome do caso de TokenType => operador da AST.
     * @param string                        $kind      Nome do grupo de operadores, para a mensagem de erro.
     *
     * @return Expression
     */
    private function nonAssociative(callable $operand, array $operators, string $kind): Expression
    {
        $left = $operand();

        if (!isset($operators[$this->current()->type->name])) {
            return $left;
        }

        $token = $this->advance();
        $expression = new BinaryExpression($operators[$token->type->name], $left, $operand(), $token->line, $token->column);

        if (isset($operators[$this->current()->type->name])) {
            $extra = $this->current();
            throw SyntaxException::at(
                sprintf(
                    "operador '%s' inesperado: operadores %s não são associativos; use parênteses, por exemplo (a %s b) %s c",
                    $extra->lexeme,
                    $kind,
                    $token->lexeme,
                    $extra->lexeme,
                ),
                $extra->line,
                $extra->column,
            );
        }

        return $expression;
    }

    // ---------------------------------------------------------------------
    // Valores dos literais (contrato com o Lexer: Token::$value)
    // ---------------------------------------------------------------------

    /**
     * @param Token $token Token StringLiteral.
     *
     * @return string Conteúdo do literal, sem aspas.
     */
    private function stringValue(Token $token): string
    {
        if (!is_string($token->value)) {
            throw $this->contractViolation($token, 'string');
        }

        return $token->value;
    }

    /**
     * @param Token $token Token IntLiteral.
     *
     * @return int
     */
    private function intValue(Token $token): int
    {
        if (!is_int($token->value)) {
            throw $this->contractViolation($token, 'int');
        }

        return $token->value;
    }

    /**
     * @param Token $token Token FloatLiteral.
     *
     * @return float
     */
    private function floatValue(Token $token): float
    {
        if (!is_float($token->value) && !is_int($token->value)) {
            throw $this->contractViolation($token, 'float');
        }

        return (float) $token->value;
    }

    /**
     * Erro interno: o Lexer entregou um literal sem o valor semântico
     * combinado. Não é um erro do programa .dsl, e sim um defeito do
     * compilador; por isso não é uma SyntaxException.
     *
     * @param Token  $token        Token com valor ausente ou de tipo errado.
     * @param string $expectedType Tipo PHP esperado em Token::$value.
     *
     * @return LogicException
     */
    private function contractViolation(Token $token, string $expectedType): LogicException
    {
        return new LogicException(sprintf(
            'Token %s na linha %d, coluna %d sem valor semântico do tipo %s (ver contrato em src/Lexer/Token.php).',
            $token->type->value,
            $token->line,
            $token->column,
            $expectedType,
        ));
    }

    // ---------------------------------------------------------------------
    // Primitivas de leitura de tokens
    // ---------------------------------------------------------------------

    /**
     * Token atual (lookahead), sem consumi-lo.
     *
     * @return Token
     */
    private function current(): Token
    {
        return $this->tokens[$this->position];
    }

    /**
     * Indica se o token atual é do tipo informado.
     *
     * @param TokenType $type Tipo esperado.
     *
     * @return bool
     */
    private function check(TokenType $type): bool
    {
        return $this->current()->type === $type;
    }

    /**
     * Consome e devolve o token atual. Nunca avança além do EOF.
     *
     * @return Token
     */
    private function advance(): Token
    {
        $token = $this->current();
        if ($token->type !== TokenType::Eof) {
            $this->position++;
        }

        return $token;
    }

    /**
     * Consome o token atual se ele for do tipo informado.
     *
     * @param TokenType $type Tipo esperado.
     *
     * @return bool true se o token foi consumido.
     */
    private function match(TokenType $type): bool
    {
        if (!$this->check($type)) {
            return false;
        }

        $this->advance();

        return true;
    }

    /**
     * Consome o token atual se for do tipo informado; caso contrário, gera um
     * erro sintático na posição do token atual.
     *
     * @param TokenType $type     Tipo esperado.
     * @param string    $expected Descrição do que era esperado, para a mensagem.
     *
     * @return Token O token consumido.
     *
     * @throws SyntaxException
     */
    private function expect(TokenType $type, string $expected): Token
    {
        if ($this->check($type)) {
            return $this->advance();
        }

        throw $this->unexpected($expected);
    }

    /**
     * Como expect(TokenType::Semicolon, ...), mas, se o ";" faltar, o erro é
     * posicionado logo após o token anterior, ou seja, onde o ";" deveria
     * estar (normalmente no fim da linha), e não no início da linha seguinte.
     *
     * @param string $expected Descrição do que era esperado, para a mensagem.
     *
     * @return void
     *
     * @throws SyntaxException
     */
    private function expectSemicolon(string $expected): void
    {
        if ($this->match(TokenType::Semicolon)) {
            return;
        }

        $previous = $this->tokens[$this->position - 1];

        throw $this->unexpected(
            $expected,
            $previous->line,
            $previous->column + mb_strlen($previous->lexeme),
        );
    }

    /**
     * Cria o erro "esperado X, encontrado: Y" para o token atual.
     *
     * @param string   $expected Descrição do que era esperado.
     * @param int|null $line     Linha do erro (padrão: a do token atual).
     * @param int|null $column   Coluna do erro (padrão: a do token atual).
     *
     * @return SyntaxException
     */
    private function unexpected(string $expected, ?int $line = null, ?int $column = null): SyntaxException
    {
        $found = $this->current();

        return SyntaxException::at(
            sprintf('esperado %s, encontrado: %s', $expected, $found->describe()),
            $line ?? $found->line,
            $column ?? $found->column,
        );
    }

    /**
     * Recuperação de erros em modo pânico: descarta tokens até o início da
     * próxima declaração de nível superior ("api" ou "integration") ou até o
     * EOF. Como essas palavras reservadas só ocorrem no início de declarações,
     * são pontos de sincronização seguros.
     *
     * @return void
     */
    private function synchronize(): void
    {
        while (!$this->check(TokenType::Eof)
            && !$this->check(TokenType::Api)
            && !$this->check(TokenType::Integration)
        ) {
            $this->advance();
        }
    }
}
