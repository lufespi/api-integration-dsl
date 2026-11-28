<?php

declare(strict_types=1);

namespace App\Lexer;

/** Terminais da gramática. Palavras reservadas diferenciam maiúsculas de minúsculas. */
enum TokenType: string
{
    // Palavras reservadas.
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

    // Métodos HTTP.
    case Get = 'GET';
    case Post = 'POST';
    case Put = 'PUT';
    case Patch = 'PATCH';
    case Delete = 'DELETE';

    // Tipos de dados.
    case TypeString = 'string';
    case TypeInt = 'int';
    case TypeFloat = 'float';
    case TypeBool = 'bool';

    // Literais nomeados.
    case True = 'true';
    case False = 'false';
    case Null = 'null';

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

    case Eof = 'EOF';

    /** @return array<string, self> lexema => tipo */
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

    /** Identificadores e literais de texto/número. */
    public function isValued(): bool
    {
        return match ($this) {
            self::Identifier, self::StringLiteral, self::IntLiteral, self::FloatLiteral => true,
            default => false,
        };
    }

    /** Descrição do terminal nas mensagens de erro, ex.: "';'", "fim do arquivo". */
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
