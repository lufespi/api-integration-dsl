# Gerador de Código PHP

> **Status: IMPLEMENTADO** em [`src/Generator/`](../src/Generator/) (2026-11-26).
> Responsável: integrante do **Gerador de código**. Consome a AST definida em
> [`ast-contract.md`](ast-contract.md). A integração na CLI é feita por outro
> integrante e **ainda não foi realizada**.
>
> As decisões marcadas como **provisórias** (seção 7) são do Gerador e
> aguardam validação do grupo.

## 1. Interface pública

```php
use App\Generator\PhpGenerator;

$php = (new PhpGenerator())->generate($program);   // App\Ast\Program -> string
```

- Recebe um `App\Ast\Program` (do Parser ou montado à mão) e devolve o
  código-fonte PHP completo, iniciado por `<?php`.
- **Não** grava arquivos, **não** executa o código, **não** faz requisições
  HTTP e **não** depende do Lexer, do Parser nem da CLI.
- Saída **determinística**: a mesma AST produz sempre o mesmo texto (sem
  datas, aleatoriedade ou caminhos locais).
- Problemas que impediriam uma geração segura lançam
  `App\Generator\GenerationException` (seção 4).

Uso previsto na CLI (a cargo da integração do pipeline):

```php
$tokens  = (new Lexer($source))->tokenize();
$program = (new Parser($tokens))->parse();
$php     = (new PhpGenerator())->generate($program);   // GenerationException em caso de erro
file_put_contents('output/programa.php', $php);
```

## 2. Arquitetura

| Arquivo | Responsabilidade |
|---|---|
| `PhpGenerator.php` | Ponto de entrada. Indexa APIs/endpoints, resolve as referências `Api.endpoint`, faz as verificações mínimas e monta o script (cabeçalhos, condição, mapeamento e execução de cada integração). |
| `ExpressionCompiler.php` | Traduz cada nó de `App\Ast\Expression` em uma expressão PHP; valida funções e referências de campo; reconstrói o texto da DSL para os comentários do código gerado. |
| `RuntimeLibrary.php` | Código PHP **fixo** copiado uma única vez em cada script: cURL, JSON, leitura de campos, operadores, conversões de tipo e laço de execução. |
| `GenerationException.php` | Erro de geração (subclasse de `CompilerException`, distinta de `SyntaxException`). |

A divisão separa o que **depende do programa `.dsl`** (gerado pelo
`PhpGenerator`/`ExpressionCompiler`) do que é **igual em todo script**
(`RuntimeLibrary`). Assim o código específico de cada programa fica curto e
legível, e as funções auxiliares não se repetem quando há várias integrações.

### 2.1 Estrutura do script gerado

```text
<?php declare(strict_types=1);
comentário inicial         requisitos, modo de execução, integrações
biblioteca de runtime      dsl_http_request(), dsl_field(), dsl_add(), ... (uma vez)
dsl_api_headers_N()        uma função por API usada: cabeçalhos dessa API
para cada integração N:
  dsl_condition_N()        se houver "when"
  dsl_map_N()              se houver "map"
  dsl_integration_N()      chama dsl_run_integration() com URLs, métodos e funções acima
execução                   exit(dsl_main([...])), exceto se DSL_NO_AUTORUN estiver definida
```

Trecho gerado para [`exemplo3.dsl`](../examples/valid/exemplo3.dsl)
(primeira integração):

```php
/**
 * Condição "when": contato.email != null && contato.optIn
 */
function dsl_condition_1(stdClass $record): bool
{
    return (bool) ((!dsl_equals(dsl_field($record, ['email']), null)) && dsl_field($record, ['optIn']));
}

/**
 * Mapeamento "map": monta o registro enviado ao destino.
 */
function dsl_map_1(stdClass $record): stdClass
{
    return (object) [
        // email: string = contato.email
        'email' => dsl_convert(dsl_field($record, ['email']), 'string', 'email'),
        // fullName: string = contato.firstName + " " + contato.lastName
        'fullName' => dsl_convert(dsl_add(dsl_add(dsl_field($record, ['firstName']), ' '), dsl_field($record, ['lastName'])), 'string', 'fullName'),
        // age: int = 2026 - contato.birthYear
        'age' => dsl_convert(dsl_arith('-', 2026, dsl_field($record, ['birthYear'])), 'int', 'age'),
        // active: bool = !contato.archived
        'active' => dsl_convert((!dsl_field($record, ['archived'])), 'bool', 'active'),
    ];
}

function dsl_integration_1(): bool
{
    return dsl_run_integration(
        name: 'sincronizarAssinantes',
        sourceUrl: 'https://crm.example.com/v1/contacts',
        sourceHeaders: dsl_api_headers_1(...),
        targetMethod: 'PUT',
        targetUrl: 'https://mailer.example.com/api/subscribers',
        targetHeaders: dsl_api_headers_2(...),
        condition: dsl_condition_1(...),
        mapping: dsl_map_1(...),
    );
}
```

### 2.2 Execução do script gerado

```bash
CRM_TOKEN=... php output/exemplo3.php
```

Requisitos: PHP 8.3+ com as extensões `curl` e `json`. Não usa Composer,
Docker nem arquivos do compilador. Saída (ilustrativa):

```text
[sincronizarAssinantes] GET https://crm.example.com/v1/contacts -> PUT https://mailer.example.com/api/subscribers
[sincronizarAssinantes] 3 registro(s) recebido(s): 2 enviado(s), 1 ignorado(s) pela condição, 0 com falha.
Concluído: 2 integração(ões) sem falhas.
```

Erros vão para *stderr* como `[integração] ERRO: ...`. Código de saída: **0**
sem falhas; **1** se qualquer integração tiver falha.

## 3. Tradução

### 3.1 Fluxo de cada integração (`dsl_run_integration`)

1. Calcula os cabeçalhos da API de origem e da API de destino (falha de
   configuração, como `env()` ausente, interrompe a integração **antes** de
   qualquer requisição).
2. `GET` na URL de origem (`baseUrl` + `path`); decodifica o JSON.
3. Obtém os registros: **objeto** JSON = um registro; **lista** JSON = cada
   item é um registro (cada item deve ser objeto). Outro formato é erro.
4. Para cada registro: avalia `when` (se houver); se falso, ignora. Monta o
   payload com `map` (ou usa o registro original). Envia ao destino com o
   método do endpoint e body JSON.
5. Falha em um registro (conversão, operação inválida, status HTTP do
   destino) é reportada e os demais registros **continuam**. Falha na origem
   interrompe só aquela integração; as seguintes executam normalmente.

### 3.2 Requisições HTTP (`dsl_http_request`)

| Aspecto | Comportamento |
|---|---|
| Métodos | Origem: `GET` (sem body). Destino: `POST`, `PUT` ou `PATCH` com body JSON. |
| Body | `json_encode` com `JSON_THROW_ON_ERROR`, sem escapar `/` e Unicode, preservando `10.0` como decimal. |
| `Content-Type` | `application/json` adicionado quando há body, **exceto** se a API já declarar `Content-Type` (sem duplicar). |
| Timeouts | 10 s para conectar (`DSL_CONNECT_TIMEOUT`), 30 s por requisição (`DSL_REQUEST_TIMEOUT`). |
| Sucesso | Status `2xx`. Qualquer outro (inclusive `3xx`: redirecionamentos não são seguidos) é erro com o código de status. |
| Falha de conexão | Mensagem com método, URL e o erro do cURL. |
| JSON da origem | Decodificado como objetos (`stdClass`), preservando `{}` vs `[]` ao repassar o registro. JSON inválido é erro. |
| Recursos | O handle cURL é liberado ao fim de cada requisição (`finally`). |
| TLS | Verificação de certificado **mantida** (padrão do cURL); apenas `http`/`https` são aceitos. |

### 3.3 Expressões

| Nó da AST | PHP gerado | Semântica em runtime |
|---|---|---|
| `Literal` | `var_export` do valor (`'texto'`, `18`, `99.5`, `true`, `null`) | literal PHP com escape seguro; negativos entre parênteses |
| `FieldReference` `c.a.b` | `dsl_field($record, ['a', 'b'])` | caminho aninhado; ausente → `null` |
| `FunctionCall` `env("X")` | `dsl_env('X')` | variável de ambiente obrigatória |
| `!x` | `(!x)` | negação com a conversão booleana do PHP |
| `-x` | `dsl_negate(x)` | exige número |
| `a && b`, `a \|\| b` | `(a && b)`, `(a \|\| b)` | nativos do PHP: curto-circuito e conversão booleana |
| `a == b`, `a != b` | `dsl_equals(a, b)`, `(!dsl_equals(a, b))` | números pelo valor (`100 == 100.0`); demais estritamente (`"1" != 1`) |
| `<` `<=` `>` `>=` | `dsl_compare('<', a, b)` | dois números ou dois textos (ordem lexicográfica); outros pares → erro |
| `a + b` | `dsl_add(a, b)` | se algum operando é texto: concatenação; senão, soma numérica |
| `-` `*` `/` `%` | `dsl_arith('-', a, b)` | exige números; divisão/resto por zero → erro; `%` com decimais usa `fmod` |

Cada nó vira uma chamada de função ou uma expressão entre parênteses, então
**a precedência e a associatividade são exatamente as da árvore** do Parser.

**Campos ausentes.** `dsl_field` devolve `null`, sem *notices*, quando o
campo não existe ou quando o caminho atravessa um valor que não é objeto
(`c.nome.x` com `nome` texto). Operações que exigem número ou texto
**falham** ao receber `null` (ex.: `2026 - c.birthYear` sem `birthYear`), em
vez de produzir um resultado arbitrário; já `c.x == null` e `!c.x` funcionam
naturalmente com campos ausentes.

**Texto de valores.** Na concatenação, números usam a conversão do PHP
(`1.5` → `"1.5"`), booleanos viram `"true"`/`"false"`; `null`, listas e
objetos não podem ser concatenados (erro).

### 3.4 Mapeamento (`map`) e conversão de tipos (`dsl_convert`)

Cada `FieldMapping` vira uma entrada `'campo' => dsl_convert(valor, 'tipo', 'campo')`;
o payload é um objeto JSON (mesmo com campos de nome numérico).

| Tipo declarado | Aceita | Rejeita (erro no registro) |
|---|---|---|
| `string` | texto; número (`1990` → `"1990"`); booleano (`"true"`/`"false"`) | `null`, lista, objeto |
| `int` | inteiro; decimal sem parte fracionária (`36.0` → `36`); texto inteiro (`"42"`) | `3.7`, `"abc"`, `null`, booleano |
| `float` | inteiro ou decimal (`36` → `36.0`); texto numérico | `"abc"`, `null`, booleano |
| `bool` | booleano; `1`/`0`; `"true"`/`"false"`/`"1"`/`"0"` | outros valores, `null` |

Conversões que perderiam informação são erros, não truncamentos silenciosos.

### 3.5 Cabeçalhos (`header`)

- Cada API gera sua função `dsl_api_headers_N()`; a origem recebe só os
  cabeçalhos da API de origem e o destino só os da API de destino.
  **Credenciais nunca são repassadas de uma API à outra.**
- Valores são expressões avaliadas uma vez por integração, antes das
  requisições; o resultado deve ser texto ou número.
- Valores com quebra de linha são rejeitados (injeção de cabeçalho).
- Nomes de cabeçalho são validados na geração (caracteres de *token* HTTP).

## 4. Verificações feitas na geração

O Parser garante a sintaxe; o Gerador faz **apenas** as verificações
semânticas necessárias para gerar código seguro (a decisão sobre um
`Analyzer` separado continua pendente — P2). Todas lançam
`GenerationException`, com a posição do nó:

```text
Erro de geração na linha 12, coluna 10: a integração 'x' usa a API 'Crm' como destino, mas ela não foi declarada
```

| Verificação | Exemplo de mensagem |
|---|---|
| API inexistente | `a integração 'sync' usa a API 'Nope' como origem, mas ela não foi declarada` |
| Endpoint inexistente | `a API 'Src' não possui o endpoint 'nope' (usado como origem da integração 'sync')` |
| API / endpoint / integração duplicados | `API 'Src' declarada mais de uma vez (primeira declaração na linha 1); a referência seria ambígua` |
| Método não suportado na posição | `o endpoint 'Loja.cancelarPedido' usa DELETE, que não é suportado como destino; use POST, PUT, PATCH` |
| Parâmetro de caminho | `o endpoint 'x' usa parâmetros de caminho ('/orders/{id}'), ainda não suportados (pendência P8)` |
| URL inválida | `o endereço-base da API 'X' deve ser uma URL http:// ou https:// sem espaços` |
| Função desconhecida / aridade | `função desconhecida 'upper'; funções disponíveis: env` |
| Argumento de `env` não literal | `o argumento de 'env' deve ser um texto literal com o nome da variável` |
| Campo sem alias / alias incompatível | `a referência de campo 'cliente.email' deve começar pelo alias 'contato' da integração 'x'` |
| Campo em cabeçalho | `a referência de campo 'r.id' não pode ser usada em cabeçalho` |
| Campo repetido no `map` | `campo 'n' vazio ou repetido no bloco map da integração 'sync'` |

Restrições de método e de caminho valem só para endpoints **usados** por
alguma integração: o `DELETE "/orders/{id}"` não usado de
[`exemplo2.dsl`](../examples/valid/exemplo2.dsl) é aceito. Os cabeçalhos de
todas as APIs são validados, mas só os das APIs usadas são emitidos.

## 5. Segurança do código gerado

- `declare(strict_types=1);` em todo script.
- Nenhum texto da AST é concatenado como código: strings, nomes de campos,
  URLs e nomes de variáveis são emitidos com `var_export` (literal PHP
  escapado). Texto em comentários é neutralizado (quebras de linha, `?>`, `*/`).
- Sem `eval`, sem comandos de sistema, sem `include`/`require`.
- Só a função `env` pode ser chamada, e com nome **literal**: dados do
  registro nunca escolhem qual variável de ambiente é lida.
- Mensagens de erro nunca exibem valores de cabeçalhos nem de variáveis de
  ambiente (apenas o nome da variável ou do cabeçalho e o tipo do valor).
- Verificação TLS do cURL mantida; protocolos restritos a HTTP/HTTPS;
  redirecionamentos não seguidos.
- Nenhuma credencial é escrita no script pelo Gerador (segredos vêm de `env`).

## 6. Testes

Em [`tests/Unit/Generator/`](../tests/Unit/Generator/), com ASTs montadas à
mão (`Support/Ast.php`). Nenhum teste acessa a rede externa.

| Arquivo | O que verifica |
|---|---|
| `ExpressionGenerationTest` | Literais, operadores, precedência, `+`, campos aninhados/ausentes, `env`, funções inválidas, regras de alias. As expressões traduzidas são **executadas** com o runtime real sobre registros simulados. |
| `PhpGeneratorTest` | `php -l`, URLs e métodos, resolução de referências, várias integrações, determinismo, ausência de construções perigosas, escape de textos hostis, cabeçalhos, todas as `GenerationException`. |
| `GeneratedCodeTest` | Script completo executado contra um **servidor HTTP local** (`php -S` em `127.0.0.1`, `Support/LocalHttpServer.php`): lista/objeto, `when`, `map`, PUT/PATCH, cabeçalhos por API, `env` ausente, status HTTP, JSON inválido, formato incompatível, falha de conexão, várias integrações, código de saída. |
| `ExamplesGenerationTest` | Os três exemplos de `examples/valid/` (via Lexer e Parser, só neste teste) geram PHP válido; condições, mapeamentos e cabeçalhos dos exemplos 2 e 3 avaliados com dados simulados. Os scripts **não** são executados contra as URLs de exemplo. |

Os scripts gerados rodam em **processos PHP separados**
(`Support/GeneratedScript.php`), pois declaram funções globais e chamam
`exit()`. Com a constante `DSL_NO_AUTORUN` definida, o script carrega as
funções sem executar as integrações, o que permite testar
`dsl_condition_N`/`dsl_map_N` isoladamente.

## 7. Decisões provisórias do Gerador

Sujeitas à validação do grupo; nenhuma exigiu alterar a AST ou a gramática.

| # | Questão | Decisão provisória |
|---|---|---|
| P6 | `mappings` vazio | Envia o registro de origem **inteiro e sem alterações** (JSON preservado). |
| P7 | Funções embutidas | Apenas `env("NOME")`, com nome literal. Outras (`upper`, `lower`, `now`...) são `GenerationException` até aprovação. |
| P8 | Parâmetros de caminho | Não suportados: `{...}` em endpoint **usado** é `GenerationException`. Query string fixa no caminho é aceita. |
| P9 | Respostas em lista | Lista → item a item; objeto → um registro; outros formatos → erro. |
| P11 | `+` com textos | Concatenação se ao menos um operando for texto **em runtime**; soma se ambos forem números; outros casos → erro. |
| P12 | Alias omitido | `when`/`map` que usem campos **exigem** `as alias`, e os campos devem começar por ele. Sem campos (ou sem `when`/`map`), o alias é opcional. |
| G-1 | Métodos por posição | Origem: só `GET`. Destino: `POST`, `PUT`, `PATCH`. `DELETE` e `GET` como destino e não-`GET` como origem são `GenerationException`. |
| G-2 | Falha em um registro | Reportada; os demais registros continuam; código de saída final 1. |
| G-3 | Campos ausentes | Leitura devolve `null`; operações aritméticas/concatenação com `null` falham o registro. |
| G-4 | Lógicos | `&&`, `\|\|`, `!` e o resultado de `when` usam a conversão booleana do PHP (`null`, `0`, `""`, `"0"` são falsos). |
| G-5 | `env` vazia | Variável definida mas vazia é tratada como ausente. |

## 8. Limitações conhecidas

- Sem paginação, paralelismo, filas, *retry* ou *rate limiting*.
- Sem parâmetros de caminho nem query string dinâmica (P8).
- Sem `DELETE` (nem `GET`) como destino; origem apenas `GET`.
- Uma requisição por registro (sem envio em lote).
- A resposta do destino não é lida (só o status é verificado).
- `==` entre dois objetos JSON compara identidade, não conteúdo (listas são comparadas por valor).
- Inteiros JSON maiores que `PHP_INT_MAX` viram `float` (comportamento do `json_decode`).
- A integração do Gerador na CLI (`main.php`) ainda não foi feita.
