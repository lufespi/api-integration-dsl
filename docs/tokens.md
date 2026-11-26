# Tokens e Analisador Léxico

> **Status: DEFINIDO E IMPLEMENTADO** em [`src/Lexer/Lexer.php`](../src/Lexer/Lexer.php).
> Responsável: integrante do **Analisador Léxico**. Os tipos de token
> ([`TokenType`](../src/Lexer/TokenType.php)) são o contrato com o
> **Analisador Sintático**; a gramática que os usa está em
> [`grammar.md`](grammar.md).

## 1. Papel do Lexer

Receber o texto-fonte (`string`) e produzir uma lista de tokens, descartando
espaços e comentários, ou lançar `LexicalException` com linha e coluna.

```php
$tokens = (new Lexer($source))->tokenize();   // list<Token>, termina com um Eof
```

## 2. Estrutura de um token

Classe [`App\Lexer\Token`](../src/Lexer/Token.php), imutável.

| Campo | Exemplo | Descrição |
|---|---|---|
| `type` | `TokenType::StringLiteral` | Categoria do token |
| `lexeme` | `"/users"` (com as aspas) | Texto exato reconhecido |
| `line` | `3` | Linha inicial, a partir de 1 |
| `column` | `14` | Coluna inicial, a partir de 1, em caracteres (não bytes) |
| `value` | `/users` | Valor semântico dos literais: `string`, `int` ou `float`. Nos demais tokens, `null` |

## 3. Categorias

| Categoria | Padrão | `TokenType` |
|---|---|---|
| Palavras reservadas (estrutura) | `api` `base` `header` `endpoint` `integration` `from` `as` `to` `when` `map` | `Api`, `Base`, `Header`, `Endpoint`, `Integration`, `From`, `As`, `To`, `When`, `Map` |
| Palavras reservadas (métodos HTTP) | `GET` `POST` `PUT` `PATCH` `DELETE` | `Get`, `Post`, `Put`, `Patch`, `Delete` |
| Palavras reservadas (tipos) | `string` `int` `float` `bool` | `TypeString`, `TypeInt`, `TypeFloat`, `TypeBool` |
| Palavras reservadas (literais) | `true` `false` `null` | `True`, `False`, `Null` |
| Identificador | `[A-Za-z_][A-Za-z0-9_]*` | `Identifier` |
| Literal de texto | `"([^"\\\n]\|\\["\\nt])*"` | `StringLiteral` |
| Literal inteiro | `[0-9]+` | `IntLiteral` |
| Literal decimal | `[0-9]+\.[0-9]+` | `FloatLiteral` |
| Operadores lógicos | `\|\|` `&&` `!` | `OrOr`, `AndAnd`, `Bang` |
| Operadores de igualdade e relacionais | `==` `!=` `<` `<=` `>` `>=` | `EqualEqual`, `BangEqual`, `Less`, `LessEqual`, `Greater`, `GreaterEqual` |
| Operadores aritméticos | `+` `-` `*` `/` `%` | `Plus`, `Minus`, `Star`, `Slash`, `Percent` |
| Atribuição | `=` | `Assign` |
| Delimitadores | `{` `}` `(` `)` `.` `,` `;` `:` | `LeftBrace`, `RightBrace`, `LeftParen`, `RightParen`, `Dot`, `Comma`, `Semicolon`, `Colon` |
| Fim do arquivo | — | `Eof` |

## 4. Regras

1. **Maiúsculas importam.** `api` é palavra reservada e `Api` é identificador;
   `GET` é método HTTP e `get` é identificador.
2. **Palavra inteira.** `apis` e `map_` são identificadores.
3. **Maior casamento.** `>=` é um token só, não `>` seguido de `=`.
4. **Números não têm sinal.** `-5` é o operador `-` seguido de `5`.
5. **Ponto.** Em `1.5` faz parte do número; em `Crm.listar` e `1.a` é o
   delimitador `Dot`.
6. **Textos** ficam em uma linha só, entre aspas duplas. Escapes: `\"`, `\\`,
   `\n` e `\t`. Aceitam acentos e qualquer caractere UTF-8.
7. **Identificadores** só usam letras sem acento, dígitos e `_`.
8. **Comentários**, descartados: `// até o fim da linha`, `# até o fim da linha`
   e `/* bloco, em várias linhas */` (sem aninhamento).
9. **Espaços**, descartados: espaço, tab e quebra de linha. CRLF conta como uma
   quebra só. O BOM no início do arquivo é ignorado.

O Lexer decide o tipo do token pelo primeiro caractere:

| Primeiro caractere | Reconhece | Método |
|---|---|---|
| Letra ou `_` | palavra reservada ou identificador | `word()` |
| Dígito | inteiro ou decimal | `number()` |
| `"` | texto | `text()` |
| Outro | operador, delimitador ou erro | `symbol()` |

## 5. Erros léxicos

Todos lançam [`LexicalException`](../src/Exceptions/LexicalException.php), no
formato:

```text
Erro léxico na linha <L>, coluna <C>: <motivo>
```

A análise para no primeiro erro léxico.

| Erro | Exemplo | Motivo exibido | Posição apontada |
|---|---|---|---|
| Caractere inválido | `Crm@criar` | `caractere inválido '@'` | o caractere |
| `&` ou `\|` sozinho | `a & b` | `caractere inválido '&' (o operador lógico é '&&')` | o caractere |
| Aspas simples ou curvas | `'x'` | `caractere inválido ''' (textos usam aspas duplas retas)` | o caractere |
| Caractere invisível | espaço não separável | `caractere inválido U+00A0` | o caractere |
| Texto não terminado | `"https://x;` | `texto não terminado: faltou fechar as aspas` | a aspa de abertura |
| Escape inválido | `"a\qb"` | `sequência de escape inválida '\q' (use \", \\, \n ou \t)` | a barra invertida |
| Número malformado | `1.2.3`, `12abc`, `1e5` | `número malformado '1.2.3'` | o início do número |
| Número grande demais | `9223372036854775808` | `número '9223372036854775808' acima do limite aceito` | o início do número |
| Comentário de bloco aberto | `/* sem fim` | `comentário de bloco não terminado: faltou fechar com '*/'` | o `/*` |
| Codificação | byte fora do UTF-8 | `o arquivo não está em UTF-8` | o byte |

Exemplos em `examples/invalid/lexical/`, cada um com a mensagem documentada em
um comentário `// Erro esperado:` conferido pelos testes:

| Arquivo | Erro demonstrado |
|---|---|
| `01-caractere-invalido.dsl` | `@` no lugar do ponto em `Crm.createContact` |
| `02-texto-nao-terminado.dsl` | URL sem as aspas de fechamento |
| `03-numero-malformado.dsl` | `10.5.3` |

## 6. Testes

[`tests/Unit/Lexer/LexerTest.php`](../tests/Unit/Lexer/LexerTest.php): todos os
terminais, valores, posições (LF e CRLF), cada erro da seção 5 e todos os
arquivos de `examples/`. Os testes do Parser também usam este Lexer.

## 7. Decisões

| # | Questão | Decisão |
|---|---|---|
| T1 | Palavras reservadas | As 22 da gramática (G1), em `TokenType::reservedWords()` |
| T2 | Maiúsculas e minúsculas | Diferencia |
| T3 | Métodos HTTP | Palavras reservadas, um tipo por método |
| T4 | URLs | Texto comum (`StringLiteral`); o Parser valida o `/` inicial dos caminhos |
| T5 | Comentários | `//`, `#` e `/* */` |
| T6 | Literais e operadores | Definidos pela gramática (G5/G6) |
| T7 | UTF-8 | Sim em textos e comentários; identificadores só ASCII |
