# Contrato da AST

> **Status: IMPLEMENTADO pelo Analisador Sintático** em [`src/Ast/`](../src/Ast/)
> (2026-11-20). **Aguarda validação do Gerador**, que consome a AST.
> Alterações devem ser combinadas entre os responsáveis pelo **Parser** e pelo
> **Generator**. A gramática que produz estes nós está em
> [`grammar.md`](grammar.md).

## 1. Objetivo

A AST (Abstract Syntax Tree) é a fronteira entre a análise e a geração de
código. Definir seu formato permite que:

- o Parser saiba **o que construir**;
- o Generator possa ser desenvolvido e testado **com ASTs montadas à mão**,
  sem depender do Lexer e do Parser.

## 2. Elementos

### 2.1 Declarações

| Nó (classe em `App\Ast`) | Campos | Descrição |
|---|---|---|
| `Program` | `apis`, `integrations` | Raiz: tudo o que foi declarado no arquivo, na ordem do fonte |
| `Api` | `name`, `baseUrl`, `headers`, `endpoints` | API HTTP com identificador, endereço-base e cabeçalhos |
| `Header` | `name`, `value` (`Expression`) | Cabeçalho enviado em toda requisição à API |
| `Endpoint` | `name`, `method` (`HttpMethod`), `path` | Operação de uma API |
| `Integration` | `name`, `source`, `alias`, `target`, `condition`, `mappings` | Fluxo origem → destino, com filtro e mapeamento opcionais |
| `EndpointRef` | `api`, `endpoint` | Referência `Api.endpoint` usada em `source`/`target` |
| `FieldMapping` | `field`, `dataType` (`DataType`), `value` (`Expression`) | Campo do registro enviado ao destino |

Enums: `HttpMethod` (`GET`, `POST`, `PUT`, `PATCH`, `DELETE`) e `DataType`
(`string`, `int`, `float`, `bool`).

### 2.2 Expressões (`App\Ast\Expression`)

Usadas em `Header.value`, `Integration.condition` e `FieldMapping.value`.
Todas implementam a interface `Expression`.

| Nó | Campos | Exemplo no fonte |
|---|---|---|
| `Literal` | `literalType` (`LiteralType`), `value` | `"paid"`, `18`, `99.5`, `true`, `null` |
| `FieldReference` | `path` (lista de nomes) | `pedido.cliente.email` → `['pedido','cliente','email']` |
| `FunctionCall` | `name`, `arguments` (lista de `Expression`) | `env("CRM_TOKEN")` |
| `UnaryExpression` | `operator` (`UnaryOperator`: `!`, `-`), `operand` | `!pedido.cancelled` |
| `BinaryExpression` | `operator` (`BinaryOperator`), `left`, `right` | `pedido.total >= 100` |

`BinaryOperator`: `||`, `&&`, `==`, `!=`, `<`, `<=`, `>`, `>=`, `+`, `-`,
`*`, `/`, `%`. A forma da árvore já reflete precedência e associatividade
(ver [`grammar.md`](grammar.md), seção 6): o Generator pode traduzir cada
`BinaryExpression` como `(left op right)` sem se preocupar com precedência.

Posição: todos os nós, exceto `Program`, carregam `line` e `column` (a partir
de 1). Em `BinaryExpression`/`UnaryExpression`, a posição é a do operador; nos
demais, a do primeiro token da construção.

## 3. Representação: classes imutáveis + `toArray()`

**Decisão (P1):** os nós são classes `final readonly` (PHP 8.2+), com
propriedades públicas tipadas. Vantagens: autocompletar, erros de digitação
detectados pelo PHP, enums para valores fechados.

Para manter a opção de trabalhar com **arrays associativos** (proposta
original deste documento), todo nó implementa `App\Ast\Node::toArray()`, que
devolve a árvore no formato abaixo. O Generator pode consumir os objetos ou
os arrays, como preferir.

```php
<?php

// Uso pelo Generator:
$program = (new Parser($tokens))->parse();     // App\Ast\Program
foreach ($program->integrations as $integration) {
    $integration->source->api;                 // 'Users'
    $integration->condition;                   // Expression|null
}
$ast = $program->toArray();                    // mesmo conteúdo em arrays
```

Formato de `toArray()` para [`examples/valid/exemplo1.dsl`](../examples/valid/exemplo1.dsl)
(conferido em `ParserTest::testContractExampleProducesTheDocumentedAst`):

```php
<?php

$ast = [
    'type' => 'Program',
    'apis' => [
        [
            'type' => 'Api',
            'name' => 'Users',
            'baseUrl' => 'https://users.example.com/api',
            'line' => 1,
            'column' => 1,
            'headers' => [],
            'endpoints' => [
                [
                    'type' => 'Endpoint',
                    'name' => 'listUsers',
                    'method' => 'GET',
                    'path' => '/users',
                    'line' => 3,
                    'column' => 5,
                ],
            ],
        ],
        // ... Api 'Crm' no mesmo formato
    ],
    'integrations' => [
        [
            'type' => 'Integration',
            'name' => 'syncUsers',
            'source' => ['api' => 'Users', 'endpoint' => 'listUsers'],
            'target' => ['api' => 'Crm', 'endpoint' => 'createContact'],
            'line' => 11,
            'column' => 1,
            'alias' => null,       // "from ... as alias" omitido
            'condition' => null,   // cláusula "when" omitida
            'mappings' => [],      // bloco "map" omitido: enviar o registro inteiro
        ],
    ],
];
```

Formato dos demais nós em `toArray()` (posições de
[`examples/valid/exemplo3.dsl`](../examples/valid/exemplo3.dsl)):

```php
<?php

// Linha 12: header "Authorization" = "Bearer " + env("CRM_TOKEN");
['type' => 'Header', 'name' => 'Authorization', 'value' => [/* Expression */], 'line' => 12, 'column' => 5];
['type' => 'Literal', 'literalType' => 'string', 'value' => 'Bearer ', 'line' => 12, 'column' => 30];
['type' => 'FunctionCall', 'name' => 'env', 'arguments' => [/* Expression... */], 'line' => 12, 'column' => 42];

// Linha 33: age: int = 2026 - contato.birthYear;
['type' => 'FieldMapping', 'field' => 'age', 'dataType' => 'int', 'value' => [/* Expression */], 'line' => 33, 'column' => 9];
['type' => 'BinaryExpression', 'operator' => '-', 'left' => [/* ... */], 'right' => [/* ... */], 'line' => 33, 'column' => 25];
['type' => 'FieldReference', 'path' => ['contato', 'birthYear'], 'line' => 33, 'column' => 27];

// Linha 34: active: bool = !contato.archived;
['type' => 'UnaryExpression', 'operator' => '!', 'operand' => [/* Expression */], 'line' => 34, 'column' => 24];
```

`EndpointRef::toArray()` devolve apenas `['api' => ..., 'endpoint' => ...]`,
como na proposta original; sua posição está nas propriedades do objeto.

Regras para os valores (garantidas pelo Parser):

- `name`, `field` e segmentos de `path`: como escritos no fonte, sensíveis a
  maiúsculas e minúsculas.
- `method`: sempre em maiúsculas (`GET`, `POST`, `PUT`, `PATCH`, `DELETE`).
- `baseUrl`: string sem barra final; `path` do endpoint: string iniciada por `/`.
- `Literal.value`: já convertido (`string`, `int`, `float`, `bool` ou `null`).
- Listas vazias são `[]`, nunca `null`. Opcionais ausentes (`alias`,
  `condition`) são `null`.

## 4. Garantias

O Generator pode assumir que a AST recebida:

1. Está sintaticamente correta (garantido pelo Parser: se houver qualquer
   erro sintático, nenhuma AST é produzida).
2. Toda `Api` tem ao menos um `Endpoint`; todo bloco `map` presente tem ao
   menos um `FieldMapping`.

**Ainda não garantido** (verificações semânticas, pendência P2):

3. Ausência de nomes de API duplicados e de endpoints duplicados na mesma API.
4. Toda referência `source`/`target` aponta para uma API e um endpoint existentes.
5. Toda `FieldReference` começa pelo `alias` da integração.
6. Toda `FunctionCall` usa uma função conhecida, com a quantidade certa de argumentos.

## 5. Decisões pendentes

| # | Questão | Opções / observações | Decidem |
|---|---|---|---|
| P2 | Quem faz a verificação semântica (itens 3–6 da seção 4)? | Etapa separada `Analyzer` entre Parser e Generator (recomendado), ou o próprio Generator | Grupo |
| P6 | Mapeamento: semântica de `mappings` vazio | Proposta: enviar o registro de origem inteiro | Generator |
| P7 | Funções embutidas disponíveis | Sugestão: `env(nome)`, `upper(texto)`, `lower(texto)`, `now()` | Generator |
| P8 | Parâmetros de caminho e query string | Ex.: `/users/{id}`; a gramática aceita qualquer texto iniciado por `/` | Generator |
| P9 | Respostas em lista | Proposta: se a origem retornar uma lista, aplicar `when`/`map` e enviar item a item | Generator |
| P11 | Semântica de `+` com textos | Proposta: concatenação quando ao menos um operando é texto | Generator |
| P12 | Nome do registro quando `alias` é omitido | Ex.: `item`; ou exigir `as` sempre que houver `when`/`map` (verificação semântica) | Grupo |

## 6. Decisões tomadas

Propostas pelo Analisador Sintático em 2026-11-20, implementadas em
`src/Ast/` e `src/Parser/`; **aguardam validação do Gerador**.

| # | Questão | Decisão |
|---|---|---|
| P1 | Arrays associativos ou classes `readonly`? | Classes `readonly` com `toArray()` no formato de arrays (seção 3) |
| P3 | Tipos de dados | Literais `string`, `int`, `float`, `bool`, `null`; tipos declarados `string`, `int`, `float`, `bool` em `FieldMapping.dataType` |
| P4 | Operadores | Lógicos `\|\|` `&&` `!`; igualdade `==` `!=`; relacionais `<` `<=` `>` `>=`; aritméticos `+` `-` `*` `/` `%`; menos unário |
| P5 | Estrutura condicional | Cláusula `when` → `Integration.condition` (`Expression` ou `null`) |
| P6 (parcial) | Mapeamento de campos | Bloco `map` → `Integration.mappings` (lista de `FieldMapping`) |
| P7 (parcial) | Cabeçalhos e autenticação | `header` → `Api.headers`; segredos via `env("...")` |
| P10 | Posição em todos os nós? | Sim: `line` e `column` em todos os nós, exceto `Program` |
