# Gramática e Analisador Sintático

> **Status: DEFINIDA E IMPLEMENTADA** em [`src/Parser/Parser.php`](../src/Parser/Parser.php).
> Responsável: integrante do **Analisador Sintático**. Os terminais (seção 3)
> são o contrato com o **Analisador Léxico** ([`tokens.md`](tokens.md)); a AST
> produzida (seção 10) é o contrato com o **Gerador**
> ([`ast-contract.md`](ast-contract.md)). Mudanças na gramática devem ser
> combinadas com ambos.

## Sumário

1. [Objetivo e escopo da linguagem](#1-objetivo-e-escopo-da-linguagem)
2. [Decisões de sintaxe](#2-decisões-de-sintaxe)
3. [Terminais](#3-terminais)
4. [Não terminais e símbolo inicial](#4-não-terminais-e-símbolo-inicial)
5. [Produções (EBNF)](#5-produções-ebnf)
6. [Precedência e associatividade dos operadores](#6-precedência-e-associatividade-dos-operadores)
7. [Técnica de análise](#7-técnica-de-análise)
8. [Gramática transformada (BNF, sem recursão à esquerda)](#8-gramática-transformada-bnf-sem-recursão-à-esquerda)
9. [Conjuntos FIRST e FOLLOW e verificação LL(1)](#9-conjuntos-first-e-follow-e-verificação-ll1)
10. [Construção da AST](#10-construção-da-ast)
11. [Exemplos de derivação](#11-exemplos-de-derivação)
12. [Erros sintáticos](#12-erros-sintáticos)
13. [Atendimento aos requisitos do trabalho](#13-atendimento-aos-requisitos-do-trabalho)
14. [Pendências](#14-pendências)

---

## 1. Objetivo e escopo da linguagem

A DSL descreve **integrações entre APIs HTTP**. Um programa declara:

1. **APIs**: identificador, endereço-base, cabeçalhos e endpoints;
2. **endpoints**: identificador, método HTTP e caminho;
3. **integrações**: endpoint de origem → endpoint de destino, com
   - **condição** opcional (`when`): só encaminha registros que a satisfaçam;
   - **mapeamento** opcional (`map`): monta o registro de destino campo a
     campo, com **tipo de dado** declarado e valor calculado por expressão.

Exemplo completo (ver também [`examples/valid/`](../examples/valid/)):

```text
// Comentário de linha (descartado pelo Lexer)
api Crm {
    base "https://crm.example.com/v1";
    header "Authorization" = "Bearer " + env("CRM_TOKEN");
    endpoint listContacts GET "/contacts";
}

api Mailer {
    base "https://mailer.example.com/api";
    endpoint upsertSubscriber PUT "/subscribers";
}

integration sincronizarAssinantes {
    from Crm.listContacts as contato;
    to Mailer.upsertSubscriber;
    when contato.email != null && contato.optIn;
    map {
        email: string = contato.email;
        fullName: string = contato.firstName + " " + contato.lastName;
        age: int = 2026 - contato.birthYear;
        active: bool = !contato.archived;
    }
}
```

## 2. Decisões de sintaxe

Resolução das pendências da versão preliminar deste documento:

| # | Questão | Decisão | Justificativa |
|---|---|---|---|
| G1 | Palavras-chave | `api`, `base`, `header`, `endpoint`, `integration`, `from`, `as`, `to`, `when`, `map`; métodos `GET`, `POST`, `PUT`, `PATCH`, `DELETE`; tipos `string`, `int`, `float`, `bool`; literais `true`, `false`, `null` (22 no total, ver seção 3) | Vocabulário do domínio HTTP; poucas palavras, todas com papel sintático próprio |
| G2 | Delimitação de blocos | Chaves `{ }` | Familiar (C, PHP, JSON) e não depende de indentação, que complicaria o Lexer |
| G3 | Separador de declarações | `;` **terminador** obrigatório em toda declaração simples | Torna o fim de cada declaração explícito, permite expressões em várias linhas e dá pontos claros para mensagens de erro |
| G4 | Referência a endpoints | `Api.endpoint` | Lê-se naturalmente e evita ambiguidade entre endpoints homônimos de APIs diferentes |
| G5 | Tipos de dados | (a) **literais** tipados: texto, inteiro, decimal, booleano, nulo; (b) **tipos declarados** `string`, `int`, `float`, `bool` em cada campo do bloco `map` | Atende ao requisito "pelo menos dois tipos de dados" em dois níveis: valores e declarações |
| G6 | Operadores | lógicos `\|\|` `&&` `!`; igualdade `==` `!=`; relacionais `<` `<=` `>` `>=`; aritméticos `+` `-` `*` `/` `%`; menos unário `-` | Ver precedência e associatividade na seção 6 |
| G7 | Estrutura condicional | Cláusula `when <expressão>;` dentro de `integration` | Decide, para cada registro, se ele é encaminhado; é a estrutura de controle natural do domínio (filtro) |
| G8 | Mapeamento, cabeçalhos e autenticação | **Sim**: `header "Nome" = expressão;` na API e bloco `map { campo: tipo = expressão; }` na integração. Autenticação é feita por cabeçalho, com segredos lidos por `env("...")` | Evita segredos no código-fonte e dá uso real a tipos e expressões |
| G9 | Ordem das declarações | **Livre**: APIs e integrações podem aparecer em qualquer ordem e intercaladas | Referências são resolvidas depois (verificação semântica), então a ordem não precisa ser imposta pela gramática |

Outras decisões:

- **Ordem fixa dentro dos blocos**: em `api`, `base` vem primeiro, depois os
  `header` e por fim os `endpoint`; em `integration`, `from`, `to`, `when`
  (opcional) e `map` (opcional), nessa ordem. Isso garante, já na gramática,
  que `base`, `from` e `to` aparecem **exatamente uma vez**.
- **Mínimos**: o programa tem ao menos uma declaração; toda API tem ao menos
  um endpoint; todo bloco `map` tem ao menos um mapeamento.
- **Sensibilidade a maiúsculas**: palavras reservadas são exatas (`api` é
  palavra reservada; `Api` é identificador). Métodos HTTP só em maiúsculas.
- **Palavras reservadas como nomes de campo**: após `.` em uma referência,
  qualquer palavra reservada é aceita como nome de campo (ex.:
  `pedido.from`, `item.type`), porque campos JSON com esses nomes são comuns.
- **Comentários** são descartados pelo Lexer e não aparecem na gramática. Os
  exemplos usam `// ...` (sintaxe final a cargo do Lexer, pendência T5).

## 3. Terminais

Cada terminal corresponde a um caso do enum
[`App\Lexer\TokenType`](../src/Lexer/TokenType.php), que é o contrato com o
Lexer.

| Grupo | Terminais (como aparecem na gramática) | Caso em `TokenType` |
|---|---|---|
| Palavras reservadas estruturais | `api` `base` `header` `endpoint` `integration` `from` `as` `to` `when` `map` | `Api`, `Base`, `Header`, `Endpoint`, `Integration`, `From`, `As`, `To`, `When`, `Map` |
| Métodos HTTP | `GET` `POST` `PUT` `PATCH` `DELETE` | `Get`, `Post`, `Put`, `Patch`, `Delete` |
| Tipos de dados | `string` `int` `float` `bool` | `TypeString`, `TypeInt`, `TypeFloat`, `TypeBool` |
| Literais nomeados | `true` `false` `null` | `True`, `False`, `Null` |
| Identificador | `IDENT` — `[A-Za-z_][A-Za-z0-9_]*`, exceto palavras reservadas | `Identifier` |
| Literal de texto | `TEXTO` — entre aspas duplas | `StringLiteral` |
| Literal inteiro | `INTEIRO` — ex.: `18` | `IntLiteral` |
| Literal decimal | `DECIMAL` — ex.: `99.5` | `FloatLiteral` |
| Operadores lógicos | `\|\|` `&&` `!` | `OrOr`, `AndAnd`, `Bang` |
| Operadores de igualdade | `==` `!=` | `EqualEqual`, `BangEqual` |
| Operadores relacionais | `<` `<=` `>` `>=` | `Less`, `LessEqual`, `Greater`, `GreaterEqual` |
| Operadores aritméticos | `+` `-` `*` `/` `%` | `Plus`, `Minus`, `Star`, `Slash`, `Percent` |
| Atribuição | `=` | `Assign` |
| Delimitadores | `{` `}` `(` `)` `.` `,` `;` `:` | `LeftBrace`, `RightBrace`, `LeftParen`, `RightParen`, `Dot`, `Comma`, `Semicolon`, `Colon` |
| Fim da entrada | `EOF` (às vezes escrito `$`) | `Eof` |

Literais numéricos não têm sinal: `-5` é o operador unário `-` aplicado a `5`.

## 4. Não terminais e símbolo inicial

**Símbolo inicial:** `programa`.

| Não terminal | Reconhece | Método em `Parser` |
|---|---|---|
| `programa` | arquivo inteiro | `parse()` |
| `declaracao` | uma declaração de nível superior | `declaration()` |
| `decl_api` | bloco `api` | `apiDeclaration()` |
| `decl_base` | `base "url";` | `baseDeclaration()` |
| `decl_header` | `header "Nome" = expr;` | `headerDeclaration()` |
| `decl_endpoint` | `endpoint nome METODO "/caminho";` | `endpointDeclaration()` |
| `metodo_http` | `GET` … `DELETE` | `httpMethod()` |
| `decl_integracao` | bloco `integration` | `integrationDeclaration()` |
| `clausula_from`, `clausula_to`, `clausula_when` | cláusulas da integração | `integrationDeclaration()` |
| `ref_endpoint` | `Api.endpoint` | `endpointReference()` |
| `bloco_map` | `map { ... }` | `mapBlock()` |
| `mapeamento` | `campo: tipo = expr;` | `fieldMapping()` |
| `tipo` | `string` `int` `float` `bool` | `dataType()` |
| `expressao` | expressão completa | `expression()` |
| `expr_ou` … `expr_unaria` | um nível de precedência cada | `orExpression()` … `unaryExpression()` |
| `primario`, `literal` | operandos | `primary()` |
| `chamada`, `argumentos` | `f(a, b)` | `functionCall()` |
| `acesso`, `nome_campo` | `.campo.subcampo` | `fieldReference()` |

## 5. Produções (EBNF)

Notação ISO/IEC 14977: `,` concatenação, `|` alternativa, `[ x ]` opcional,
`{ x }` zero ou mais repetições, `"x"` terminal literal, `;` fim da regra.

```ebnf
(* ---------- Estrutura do programa ---------- *)
programa         = declaracao , { declaracao } , EOF ;
declaracao       = decl_api | decl_integracao ;

(* ---------- APIs ---------- *)
decl_api         = "api" , IDENT , "{" ,
                       decl_base ,
                       { decl_header } ,
                       decl_endpoint , { decl_endpoint } ,
                   "}" ;
decl_base        = "base" , TEXTO , ";" ;
decl_header      = "header" , TEXTO , "=" , expressao , ";" ;
decl_endpoint    = "endpoint" , IDENT , metodo_http , TEXTO , ";" ;
metodo_http      = "GET" | "POST" | "PUT" | "PATCH" | "DELETE" ;

(* ---------- Integrações ---------- *)
decl_integracao  = "integration" , IDENT , "{" ,
                       clausula_from ,
                       clausula_to ,
                       [ clausula_when ] ,
                       [ bloco_map ] ,
                   "}" ;
clausula_from    = "from" , ref_endpoint , [ "as" , IDENT ] , ";" ;
clausula_to      = "to" , ref_endpoint , ";" ;
clausula_when    = "when" , expressao , ";" ;
ref_endpoint     = IDENT , "." , IDENT ;

bloco_map        = "map" , "{" , mapeamento , { mapeamento } , "}" ;
mapeamento       = IDENT , ":" , tipo , "=" , expressao , ";" ;
tipo             = "string" | "int" | "float" | "bool" ;

(* ---------- Expressões (da menor para a maior precedência) ---------- *)
expressao           = expr_ou ;
expr_ou             = expr_e , { "||" , expr_e } ;
expr_e              = expr_igualdade , { "&&" , expr_igualdade } ;
expr_igualdade      = expr_relacional , [ ( "==" | "!=" ) , expr_relacional ] ;
expr_relacional     = expr_aditiva , [ ( "<" | "<=" | ">" | ">=" ) , expr_aditiva ] ;
expr_aditiva        = expr_multiplicativa , { ( "+" | "-" ) , expr_multiplicativa } ;
expr_multiplicativa = expr_unaria , { ( "*" | "/" | "%" ) , expr_unaria } ;
expr_unaria         = ( "!" | "-" ) , expr_unaria
                    | primario ;
primario            = literal
                    | IDENT , chamada
                    | IDENT , acesso
                    | "(" , expressao , ")" ;
chamada             = "(" , [ argumentos ] , ")" ;
argumentos          = expressao , { "," , expressao } ;
acesso              = { "." , nome_campo } ;
nome_campo          = IDENT | palavra_reservada ;
literal             = TEXTO | INTEIRO | DECIMAL | "true" | "false" | "null" ;

(* palavra_reservada: qualquer um dos 22 terminais do grupo G1 (seção 3). *)
```

**Restrição sobre literal** (verificada pelo Parser, fora da GLC): o `TEXTO`
do caminho em `decl_endpoint` deve começar com `/`. O endereço-base é
normalizado sem barra final. Ambas as regras vêm do contrato da AST.

## 6. Precedência e associatividade dos operadores

Da **menor** para a **maior** precedência. Cada nível é um não terminal
próprio, o que codifica a precedência na própria gramática (sem tabelas
externas nem ambiguidade).

| Nível | Operadores | Não terminal | Associatividade | Exemplo → agrupamento |
|---|---|---|---|---|
| 1 | `\|\|` | `expr_ou` | esquerda | `a \|\| b \|\| c` → `(a \|\| b) \|\| c` |
| 2 | `&&` | `expr_e` | esquerda | `a \|\| b && c` → `a \|\| (b && c)` |
| 3 | `==` `!=` | `expr_igualdade` | **não associativo** | `a == b == c` → **erro** |
| 4 | `<` `<=` `>` `>=` | `expr_relacional` | **não associativo** | `18 <= x < 65` → **erro** |
| 5 | `+` `-` | `expr_aditiva` | esquerda | `a - b - c` → `(a - b) - c` |
| 6 | `*` `/` `%` | `expr_multiplicativa` | esquerda | `a + b * c` → `a + (b * c)` |
| 7 | `!` `-` (unários) | `expr_unaria` | direita | `!!a` → `!(!a)`; `-a * b` → `(-a) * b` |
| 8 | `( )`, chamada, `.` | `primario` | — | `(a + b) * c` |

**Por que comparações não associativas?** Em `18 <= idade < 65`, a leitura
associativa à esquerda seria `(18 <= idade) < 65`, isto é, comparar um
booleano com 65 — quase sempre um engano. A gramática simplesmente não gera
essa cadeia (o `[ ... ]` permite no máximo um operador por nível), e o Parser
emite uma mensagem específica sugerindo parênteses. A forma correta é
`idade >= 18 && idade < 65`.

**Semântica de `+`** (para o Gerador): soma numérica; quando ao menos um
operando é texto, concatenação.

## 7. Técnica de análise

**Análise descendente recursiva preditiva, LL(1), escrita à mão** (decisão
D2 de [`architecture.md`](architecture.md): sem bibliotecas de *parsing*).

- **Descendente**: a árvore é construída da raiz (`programa`) para as folhas.
- **Recursiva**: cada não terminal é um método; uma produção que usa outro não
  terminal chama o método correspondente (tabela da seção 4).
- **Preditiva, LL(1)**: a produção a aplicar é escolhida olhando **um único
  token** de lookahead (`current()`), sem retrocesso (*backtracking*).
  Repetições `{ }` viram laços `while`; opcionais `[ ]` viram `if`.

Justificativa da escolha:

1. A linguagem é pequena e tem palavras reservadas que iniciam cada
   construção (`api`, `integration`, `from`, `when`, ...), o que a torna
   naturalmente LL(1).
2. O código do Parser espelha a gramática um-para-um: é fácil de ler, de
   conferir com este documento e de explicar na apresentação.
3. Permite mensagens de erro precisas e contextuais ("esperado `'to'` após a
   cláusula `'from'` da integração `'x'`"), difíceis de obter com geradores
   LALR.
4. Não exige ferramentas externas nem etapa de geração de código.

Requisitos da técnica: a gramática **não pode ter recursão à esquerda** (o
método entraria em recursão infinita) e, em cada ponto de escolha, os
conjuntos FIRST das alternativas devem ser **disjuntos** (seções 8 e 9).

## 8. Gramática transformada (BNF, sem recursão à esquerda)

A forma "natural" de escrever expressões com associatividade à esquerda é
**recursiva à esquerda**, por exemplo:

```text
ExprAditiva → ExprAditiva + ExprMult | ExprAditiva - ExprMult | ExprMult
```

Um analisador descendente não pode usá-la: `ExprAditiva()` chamaria
`ExprAditiva()` sem consumir nenhum token. Aplica-se a transformação padrão
de eliminação da recursão à esquerda imediata:

```text
A → A α | β        ⟹        A  → β A'
                             A' → α A' | ε
```

Resultado: `ExprAditiva → ExprMult RestoAditiva` e
`RestoAditiva → + ExprMult RestoAditiva | - ExprMult RestoAditiva | ε`.
A EBNF da seção 5 já está nessa forma: `{ ... }` equivale à cauda `A'`.
A recursão à direita da cauda produziria uma árvore inclinada à direita;
por isso o Parser implementa a cauda como **laço**, acumulando o operando
esquerdo (`$left = new BinaryExpression(op, $left, $right)`), o que constrói
a AST com a associatividade à esquerda correta.

A gramática completa em BNF pura (ε = cadeia vazia), usada para os cálculos
da seção 9:

```text
 1  Programa          → Declaracao ListaDecl EOF
 2  ListaDecl         → Declaracao ListaDecl | ε
 3  Declaracao        → DeclApi | DeclIntegracao
 4  DeclApi           → api IDENT { DeclBase ListaHeaders DeclEndpoint ListaEndpoints }
 5  DeclBase          → base TEXTO ;
 6  ListaHeaders      → DeclHeader ListaHeaders | ε
 7  DeclHeader        → header TEXTO = Expressao ;
 8  ListaEndpoints    → DeclEndpoint ListaEndpoints | ε
 9  DeclEndpoint      → endpoint IDENT MetodoHttp TEXTO ;
10  MetodoHttp        → GET | POST | PUT | PATCH | DELETE
11  DeclIntegracao    → integration IDENT { ClausulaFrom ClausulaTo WhenOpc MapOpc }
12  ClausulaFrom      → from RefEndpoint AliasOpc ;
13  AliasOpc          → as IDENT | ε
14  ClausulaTo        → to RefEndpoint ;
15  WhenOpc           → when Expressao ; | ε
16  MapOpc            → map { Mapeamento ListaMap } | ε
17  ListaMap          → Mapeamento ListaMap | ε
18  Mapeamento        → IDENT : Tipo = Expressao ;
19  Tipo              → string | int | float | bool
20  RefEndpoint       → IDENT . IDENT
21  Expressao         → ExprE RestoOu
22  RestoOu           → || ExprE RestoOu | ε
23  ExprE             → ExprIgual RestoE
24  RestoE            → && ExprIgual RestoE | ε
25  ExprIgual         → ExprRel RestoIgual
26  RestoIgual        → == ExprRel | != ExprRel | ε
27  ExprRel           → ExprAdit RestoRel
28  RestoRel          → < ExprAdit | <= ExprAdit | > ExprAdit | >= ExprAdit | ε
29  ExprAdit          → ExprMult RestoAdit
30  RestoAdit         → + ExprMult RestoAdit | - ExprMult RestoAdit | ε
31  ExprMult          → ExprUnaria RestoMult
32  RestoMult         → * ExprUnaria RestoMult | / ExprUnaria RestoMult | % ExprUnaria RestoMult | ε
33  ExprUnaria        → ! ExprUnaria | - ExprUnaria | Primario
34  Primario          → Literal | IDENT SufixoIdent | ( Expressao )
35  SufixoIdent       → ( ArgsOpc ) | Acesso
36  Acesso            → . NomeCampo Acesso | ε
37  NomeCampo         → IDENT | PalavraReservada
38  ArgsOpc           → Expressao ListaArgs | ε
39  ListaArgs         → , Expressao ListaArgs | ε
40  Literal           → TEXTO | INTEIRO | DECIMAL | true | false | null
```

Observe que `RestoIgual` e `RestoRel` **não** são recursivos: é isso que
torna `==`/`!=` e os relacionais não associativos.

## 9. Conjuntos FIRST e FOLLOW e verificação LL(1)

Abreviação: **E** = { `!`, `-`, `(`, `IDENT`, `TEXTO`, `INTEIRO`, `DECIMAL`,
`true`, `false`, `null` } (início de qualquer expressão).

### 9.1 FIRST

| Não terminal | FIRST |
|---|---|
| Programa, ListaDecl\*, Declaracao | { `api`, `integration` } (\* e ε) |
| DeclApi / DeclIntegracao | { `api` } / { `integration` } |
| ListaHeaders | { `header`, ε } |
| ListaEndpoints | { `endpoint`, ε } |
| AliasOpc | { `as`, ε } |
| WhenOpc | { `when`, ε } |
| MapOpc | { `map`, ε } |
| ListaMap | { `IDENT`, ε } |
| Expressao, ExprE, ExprIgual, ExprRel, ExprAdit, ExprMult, ExprUnaria | **E** |
| Primario | { `(`, `IDENT`, `TEXTO`, `INTEIRO`, `DECIMAL`, `true`, `false`, `null` } |
| RestoOu / RestoE | { `\|\|`, ε } / { `&&`, ε } |
| RestoIgual | { `==`, `!=`, ε } |
| RestoRel | { `<`, `<=`, `>`, `>=`, ε } |
| RestoAdit / RestoMult | { `+`, `-`, ε } / { `*`, `/`, `%`, ε } |
| SufixoIdent / Acesso | { `(`, `.`, ε } / { `.`, ε } |
| ArgsOpc / ListaArgs | E ∪ { ε } / { `,`, ε } |

### 9.2 FOLLOW (dos não terminais anuláveis e das expressões)

`Expressao` aparece antes de `;` (header, when, mapeamento), de `)`
(parênteses e chamada) e de `,` (argumentos). Propagando pelos níveis:

| Não terminal | FOLLOW |
|---|---|
| ListaDecl | { `EOF` } |
| ListaHeaders | { `endpoint` } |
| ListaEndpoints | { `}` } |
| AliasOpc | { `;` } |
| WhenOpc | { `map`, `}` } |
| MapOpc, ListaMap | { `}` } |
| Expressao, RestoOu | F₁ = { `;`, `)`, `,` } |
| ExprE, RestoE | F₂ = F₁ ∪ { `\|\|` } |
| ExprIgual, RestoIgual | F₃ = F₂ ∪ { `&&` } |
| ExprRel, RestoRel | F₄ = F₃ ∪ { `==`, `!=` } |
| ExprAdit, RestoAdit | F₅ = F₄ ∪ { `<`, `<=`, `>`, `>=` } |
| ExprMult, RestoMult | F₆ = F₅ ∪ { `+`, `-` } |
| ExprUnaria, Primario, SufixoIdent, Acesso | F₇ = F₆ ∪ { `*`, `/`, `%` } |
| ArgsOpc, ListaArgs | { `)` } |

### 9.3 Verificação das condições LL(1)

Uma gramática é LL(1) se, para toda regra `A → α | β`:
(1) FIRST(α) ∩ FIRST(β) = ∅; e (2) se β ⇒* ε, então FIRST(α) ∩ FOLLOW(A) = ∅.

**Condição 1 — alternativas com FIRST disjuntos:**

| Regra | Alternativas | Interseção |
|---|---|---|
| Declaracao | { `api` } × { `integration` } | ∅ ✔ |
| MetodoHttp, Tipo, Literal | terminais distintos | ∅ ✔ |
| ExprUnaria | { `!` } × { `-` } × FIRST(Primario) | ∅ ✔ (literais não têm sinal) |
| Primario | FIRST(Literal) × { `IDENT` } × { `(` } | ∅ ✔ |
| SufixoIdent | { `(` } × FIRST(Acesso) = { `.`, ε } | ∅ ✔ |
| NomeCampo | { `IDENT` } × palavras reservadas | ∅ ✔ |
| Restos de operadores | operadores distintos entre si | ∅ ✔ |

**Condição 2 — anuláveis: FIRST ∩ FOLLOW = ∅:**

| Não terminal anulável | FIRST \ {ε} | FOLLOW | Interseção |
|---|---|---|---|
| ListaDecl | `api` `integration` | `EOF` | ∅ ✔ |
| ListaHeaders | `header` | `endpoint` | ∅ ✔ |
| ListaEndpoints | `endpoint` | `}` | ∅ ✔ |
| AliasOpc | `as` | `;` | ∅ ✔ |
| WhenOpc | `when` | `map` `}` | ∅ ✔ |
| MapOpc / ListaMap | `map` / `IDENT` | `}` | ∅ ✔ |
| RestoOu | `\|\|` | F₁ | ∅ ✔ |
| RestoE | `&&` | F₂ | ∅ ✔ |
| RestoIgual | `==` `!=` | F₃ | ∅ ✔ |
| RestoRel | `<` `<=` `>` `>=` | F₄ | ∅ ✔ |
| RestoAdit | `+` `-` | F₅ | ∅ ✔ |
| RestoMult | `*` `/` `%` | F₆ | ∅ ✔ |
| SufixoIdent / Acesso | `(` `.` / `.` | F₇ | ∅ ✔ (`(` e `.` ∉ F₇) |
| ArgsOpc | E | `)` | ∅ ✔ |
| ListaArgs | `,` | `)` | ∅ ✔ |

**Conclusões:**

- **Sem recursão à esquerda**: nenhuma produção começa pelo próprio não
  terminal, direta ou indiretamente (o primeiro símbolo de cada lado direito
  é um terminal ou um não terminal de nível "mais interno").
- **LL(1)**: todas as condições acima são satisfeitas.
- **Não ambígua**: toda gramática LL(1) é não ambígua, pois cada cadeia tem
  uma única derivação mais à esquerda (a tabela preditiva tem no máximo uma
  produção por célula). Em particular, a clássica ambiguidade de
  `a + b * c` não existe: precedência e associatividade estão codificadas
  nos níveis da gramática (seção 6).

## 10. Construção da AST

O Parser não constrói a árvore de derivação completa; cada método devolve
diretamente o nó da **AST** correspondente (nós em [`src/Ast/`](../src/Ast/),
formato detalhado em [`ast-contract.md`](ast-contract.md)).

| Produção | Nó da AST | Observação |
|---|---|---|
| `programa` | `Program(apis, integrations)` | Separa as declarações por tipo, preservando a ordem |
| `decl_api` (+ `decl_base`) | `Api(name, baseUrl, headers, endpoints)` | `baseUrl` sem barra final |
| `decl_header` | `Header(name, value)` | `value` é uma `Expression` |
| `decl_endpoint` | `Endpoint(name, method, path)` | `method` é o enum `HttpMethod` |
| `decl_integracao` | `Integration(name, source, alias, target, condition, mappings)` | `alias`/`condition` `null` e `mappings` `[]` quando omitidos |
| `ref_endpoint` | `EndpointRef(api, endpoint)` | |
| `mapeamento` | `FieldMapping(field, dataType, value)` | `dataType` é o enum `DataType` |
| `expr_ou` … `expr_multiplicativa` | `BinaryExpression(operator, left, right)` | árvore já reflete precedência/associatividade |
| `expr_unaria` | `UnaryExpression(operator, operand)` | |
| `literal` | `Literal(literalType, value)` | valor já convertido para o tipo PHP |
| `IDENT acesso` | `FieldReference(path)` | `user.address.city` → `['user','address','city']` |
| `IDENT chamada` | `FunctionCall(name, arguments)` | |
| `( expressao )` | (nenhum nó próprio) | os parênteses só afetam a forma da árvore |

Todos os nós (exceto `Program`) guardam `line` e `column` da construção no
fonte, para mensagens de erro semânticas posteriores.

## 11. Exemplos de derivação

### 11.1 Programa (derivação mais à esquerda)

Programa ([`examples/valid/exemplo1.dsl`](../examples/valid/exemplo1.dsl), reduzido a uma API):

```text
api Users { base "https://users.example.com/api"; endpoint listUsers GET "/users"; }
integration syncUsers { from Users.listUsers; to Users.listUsers; }
```

Derivação mais à esquerda (⇒ₗ), numerando as produções da seção 8:

```text
Programa
⇒ Declaracao ListaDecl EOF                                                 (1)
⇒ DeclApi ListaDecl EOF                                                    (3)
⇒ api IDENT { DeclBase ListaHeaders DeclEndpoint ListaEndpoints } ListaDecl EOF   (4)
⇒ api IDENT { base TEXTO ; ListaHeaders DeclEndpoint ListaEndpoints } ListaDecl EOF   (5)
⇒ api IDENT { base TEXTO ; DeclEndpoint ListaEndpoints } ListaDecl EOF     (6: ListaHeaders → ε)
⇒ api IDENT { base TEXTO ; endpoint IDENT MetodoHttp TEXTO ; ListaEndpoints } ListaDecl EOF   (9)
⇒ api IDENT { base TEXTO ; endpoint IDENT GET TEXTO ; ListaEndpoints } ListaDecl EOF   (10)
⇒ api IDENT { base TEXTO ; endpoint IDENT GET TEXTO ; } ListaDecl EOF      (8: ListaEndpoints → ε)
⇒ ... Declaracao ListaDecl EOF                                             (2)
⇒ ... DeclIntegracao ListaDecl EOF                                         (3)
⇒ ... integration IDENT { ClausulaFrom ClausulaTo WhenOpc MapOpc } ListaDecl EOF   (11)
⇒ ... integration IDENT { from RefEndpoint AliasOpc ; ClausulaTo WhenOpc MapOpc } ListaDecl EOF   (12)
⇒ ... integration IDENT { from IDENT . IDENT AliasOpc ; ClausulaTo WhenOpc MapOpc } ListaDecl EOF   (20)
⇒ ... integration IDENT { from IDENT . IDENT ; ClausulaTo WhenOpc MapOpc } ListaDecl EOF   (13: ε)
⇒ ... integration IDENT { from IDENT . IDENT ; to RefEndpoint ; WhenOpc MapOpc } ListaDecl EOF   (14)
⇒ ... integration IDENT { from IDENT . IDENT ; to IDENT . IDENT ; WhenOpc MapOpc } ListaDecl EOF   (20)
⇒ ... integration IDENT { from IDENT . IDENT ; to IDENT . IDENT ; } ListaDecl EOF   (15, 16: ε)
⇒ ... integration IDENT { from IDENT . IDENT ; to IDENT . IDENT ; } EOF    (2: ListaDecl → ε)
```

A cadeia final de terminais é exatamente a sequência de tokens do programa
(`IDENT` = `Users`, `syncUsers`, ...; `TEXTO` = `"https://..."`, `"/users"`).

### 11.2 Expressão

Expressão `idade >= 18 && ativo` (trecho de um `when`). Árvore de derivação
resumida (níveis que apenas repassam para o próximo foram condensados com ⇒*):

```text
Expressao
└── ExprE RestoOu
    ├── ExprE
    │   ├── ExprIgual  ⇒*  ExprRel RestoIgual(ε)
    │   │   └── ExprRel
    │   │       ├── ExprAdit ⇒* Primario ⇒ IDENT(idade) SufixoIdent ⇒ IDENT(idade) Acesso(ε)
    │   │       └── RestoRel ⇒ >= ExprAdit ⇒* Primario ⇒ Literal ⇒ INTEIRO(18)
    │   └── RestoE ⇒ && ExprIgual RestoE(ε)
    │                   └── ExprIgual ⇒* Primario ⇒ IDENT(ativo) Acesso(ε)
    └── RestoOu ⇒ ε
```

AST resultante (impressa totalmente parentizada): `((idade >= 18) && ativo)`.

## 12. Erros sintáticos

### 12.1 Formato da mensagem

```text
Erro sintático na linha <L>, coluna <C>: esperado <o que a gramática aceita ali>, encontrado: <token encontrado>
```

- O "esperado" corresponde ao conjunto FIRST/FOLLOW do ponto de análise,
  descrito em português e com o contexto da construção (nome da API,
  integração, campo...).
- O "encontrado" descreve o token: `palavra reservada 'when'`,
  `identificador 'get'`, `texto entre aspas "e"`, `';'`, `fim do arquivo`.
- **`;` ausente**: o erro é posicionado **logo após o token anterior** (onde
  o `;` deveria estar), e não no início da linha seguinte.

Os erros são lançados como `App\Exceptions\SyntaxException`
(subclasse de `CompilerException`), que expõe `errors()` (lista de
`App\Parser\SyntaxError`), `getSourceLine()` e `getSourceColumn()` (posição
do primeiro erro). Com mais de um erro, a mensagem é:

```text
2 erros sintáticos encontrados:
  - Erro sintático na linha 3, coluna 16: ...
  - Erro sintático na linha 11, coluna 11: ...
```

### 12.2 Recuperação de erros (modo pânico)

Ao encontrar um erro, o Parser o registra e **descarta tokens até o próximo
ponto de sincronização**: o início de uma declaração de nível superior
(`api` ou `integration`) ou o `EOF`. Essas palavras reservadas só ocorrem no
início de declarações, então a retomada é segura e evita erros em cascata.
Assim, erros em declarações diferentes são relatados **todos na mesma
execução** (ver [`04-multiplos-erros.dsl`](../examples/invalid/syntactic/04-multiplos-erros.dsl)).
Se houve qualquer erro, nenhuma AST é devolvida.

### 12.3 Catálogo de erros

Todos estes casos são verificados em
[`tests/Unit/Parser/ParserErrorTest.php`](../tests/Unit/Parser/ParserErrorTest.php).

| Situação | Entrada (trecho) | Mensagem (após "Erro sintático na linha L, coluna C: ") |
|---|---|---|
| Programa vazio | *(arquivo vazio)* | `esperado declaração 'api' ou 'integration' (o programa está vazio), encontrado: fim do arquivo` |
| Declaração desconhecida | `endpoint x GET "/x";` | `esperado declaração 'api' ou 'integration', encontrado: palavra reservada 'endpoint'` |
| API sem nome | `api { ...` | `esperado nome da API (identificador) após 'api', encontrado: '{'` |
| `base` ausente | `api A { endpoint ...` | `esperado 'base' como primeira declaração do bloco da API 'A' (endereço-base), encontrado: palavra reservada 'endpoint'` |
| `;` ausente | `base "u" endpoint ...` | `esperado ';' ao final da declaração 'base', encontrado: palavra reservada 'endpoint'` |
| API sem endpoints | `api A { base "u"; }` | `esperado 'header' ou 'endpoint' no bloco da API 'A' (toda API deve declarar ao menos um endpoint), encontrado: '}'` |
| Método inválido | `endpoint e get "/e";` | `esperado método HTTP (GET, POST, PUT, PATCH ou DELETE, em maiúsculas) no endpoint 'e', encontrado: identificador 'get'` |
| Caminho sem `/` | `endpoint e GET "e";` | `o caminho do endpoint 'e' deve começar com '/', encontrado: texto entre aspas "e"` |
| Bloco não fechado | `... endpoint e GET "/e";` *(fim)* | `esperado 'endpoint' ou '}' para fechar o bloco da API 'A', encontrado: fim do arquivo` |
| `=` ausente no header | `header "Accept" "json";` | `esperado '=' após o nome do cabeçalho "Accept", encontrado: texto entre aspas "json"` |
| `to` ausente | `from A.b; when x;` | `esperado 'to' após a cláusula 'from' da integração 's' (endpoint de destino), encontrado: palavra reservada 'when'` |
| Referência sem `.` | `from A b;` | `esperado '.' entre a API e o endpoint na cláusula 'from' (formato Api.endpoint), encontrado: identificador 'b'` |
| Lixo após `from` | `from A.b x;` | `esperado 'as' ou ';' ao final da cláusula 'from', encontrado: identificador 'x'` |
| Cláusula fora de ordem | `map { ... } when x;` | `esperado '}' para fechar o bloco da integração 's', encontrado: palavra reservada 'when'` |
| Cláusula desconhecida | `to C.d; foo;` | `esperado 'when', 'map' ou '}' para fechar o bloco da integração 's', encontrado: identificador 'foo'` |
| `map` vazio | `map { }` | `esperado nome de campo no bloco 'map' (o bloco deve conter ao menos um mapeamento), encontrado: '}'` |
| Tipo ausente/inválido | `n: text = 1;` | `esperado tipo de dado (string, int, float ou bool) para o campo 'n', encontrado: identificador 'text'` |
| Comparação encadeada | `when a < b < c;` | `operador '<' inesperado: operadores relacionais não são associativos; use parênteses, por exemplo (a < b) < c` |
| Igualdade encadeada | `when a == b != c;` | `operador '!=' inesperado: operadores de igualdade não são associativos; use parênteses, por exemplo (a == b) != c` |
| `)` ausente | `when (a + b;` | `esperado ')' para fechar o '(' aberto na linha 1, coluna 40, encontrado: ';'` |
| Operando ausente | `when a + ;` | `esperado expressão (literal, identificador, '(', '!' ou '-'), encontrado: ';'` |
| Argumentos sem `,` | `when f(a b);` | `esperado ',' ou ')' na chamada da função 'f', encontrado: identificador 'b'` |
| Referência terminada em `.` | `when a.;` | `esperado nome de campo após 'a.', encontrado: ';'` |

### 12.4 Exemplos de programas com erro sintático

Em [`examples/invalid/syntactic/`](../examples/invalid/syntactic/). Cada
arquivo documenta, em linhas `// Erro esperado: ...`, a mensagem exata, e
[`tests/Unit/Parser/ExamplesTest.php`](../tests/Unit/Parser/ExamplesTest.php)
confere que o Parser produz exatamente esses erros.

| Arquivo | Erro demonstrado |
|---|---|
| `01-ponto-e-virgula-ausente.dsl` | `;` ausente ao final de um endpoint (posição logo após `"/users"`) |
| `02-clausula-to-ausente.dsl` | cláusula obrigatória `to` omitida |
| `03-comparacao-encadeada.dsl` | `18 <= cliente.idade < 65` (operadores não associativos) |
| `04-multiplos-erros.dsl` | dois erros independentes relatados na mesma execução (recuperação) |

### 12.5 Erros que não são sintáticos

- **Léxicos** (caractere inválido, texto não terminado): detectados pelo
  Lexer, antes do Parser.
- **Semânticos** (API ou endpoint inexistente em `from`/`to`, nomes
  duplicados, função desconhecida, alias não declarado): o programa é
  sintaticamente válido; a verificação fica para uma etapa posterior (ver
  pendência P2 em [`ast-contract.md`](ast-contract.md)).
- **Contrato violado** (lista de tokens sem `EOF`, literal sem valor): defeito
  do compilador, não do programa. O Parser lança `InvalidArgumentException`
  ou `LogicException`, que a CLI trata como erro interno.

## 13. Atendimento aos requisitos do trabalho

| Requisito da linguagem | Onde aparece na gramática |
|---|---|
| Identificadores | `IDENT`: nomes de APIs, endpoints, integrações, alias, campos e funções |
| Pelo menos dois tipos de dados | Literais texto, inteiro, decimal, booleano e nulo; tipos declarados `string`, `int`, `float`, `bool` (`tipo`) |
| Literais | `literal`, `TEXTO` em `base`/`header`/`endpoint` |
| Operadores | 13 operadores binários + 2 unários em 7 níveis de precedência (seção 6) |
| Comandos específicos do domínio | `api`, `base`, `header`, `endpoint`, `integration`, `from`, `to`, `map`, métodos HTTP |
| Estrutura composta/repetitiva/condicional | **Condicional**: `when`; **compostas**: blocos `api`, `integration` e `map`, listas de endpoints/cabeçalhos/mapeamentos |
| Agrupamento/delimitação | `{ }` para blocos, `( )` em expressões e chamadas, `;` como terminador |
| Comentários | `// ...`, descartados pelo Lexer |

| Critério de avaliação (Analisador Sintático) | Entregue |
|---|---|
| Definição da GLC | Seções 3–6 (terminais, não terminais, símbolo inicial, produções EBNF e BNF) |
| Especificação do analisador sintático | Seções 7–10 (técnica, transformações, FIRST/FOLLOW, LL(1), AST) e `src/Parser/` |
| Apresentação das saídas de erro | Seção 12 e `SyntaxException` |
| Exemplos | 3 válidos em `examples/valid/`, 4 com erros sintáticos em `examples/invalid/syntactic/` |

## 14. Pendências

| # | Questão | Com quem |
|---|---|---|
| G10 | Sintaxe definitiva dos comentários (`//`, `#` ou ambos) — afeta apenas os exemplos | Lexer (T5) |
| G11 | Sequências de escape aceitas em `TEXTO` | Lexer |
| G12 | Verificação semântica (referências, duplicidades, aridade de funções) | Grupo (P2) |
| G13 | Lista de funções embutidas (sugestão: `env`, `upper`, `lower`, `now`) | Gerador |
