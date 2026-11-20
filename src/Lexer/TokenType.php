<?php

declare(strict_types=1);

namespace App\Lexer;

/**
 * Categorias de token (terminais da gramática).
 *
 * Este enum é o contrato entre o Analisador Léxico, que produz os tokens, e o
 * Analisador Sintático, que os consome. Cada caso corresponde a um terminal da
 * GLC descrita em docs/grammar.md (seção 3).
 *
 * Palavras reservadas e métodos HTTP diferenciam maiúsculas de minúsculas:
 * "api" é palavra reservada, "Api" é identificador; "GET" é método HTTP,
 * "get" é identificador.
 */
enum TokenType: string
{
    // Palavras reservadas: estrutura da DSL.
    case Api = 'api';
    case Base = 'base';
    case Header = 'header';
    case Endpoint = 'endpoint';
    case Integration = 'integration';
    case From = 'from';
    case As = 'as';
    case To = 'to';
    case When = 'when';
    case Map = 'map';

    // Palavras reservadas: métodos HTTP.
    case Get = 'GET';
    case Post = 'POST';
    case Put = 'PUT';
    case Patch = 'PATCH';
    case Delete = 'DELETE';

    // Palavras reservadas: tipos de dados.
    case TypeString = 'string';
    case TypeInt = 'int';
    case TypeFloat = 'float';
    case TypeBool = 'bool';

    // Palavras reservadas: literais.
    case True = 'true';
    case False = 'false';
    case Null = 'null';

    // Identificadores e literais com valor.
    case Identifier = 'IDENTIFIER';
    case StringLiteral = 'STRING_LITERAL';
    case IntLiteral = 'INT_LITERAL';
    case FloatLiteral = 'FLOAT_LITERAL';

    // Operadores.
    case OrOr = '||';
    case AndAnd = '&&';
    case EqualEqual = '==';
    case BangEqual = '!=';
    case Less = '<';
    case LessEqual = '<=';
    case Greater = '>';
    case GreaterEqual = '>=';
    case Plus = '+';
    case Minus = '-';
    case Star = '*';
    case Slash = '/';
    case Percent = '%';
    case Bang = '!';
    case Assign = '=';

    // Delimitadores.
    case LeftBrace = '{';
    case RightBrace = '}';
    case LeftParen = '(';
    case RightParen = ')';
    case Dot = '.';
    case Comma = ',';
    case Semicolon = ';';
    case Colon = ':';

    // Fim da entrada.
    case Eof = 'EOF';

    /**
     * Tabela de palavras reservadas: lexema exato => tipo do token.
     *
     * Útil ao Lexer para distinguir palavras reservadas de identificadores
     * depois de reconhecer um lexema no formato [A-Za-z_][A-Za-z0-9_]*.
     *
     * @return array<string, self>
     */
    public static function reservedWords(): array
    {
        $words = [];
        foreach (self::cases() as $case) {
            if ($case->isReservedWord()) {
                $words[$case->value] = $case;
            }
        }

        return $words;
    }

    /**
     * Indica se o token é uma palavra reservada (estrutura, método HTTP, tipo
     * ou literal nomeado).
     *
     * @return bool
     */
    public function isReservedWord(): bool
    {
        return match ($this) {
            self::Api, self::Base, self::Header, self::Endpoint, self::Integration,
            self::From, self::As, self::To, self::When, self::Map,
            self::Get, self::Post, self::Put, self::Patch, self::Delete,
            self::TypeString, self::TypeInt, self::TypeFloat, self::TypeBool,
            self::True, self::False, self::Null => true,
            default => false,
        };
    }

    /**
     * Indica se o token carrega um valor semântico além do lexema
     * (identificadores e literais de texto/número).
     *
     * @return bool
     */
    public function isValued(): bool
    {
        return match ($this) {
            self::Identifier, self::StringLiteral, self::IntLiteral, self::FloatLiteral => true,
            default => false,
        };
    }

    /**
     * Descrição legível do terminal, usada nas mensagens de erro sintático
     * (ex.: "';'", "identificador", "fim do arquivo").
     *
     * @return string
     */
    public function description(): string
    {
        return match ($this) {
            self::Identifier => 'identificador',
            self::StringLiteral => 'texto entre aspas',
            self::IntLiteral => 'número inteiro',
            self::FloatLiteral => 'número decimal',
            self::Eof => 'fim do arquivo',
            default => sprintf("'%s'", $this->value),
        };
    }
}
