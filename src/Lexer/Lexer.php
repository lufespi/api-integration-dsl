<?php

declare(strict_types=1);

namespace App\Lexer;

use App\Exceptions\LexicalException;

/**
 * Analisador léxico escrito à mão: o primeiro caractere decide o tipo do token.
 * Linha e coluna começam em 1 e contam caracteres UTF-8, não bytes.
 *
 * @author Comassetto
 * @since 2026-11-24
 */
final class Lexer
{
    /** Escapes aceitos em textos: caractere após a barra => valor. */
    private const ESCAPES = ['"' => '"', '\\' => '\\', 'n' => "\n", 't' => "\t"];

    /** @var list<string> */
    private readonly array $chars;

    /** @var array<string, TokenType> */
    private readonly array $reservedWords;

    private int $position = 0;

    private int $line = 1;

    private int $column = 1;

    /** @throws LexicalException se o texto não for UTF-8 válido */
    public function __construct(string $source)
    {
        if (str_starts_with($source, "\xEF\xBB\xBF")) {
            $source = substr($source, 3);
        }
        $source = str_replace("\r\n", "\n", $source);

        $this->assertUtf8($source);
        $this->chars = mb_str_split($source, 1, 'UTF-8');
        $this->reservedWords = TokenType::reservedWords();
    }

    /**
     * @return list<Token> terminada por exatamente um Eof
     *
     * @throws LexicalException no primeiro erro léxico
     */
    public function tokenize(): array
    {
        $this->position = 0;
        $this->line = 1;
        $this->column = 1;
        $tokens = [];

        while (true) {
            $this->skipWhitespaceAndComments();

            if ($this->isAtEnd()) {
                $tokens[] = new Token(TokenType::Eof, '', $this->line, $this->column);

                return $tokens;
            }

            $tokens[] = $this->nextToken();
        }
    }

    private function nextToken(): Token
    {
        $char = $this->peek();

        return match (true) {
            $this->isLetter($char) => $this->word(),
            $this->isDigit($char) => $this->number(),
            $char === '"' => $this->text(),
            default => $this->symbol(),
        };
    }

    /** [A-Za-z_][A-Za-z0-9_]*: palavra reservada ou identificador. */
    private function word(): Token
    {
        [$line, $column] = [$this->line, $this->column];

        $lexeme = '';
        while ($this->isLetterOrDigit($this->peek())) {
            $lexeme .= $this->advance();
        }

        return new Token($this->reservedWords[$lexeme] ?? TokenType::Identifier, $lexeme, $line, $column);
    }

    /** [0-9]+ ou [0-9]+\.[0-9]+, sem sinal. */
    private function number(): Token
    {
        [$line, $column] = [$this->line, $this->column];

        $lexeme = $this->digits();
        $isFloat = $this->startsFraction();
        if ($isFloat) {
            $lexeme .= $this->advance() . $this->digits();
        }

        // Número colado em letra ou em outra fração: 12abc, 1.2.3
        if ($this->isLetter($this->peek()) || $this->startsFraction()) {
            while ($this->isLetterOrDigit($this->peek()) || $this->startsFraction()) {
                $lexeme .= $this->advance();
            }

            throw new LexicalException(sprintf("número malformado '%s'", $lexeme), $line, $column);
        }

        if ($isFloat) {
            $value = (float) $lexeme;
            $fits = is_finite($value);
        } else {
            $value = (int) $lexeme;
            $digits = ltrim($lexeme, '0');
            $fits = (string) $value === ($digits === '' ? '0' : $digits);
        }

        if (!$fits) {
            throw new LexicalException(sprintf("número '%s' acima do limite aceito", $lexeme), $line, $column);
        }

        return new Token($isFloat ? TokenType::FloatLiteral : TokenType::IntLiteral, $lexeme, $line, $column, $value);
    }

    /** Texto em uma única linha. O lexema mantém aspas e escapes; o valor, não. */
    private function text(): Token
    {
        [$line, $column] = [$this->line, $this->column];

        $lexeme = $this->advance();
        $value = '';

        while (!$this->isAtEnd() && $this->peek() !== "\n") {
            $escapeColumn = $this->column;
            $char = $this->advance();
            $lexeme .= $char;

            if ($char === '"') {
                return new Token(TokenType::StringLiteral, $lexeme, $line, $column, $value);
            }

            if ($char !== '\\') {
                $value .= $char;
                continue;
            }

            if ($this->isAtEnd() || $this->peek() === "\n") {
                break;
            }

            $escaped = $this->advance();
            $lexeme .= $escaped;
            $value .= self::ESCAPES[$escaped] ?? throw new LexicalException(
                sprintf('sequência de escape inválida \'\\%s\' (use \\", \\\\, \\n ou \\t)', $escaped),
                $line,
                $escapeColumn,
            );
        }

        throw new LexicalException('texto não terminado: faltou fechar as aspas', $line, $column);
    }

    /** Operador ou delimitador, sempre o mais longo possível (">=" antes de ">"). */
    private function symbol(): Token
    {
        [$line, $column] = [$this->line, $this->column];

        $char = $this->peek();
        foreach ([$char . $this->peek(1), $char] as $lexeme) {
            $type = TokenType::tryFrom($lexeme);
            if ($type === null) {
                continue;
            }

            for ($i = mb_strlen($lexeme, 'UTF-8'); $i > 0; $i--) {
                $this->advance();
            }

            return new Token($type, $lexeme, $line, $column);
        }

        throw new LexicalException($this->describeInvalid($char), $line, $column);
    }

    private function describeInvalid(string $char): string
    {
        $hint = match ($char) {
            '&', '|' => sprintf(" (o operador lógico é '%s')", $char . $char),
            "'", '“', '”', '‘', '’' => ' (textos usam aspas duplas retas)',
            default => '',
        };

        // Caracteres invisíveis são mostrados pelo código.
        $shown = preg_match('/^[\p{C}\p{Z}]$/u', $char) === 1
            ? sprintf('U+%04X', mb_ord($char, 'UTF-8'))
            : sprintf("'%s'", $char);

        return sprintf('caractere inválido %s%s', $shown, $hint);
    }

    private function skipWhitespaceAndComments(): void
    {
        while (!$this->isAtEnd()) {
            $char = $this->peek();
            $next = $this->peek(1);

            if (in_array($char, [' ', "\t", "\n", "\r"], true)) {
                $this->advance();
            } elseif ($char === '#' || ($char === '/' && $next === '/')) {
                while (!$this->isAtEnd() && $this->peek() !== "\n") {
                    $this->advance();
                }
            } elseif ($char === '/' && $next === '*') {
                $this->skipBlockComment();
            } else {
                return;
            }
        }
    }

    private function skipBlockComment(): void
    {
        [$line, $column] = [$this->line, $this->column];

        $this->advance();
        $this->advance();

        while (!$this->isAtEnd()) {
            if ($this->advance() === '*' && $this->peek() === '/') {
                $this->advance();

                return;
            }
        }

        throw new LexicalException("comentário de bloco não terminado: faltou fechar com '*/'", $line, $column);
    }

    private function digits(): string
    {
        $digits = '';
        while ($this->isDigit($this->peek())) {
            $digits .= $this->advance();
        }

        return $digits;
    }

    /** Ponto seguido de dígito; sem o dígito, o ponto é o delimitador de "Api.endpoint". */
    private function startsFraction(): bool
    {
        return $this->peek() === '.' && $this->isDigit($this->peek(1));
    }

    /** @throws LexicalException na posição do primeiro byte inválido */
    private function assertUtf8(string $source): void
    {
        foreach (explode("\n", $source) as $index => $text) {
            if (mb_check_encoding($text, 'UTF-8')) {
                continue;
            }

            $valid = $text;
            while (!mb_check_encoding($valid, 'UTF-8')) {
                $valid = substr($valid, 0, -1);
            }

            throw new LexicalException('o arquivo não está em UTF-8', $index + 1, mb_strlen($valid, 'UTF-8') + 1);
        }
    }

    private function isAtEnd(): bool
    {
        return $this->position >= count($this->chars);
    }

    /** Caractere à frente sem consumir, ou '' depois do fim. */
    private function peek(int $offset = 0): string
    {
        return $this->chars[$this->position + $offset] ?? '';
    }

    private function advance(): string
    {
        $char = $this->chars[$this->position++];

        if ($char === "\n") {
            $this->line++;
            $this->column = 1;
        } else {
            $this->column++;
        }

        return $char;
    }

    private function isLetter(string $char): bool
    {
        return preg_match('/^[A-Za-z_]$/D', $char) === 1;
    }

    private function isDigit(string $char): bool
    {
        return preg_match('/^[0-9]$/D', $char) === 1;
    }

    private function isLetterOrDigit(string $char): bool
    {
        return $this->isLetter($char) || $this->isDigit($char);
    }
}
