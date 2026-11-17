# Contrato da AST (proposta preliminar)

> **Status: PROPOSTA PARA DISCUSSÃO.**
> Este contrato depende de validação pelos responsáveis pelo **Parser** (quem
> produz a AST) e pelo **Generator** (quem a consome). Nada aqui está
> implementado em `src/Ast/`. Alterações devem ser combinadas entre ambos.

## 1. Objetivo

A AST (Abstract Syntax Tree) é a fronteira entre a análise e a geração de
código. Definir seu formato cedo permite que:

- o Parser saiba **o que construir**;
- o Generator possa ser desenvolvido e testado **com ASTs montadas à mão**,
  antes de o Parser estar pronto.

## 2. Elementos

| Nó | Campos | Descrição |
|---|---|---|
| `Program` | `apis`, `integrations` | Raiz: tudo o que foi declarado no arquivo |
| `Api` | `name`, `baseUrl`, `endpoints` | API HTTP com identificador e endereço-base |
| `Endpoint` | `name`, `method`, `path` | Operação de uma API |
| `Integration` | `name`, `source`, `target` | Fluxo: dados de um endpoint de origem enviados a um de destino |

Referência a endpoint (`source`/`target`): par `api` + `endpoint`, ambos
identificadores declarados no programa.

Todos os nós carregam `line` (e opcionalmente `column`) da declaração no
arquivo-fonte, para mensagens de erro semânticas e depuração.

## 3. Representação proposta: arrays associativos

Opção mais simples para começar: cada nó é um array com a chave `type`.

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
            'endpoints' => [
                [
                    'type' => 'Endpoint',
                    'name' => 'listUsers',
                    'method' => 'GET',
                    'path' => '/users',
                    'line' => 3,
                ],
            ],
        ],
        [
            'type' => 'Api',
            'name' => 'Crm',
            'baseUrl' => 'https://crm.example.com/v1',
            'line' => 6,
            'endpoints' => [
                [
                    'type' => 'Endpoint',
                    'name' => 'createContact',
                    'method' => 'POST',
                    'path' => '/contacts',
                    'line' => 8,
                ],
            ],
        ],
    ],
    'integrations' => [
        [
            'type' => 'Integration',
            'name' => 'syncUsers',
            'source' => ['api' => 'Users', 'endpoint' => 'listUsers'],
            'target' => ['api' => 'Crm', 'endpoint' => 'createContact'],
            'line' => 11,
        ],
    ],
];
```

Regras propostas para os valores:

- `name`: identificador, como escrito no fonte (sensível a maiúsculas — **pendente**).
- `method`: sempre em maiúsculas (`GET`, `POST`, `PUT`, `PATCH`, `DELETE`).
- `baseUrl`: string sem barra final; `path`: string iniciada por `/`.
- Listas vazias são `[]`, nunca `null`.

### Alternativa: classes imutáveis

Se o grupo preferir tipagem mais forte, os mesmos nós podem virar classes
`readonly` em `src/Ast/` (`Program`, `Api`, `Endpoint`, `Integration`,
`EndpointRef`). Vantagens: autocompletar, erros de digitação detectados pelo PHP.
Custo: mais código para escrever e manter. A estrutura dos campos seria a mesma.

## 4. Garantias (proposta)

O Generator assume que a AST recebida:

1. Está sintaticamente correta (garantido pelo Parser).
2. Não contém nomes de API duplicados nem endpoints duplicados na mesma API.
3. Toda referência `source`/`target` aponta para uma API e um endpoint existentes.

Os itens 2 e 3 são **verificações semânticas**. É preciso decidir quem as faz
(ver pendências).

## 5. Decisões pendentes

| # | Questão | Opções / observações | Decidem |
|---|---|---|---|
| P1 | Arrays associativos ou classes `readonly`? | Arrays: simples; classes: mais seguras | Parser + Generator |
| P2 | Quem faz a verificação semântica (referências, duplicidades)? | Parser; etapa separada `Analyzer`; ou Generator | Grupo |
| P3 | **Tipos de dados** exigidos pelo trabalho | string, número inteiro/decimal, booleano, nulo? Lista/objeto? | Grupo + Parser |
| P4 | **Operadores** exigidos pelo trabalho | relacionais (`==`, `!=`, `<`, `>`, `<=`, `>=`), lógicos (`&&`, `\|\|`, `!`), aritméticos? | Grupo + Lexer + Parser |
| P5 | **Estrutura condicional** exigida pelo trabalho | Ex.: encaminhar apenas se uma condição sobre a resposta for verdadeira. Precisa de nó `Condition`/`Expression` | Grupo + Parser + Generator |
| P6 | Mapeamento de campos origem → destino | Encaminhar o JSON inteiro ou permitir selecionar/renomear campos? | Grupo + Generator |
| P7 | Cabeçalhos e autenticação | Ex.: `Authorization`, `Content-Type`; tokens em variáveis de ambiente? | Grupo + Generator |
| P8 | Parâmetros de caminho e query string | Ex.: `/users/{id}`, `?page=1` | Grupo |
| P9 | Respostas em lista | Se a origem retornar uma lista, enviar item a item ou tudo de uma vez? | Generator |
| P10 | Posição (`line`/`column`) em todos os nós? | Útil para erros semânticos | Parser |

Quando uma questão for resolvida, registrar aqui a decisão, a data e quem
aprovou, e mover o item para a seção "Decisões tomadas".

## 6. Decisões tomadas

_Nenhuma até o momento._
