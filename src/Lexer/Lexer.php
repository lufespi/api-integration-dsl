<?php

declare(strict_types=1);

namespace App\Lexer;

use App\Exceptions\LexicalException;

/**
 * Analisador léxico: converte o texto-fonte em uma lista de tokens.
 *
 * Escrito à mão, lê um caractere por vez e decide o tipo do token pelo
 * primeiro caractere (letra, dígito, aspas ou símbolo). Espaços e comentários
 * são descartados. Especificação em docs/tokens.md.
 *
 * Linha e coluna começam em 1 e contam caracteres (UTF-8), não bytes.
 */
final class Lexer
{
    /** Sequências de escape aceitas em textos: caractere após a barra => valor. */
    private const ESCAPES = ['"' => '"', '\\' => '\\', 'n' => "\n", 't' => "\t"];

    /** @var list<string> Caracteres do fonte. */
    private readonly array $chars;

    /** @var array<string, TokenType> Palavras reservadas: lexema => tipo. */
    private readonly array $reservedWords;

    private int $position = 0;

    private int $line = 1;

    private int $column = 1;

    /**
     * @param string $source Programa na DSL, em UTF-8.
     *
     * @throws LexicalException se o texto não for UTF-8 válido.
     */
    public function __construct(string $source)
    {
        // Remove o BOM e normaliza o fim de linha do Windows.
        if (str_starts_with($source, "\xEF\xBB\xBF")) {
            $source = substr($source, 3);
        }
        $source = str_replace("\r\n", "\n", $source);

        $this->assertUtf8($source);
        $this->chars = mb_str_split($source, 1, 'UTF-8');
        $this->reservedWords = TokenType::reservedWords();
    }

    /**
     * Analisa o fonte inteiro.
     *
     * @return list<Token> Tokens em ordem, terminados por exatamente um Eof.
     *
     * @throws LexicalException no primeiro erro léxico encontrado.
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

    /**
     * Reconhece o próximo token a partir do caractere atual.
     *
     * @return Token
     */
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

    /**
     * Palavra reservada ou identificador: [A-Za-z_][A-Za-z0-9_]*
     *
     * @return Token
     */
    private function word(): Token
    {
        [$line, $column] = [$this->line, $this->column];

        $lexeme = '';
        while ($this->isLetterOrDigit($this->peek())) {
            $lexeme .= $this->advance();
        }

        return new Token($this->reservedWords[$lexeme] ?? TokenType::Identifier, $lexeme, $line, $column);
    }

    /**
     * Literal inteiro [0-9]+ ou decimal [0-9]+\.[0-9]+, sem sinal.
     *
     * @return Token
     */
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

    /**
     * Literal de texto entre aspas duplas, em uma única linha.
     *
     * O lexema mantém as aspas e os escapes originais; o valor, não.
     *
     * @return Token
     */
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

    /**
     * Operador ou delimitador, sempre o mais longo possível (">=" antes de ">").
     *
     * @return Token
     */
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

    /**
     * Descrição de um caractere que não inicia nenhum token, com dica nos
     * enganos comuns.
     *
     * @param string $char Caractere encontrado.
     *
     * @return string
     */
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

    /**
     * Descarta espaços, comentários de linha (# ou //) e de bloco.
     *
     * @return void
     */
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

    /**
     * Descarta um comentário de bloco (sem aninhamento).
     *
     * @return void
     */
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

    /**
     * Consome uma sequência de dígitos.
     *
     * @return string
     */
    private function digits(): string
    {
        $digits = '';
        while ($this->isDigit($this->peek())) {
            $digits .= $this->advance();
        }

        return $digits;
    }

    /**
     * Indica se o caractere atual é um ponto seguido de dígito. Sem dígito
     * depois, o ponto é o delimitador de "Api.endpoint".
     *
     * @return bool
     */
    private function startsFraction(): bool
    {
        return $this->peek() === '.' && $this->isDigit($this->peek(1));
    }

    /**
     * @param string $source Texto a verificar.
     *
     * @return void
     *
     * @throws LexicalException na posição do primeiro byte inválido.
     */
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

    /**
     * @return bool
     */
    private function isAtEnd(): bool
    {
        return $this->position >= count($this->chars);
    }

    /**
     * Caractere à frente sem consumir, ou '' depois do fim.
     *
     * @param int $offset Distância do caractere atual.
     *
     * @return string
     */
    private function peek(int $offset = 0): string
    {
        return $this->chars[$this->position + $offset] ?? '';
    }

    /**
     * Consome o caractere atual e atualiza linha e coluna.
     *
     * @return string
     */
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

    /**
     * @param string $char Caractere ou ''.
     *
     * @return bool
     */
    private function isLetter(string $char): bool
    {
        return preg_match('/^[A-Za-z_]$/D', $char) === 1;
    }

    /**
     * @param string $char Caractere ou ''.
     *
     * @return bool
     */
    private function isDigit(string $char): bool
    {
        return preg_match('/^[0-9]$/D', $char) === 1;
    }

    /**
     * @param string $char Caractere ou ''.
     *
     * @return bool
     */
    private function isLetterOrDigit(string $char): bool
    {
        return $this->isLetter($char) || $this->isDigit($char);
    }
}
