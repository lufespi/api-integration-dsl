# Tokens (escopo preliminar)

> **Status: EM DEFINIÇÃO.**
> A especificação dos tokens é responsabilidade do integrante do **Analisador
> Léxico**, em acordo com o responsável pelo **Analisador Sintático**.

## 1. Papel do Lexer

Receber o texto-fonte (`string`) e produzir uma sequência de tokens,
descartando espaços em branco e comentários, ou reportar um **erro léxico**
com linha e coluna.

## 2. Estrutura de um token (proposta)

| Campo | Exemplo | Descrição |
|---|---|---|
| `type` | `IDENTIFIER` | Categoria do token |
| `lexeme` | `listUsers` | Texto exato reconhecido |
| `line` | `3` | Linha inicial (a partir de 1) |
| `column` | `14` | Coluna inicial (a partir de 1) |

Opções de implementação (a decidir): classe `Token` com `enum TokenType`
(PHP 8.1+), ou array associativo.

Um token final `EOF` facilita o trabalho do Parser.

## 3. Categorias candidatas

| Categoria | Exemplos | Observação |
|---|---|---|
| Palavras reservadas | `api`, `endpoint`, `integration`, `from`, `to`, `base` | Dependem da gramática |
| Métodos HTTP | `GET`, `POST`, `PUT`, `PATCH`, `DELETE` | Palavra reservada ou identificador? |
| Identificadores | `Users`, `listUsers` | Regra proposta: `[A-Za-z_][A-Za-z0-9_]*` |
| Literais de texto | `"https://..."`, `"/users"` | Aspas duplas? Sequências de escape? |
| Literais numéricos | `10`, `3.5` | Necessários para tipos/condições |
| Literais booleanos | `true`, `false` | Necessários para tipos/condições |
| Operadores | `==`, `!=`, `<`, `>`, `<=`, `>=`, `&&`, `\|\|`, `!` | Dependem da decisão sobre operadores |
| Delimitadores | `{`, `}`, `(`, `)`, `.`, `,`, `;`, `:` | Dependem da gramática |
| Comentários | `# ...` ou `// ...` | Descartados pelo Lexer |

## 4. Erros léxicos esperados

- Caractere não reconhecido (ex.: `@` fora de uma string).
- String não terminada.
- Número malformado (ex.: `1.2.3`), se houver literais numéricos.

Cada erro deve informar linha e coluna. Exemplos ficam em
`examples/invalid/lexical/`.

## 5. Contrato com o Parser (implementado)

Para que o Analisador Sintático pudesse ser implementado e testado, a
estrutura do token e o conjunto de terminais exigidos pela gramática foram
fixados em código, **sem implementar o Lexer** (que continua a cargo do
responsável pelo Analisador Léxico):

- [`src/Lexer/Token.php`](../src/Lexer/Token.php): classe `readonly` com
  `type`, `lexeme`, `line`, `column` e **`value`** — o valor semântico dos
  literais (texto sem aspas e com escapes resolvidos; `int`; `float`), que o
  Parser usa para montar a AST;
- [`src/Lexer/TokenType.php`](../src/Lexer/TokenType.php): enum com um caso
  por terminal da gramática e `TokenType::reservedWords()`, tabela
  lexema → tipo das 22 palavras reservadas (útil para distinguir palavras
  reservadas de identificadores).

O que o Parser espera do Lexer:

1. Uma `list<Token>` terminada por **exatamente um** token `TokenType::Eof`.
2. Espaços e comentários já descartados.
3. Palavras reservadas sensíveis a maiúsculas (`api` ≠ `Api`; `GET` ≠ `get`).
4. Literais numéricos **sem sinal** (`-5` = operador `-` + literal `5`).
5. `value` preenchido em `StringLiteral` (string), `IntLiteral` (int) e
   `FloatLiteral` (float).

A lista completa de terminais e seu papel na gramática está em
[`grammar.md`](grammar.md), seção 3. O responsável pelo Lexer pode ajustar
estes arquivos, desde que combine a mudança com o Parser.

## 6. Pendências

| # | Questão |
|---|---|
| T1 | ~~Lista final de palavras reservadas~~ — definida pela gramática (G1): ver seção 5 e `TokenType::reservedWords()` |
| T2 | ~~Palavras reservadas diferenciam maiúsculas/minúsculas?~~ — sim (proposta da gramática) |
| T3 | ~~Métodos HTTP como palavras reservadas ou identificadores validados pelo Parser?~~ — palavras reservadas, um tipo de token por método |
| T4 | ~~URLs como string comum ou token próprio?~~ — string comum (`StringLiteral`); o Parser valida o `/` inicial dos caminhos |
| T5 | Sintaxe de comentários |
| T6 | ~~Literais numéricos/booleanos e operadores~~ — definidos pela gramática (G5/G6); ver `grammar.md`, seção 3 |
| T7 | Codificação: suporte a UTF-8 em strings e identificadores? |
