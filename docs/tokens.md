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

## 5. Pendências

| # | Questão |
|---|---|
| T1 | Lista final de palavras reservadas |
| T2 | Palavras reservadas diferenciam maiúsculas/minúsculas? |
| T3 | Métodos HTTP como palavras reservadas ou identificadores validados pelo Parser? |
| T4 | URLs como string comum ou token próprio? |
| T5 | Sintaxe de comentários |
| T6 | Literais numéricos/booleanos e operadores (dependem de P3/P4 em `ast-contract.md`) |
| T7 | Codificação: suporte a UTF-8 em strings e identificadores? |
