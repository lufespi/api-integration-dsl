<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Lexer\Token;
use App\Lexer\TokenType;
use RuntimeException;

/**
 * Tokenizador MÍNIMO de apoio aos testes do Parser.
 *
 * NÃO é o Analisador Léxico do compilador (App\Lexer\Lexer, a cargo do
 * responsável pelo Lexer). Existe apenas para que os testes do Parser possam
 * ser escritos com código-fonte legível e para validar os exemplos .dsl,
 * enquanto o Lexer não está pronto. Produz tokens no formato do contrato
 * (src/Lexer/Token.php) e não faz tratamento de erros léxicos além de lançar
 * RuntimeException para caracteres desconhecidos.
 *
 * Quando o Lexer estiver implementado, os testes podem passar a usá-lo e
 * esta classe pode ser removida.
 *
 * Reconhece: espaços, comentários de linha "//", identificadores e palavras
 * reservadas, textos entre aspas duplas (escapes \" \\ \n \t), inteiros,
 * decimais, operadores e delimitadores da DSL.
 */
final class SourceTokenizer
{
    /** Operadores e delimitadores de dois caracteres (testados antes dos de um). */
    private const TWO_CHAR = ['||', '&&', '==', '!=', '<=', '>='];

    /** Operadores e delimitadores de um caractere. */
    private const ONE_CHAR = ['<', '>', '+', '-', '*', '/', '%', '!', '=', '{', '}', '(', ')', '.', ',', ';', ':'];

    /**
     * Converte o texto-fonte em tokens, terminando com EOF.
     *
     * @param string $source Programa na DSL.
     *
     * @return list<Token>
     */
    public static function tokenize(string $source): array
    {
        $chars = mb_str_split($source);
        $count = count($chars);
        $tokens = [];
        $i = 0;
        $line = 1;
        $column = 1;

        while ($i < $count) {
            $char = $chars[$i];

            if ($char === "\n") {
                $i++;
                $line++;
                $column = 1;
                continue;
            }

            if (ctype_space($char)) {
                $i++;
                $column++;
                continue;
            }

            // Comentário de linha: descarta até o fim da linha.
            if ($char === '/' && ($chars[$i + 1] ?? '') === '/') {
                while ($i < $count && $chars[$i] !== "\n") {
                    $i++;
                }
                continue;
            }

            $startColumn = $column;

            // Identificadores e palavras reservadas.
            if (preg_match('/[A-Za-z_]/', $char) === 1) {
                $lexeme = '';
                while ($i < $count && preg_match('/[A-Za-z0-9_]/', $chars[$i]) === 1) {
                    $lexeme .= $chars[$i++];
                }
                $column += mb_strlen($lexeme);
                $type = TokenType::reservedWords()[$lexeme] ?? TokenType::Identifier;
                $tokens[] = new Token($type, $lexeme, $line, $startColumn);
                continue;
            }

            // Números inteiros e decimais.
            if (ctype_digit($char)) {
                $lexeme = '';
                while ($i < $count && ctype_digit($chars[$i])) {
                    $lexeme .= $chars[$i++];
                }
                $isFloat = ($chars[$i] ?? '') === '.' && ctype_digit($chars[$i + 1] ?? '');
                if ($isFloat) {
                    $lexeme .= $chars[$i++];
                    while ($i < $count && ctype_digit($chars[$i])) {
                        $lexeme .= $chars[$i++];
                    }
                }
                $column += mb_strlen($lexeme);
                $tokens[] = $isFloat
                    ? new Token(TokenType::FloatLiteral, $lexeme, $line, $startColumn, (float) $lexeme)
                    : new Token(TokenType::IntLiteral, $lexeme, $line, $startColumn, (int) $lexeme);
                continue;
            }

            // Textos entre aspas duplas.
            if ($char === '"') {
                $lexeme = '"';
                $value = '';
                $i++;
                while ($i < $count && $chars[$i] !== '"') {
                    if ($chars[$i] === "\n") {
                        throw new RuntimeException(sprintf('Texto não terminado na linha %d.', $line));
                    }
                    if ($chars[$i] === '\\' && $i + 1 < $count) {
                        $escape = $chars[$i + 1];
                        $lexeme .= '\\' . $escape;
                        $value .= match ($escape) {
                            'n' => "\n",
                            't' => "\t",
                            default => $escape,
                        };
                        $i += 2;
                        continue;
                    }
                    $lexeme .= $chars[$i];
                    $value .= $chars[$i++];
                }
                if ($i >= $count) {
                    throw new RuntimeException(sprintf('Texto não terminado na linha %d.', $line));
                }
                $lexeme .= '"';
                $i++;
                $column += mb_strlen($lexeme);
                $tokens[] = new Token(TokenType::StringLiteral, $lexeme, $line, $startColumn, $value);
                continue;
            }

            // Operadores e delimitadores.
            $pair = $char . ($chars[$i + 1] ?? '');
            $symbol = in_array($pair, self::TWO_CHAR, true) ? $pair : $char;
            if (in_array($symbol, self::TWO_CHAR, true) || in_array($symbol, self::ONE_CHAR, true)) {
                $i += mb_strlen($symbol);
                $column += mb_strlen($symbol);
                $tokens[] = new Token(TokenType::from($symbol), $symbol, $line, $startColumn);
                continue;
            }

            throw new RuntimeException(sprintf("Caractere '%s' não reconhecido na linha %d, coluna %d.", $char, $line, $column));
        }

        $tokens[] = new Token(TokenType::Eof, '', $line, $column);

        return $tokens;
    }
}
