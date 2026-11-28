# Gerador de Código PHP

> **Status: IMPLEMENTADO** em [`src/Generator/`](../src/Generator/) (2026-11-26).
> Inclui as correções pós-code review (URLs, inteiros grandes, sanitização de
> logs, valores não finitos).
> Responsável: integrante do **Gerador de código**. Consome a AST definida em
> [`ast-contract.md`](ast-contract.md). Integrado à CLI, depois do Analisador
> semântico ([`semantic-analysis.md`](semantic-analysis.md)).
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

Uso na CLI (`src/Cli/Application.php`), que grava o resultado em
`output/<nome-do-arquivo>.php`:

```php
$tokens  = (new Lexer($source))->tokenize();
$program = (new Parser($tokens))->parse();
$program = (new Analyzer())->analyze($program);        // SemanticException em caso de erro
$php     = (new PhpGenerator())->generate($program);   // GenerationException em caso de erro
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
2. `GET` na URL de origem (`baseUrl` + `path`, seção 3.6); decodifica o JSON.
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
| Falha de conexão | Mensagem com método, URL sanitizada e a descrição genérica do código de erro do cURL (`curl_strerror`), ex.: `Could not connect to server (cURL 7)` (o texto depende da versão do libcurl). |
| JSON da origem | Decodificado como objetos (`stdClass`), mantendo a distinção `{}`/`[]` ao repassar o registro, e com `JSON_BIGINT_AS_STRING` (seção 3.7). JSON inválido é erro. |
| Logs | URLs aparecem com a query string inteira ocultada (`/users?[REDACTED]`), em sucesso e em falha. A URL enviada ao cURL não é alterada. |
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

**Texto de valores.** Na concatenação e no tipo `string` do `map`, inteiros
viram texto diretamente e decimais usam a menor representação que preserva o
valor (`1.5` → `"1.5"`, `100.0` → `"100"`, `1234567890.123456` →
`"1234567890.123456"`), independentemente da configuração `precision` do PHP;
booleanos viram `"true"`/`"false"`; `null`, listas e objetos não podem ser
concatenados (erro).

### 3.4 Mapeamento (`map`) e conversão de tipos (`dsl_convert`)

Cada `FieldMapping` vira uma entrada `'campo' => dsl_convert(valor, 'tipo', 'campo')`;
o payload é um objeto JSON (mesmo com campos de nome numérico).

| Tipo declarado | Aceita | Rejeita (erro no registro) |
|---|---|---|
| `string` | texto; número (`1990` → `"1990"`); booleano (`"true"`/`"false"`) | `null`, lista, objeto |
| `int` | inteiro; decimal sem parte fracionária (`36.0` → `36`); texto inteiro (`"42"`) | `3.7`, `"abc"`, `null`, booleano |
| `float` | inteiro ou decimal (`36` → `36.0`); texto numérico | `"abc"`, `null`, booleano, resultado não finito (`"1e400"`) |
| `bool` | booleano; `1`/`0`; `"true"`/`"false"`/`"1"`/`"0"` | outros valores, `null` |

Conversões que perderiam informação são erros, não truncamentos silenciosos.
Valores não finitos (`INF`, `-INF`, `NAN`) são rejeitados para qualquer tipo,
com a mensagem `campo "x": o valor não é um número finito` (seção 3.8).

### 3.5 Cabeçalhos (`header`)

- Cada API gera sua função `dsl_api_headers_N()`; a origem recebe só os
  cabeçalhos da API de origem e o destino só os da API de destino.
  **Credenciais nunca são repassadas de uma API à outra.**
- Valores são expressões avaliadas uma vez por integração, antes das
  requisições; o resultado deve ser texto ou número.
- Valores com quebra de linha são rejeitados (injeção de cabeçalho).
- Nomes de cabeçalho são validados na geração (caracteres de *token* HTTP).

### 3.6 Composição e validação de URLs

A URL de cada endpoint usado é `baseUrl` (sem a barra final) + `path`. Para
que a concatenação sempre produza uma URL correta, o Gerador valida as duas
partes com `parse_url()` e verificações complementares:

| Parte | Aceita | Rejeita |
|---|---|---|
| Endereço-base | `http`/`https` (qualquer caixa); host por nome, `localhost`, IPv4 ou IPv6 entre colchetes (`[::1]`); porta 1–65535; caminho (`/v1`) | credenciais (`user:pass@`, `token@`), query string e fragmento (inclusive `?` ou `#` vazios), outros protocolos, URL relativa, host inválido (`bad_host`, `999.1.1.1`), porta vazia/fora do intervalo, espaços e caracteres de controle |
| Caminho do endpoint | início com `/`; query string fixa (`/users?active=1`) | fragmento (`#`), espaços, caracteres de controle, parâmetros `{...}` (P8) |

Exemplo do code review: `https://api.example.com?x=1` + `/users` geraria
`https://api.example.com?x=1/users`; agora é `GenerationException` na
posição da API, orientando a mover a query para o caminho do endpoint.

### 3.7 Inteiros JSON grandes

A resposta da origem é decodificada com `JSON_THROW_ON_ERROR |
JSON_BIGINT_AS_STRING`. Inteiros dentro do intervalo do PHP continuam
`int`; inteiros fora dele (ex.: identificadores `12345678901234567890`)
viram **texto**, preservando todos os dígitos em vez de serem arredondados
para `float`.

Consequência (decisão do runtime, G-6): ao reenviar o registro sem `map`,
esses valores saem como texto JSON (`"id":"12345678901234567890"`), não como
número. No `map`, `string` preserva os dígitos e `int` rejeita o valor
(fora do intervalo); `float` converte com a perda de precisão própria do tipo.
Em expressões, o valor se comporta como qualquer texto: `-`, `*`, `/`, `%` e
comparações com números são erro, mas `+` **concatena** (regra P11), então
`r.id + 1` resulta em `"123456789012345678901"`, e não em uma soma.

### 3.8 Valores numéricos não finitos

`INF`, `-INF` e `NAN` não são representáveis em JSON. Proteção mínima:

- número JSON fora do intervalo de `float` (ex.: `1e400`, que `json_decode`
  transforma em `INF`) → erro ao ler o campo (`o campo "v" contém um número
  fora do intervalo suportado`);
- operação aritmética com resultado não finito (overflow) → erro
  (`o operador "*" produziu um resultado fora do intervalo numérico suportado`);
- `dsl_convert` → erro identificando o campo do `map`;
- literal decimal não finito no programa → `GenerationException`.

Como os demais erros de runtime, esses afetam só o registro em questão.

## 4. Verificações feitas na geração

O Parser garante a sintaxe e o `Analyzer` as regras semânticas
([`semantic-analysis.md`](semantic-analysis.md)); no pipeline da CLI,
referências, duplicidades, alias e funções inválidas já chegam como erro
semântico. O Gerador mantém as verificações abaixo de forma **defensiva**
(para ASTs montadas sem o Analyzer) e é o único a verificar as restrições
próprias da geração (métodos HTTP por posição, parâmetros de caminho, URLs,
nomes de cabeçalho e literais não finitos). Todas lançam
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
| Endereço-base inválido (seção 3.6) | `o endereço-base da API 'X' deve ser uma URL http:// ou https:// válida: não pode conter query string ('?') nem fragmento ('#'); ...` |
| Caminho inválido | `o caminho do endpoint 'x' deve começar com '/' e não conter espaços, caracteres de controle nem fragmento ('#')` |
| Literal decimal não finito | `literal decimal fora do intervalo numérico suportado (não é um número finito)` |
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
- URLs nos logs têm a query string ocultada (`?[REDACTED]`), pois ela pode
  conter tokens; erros de conexão usam a descrição genérica do cURL, que não
  repete a URL. Credenciais embutidas na URL (`user:pass@`) são rejeitadas
  na geração.
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
| `ExpressionGenerationTest` | Literais, operadores, precedência, `+`, campos aninhados/ausentes, `env`, funções inválidas, regras de alias, valores não finitos, `dsl_convert` com inteiros fora do intervalo, `dsl_safe_url`. As expressões traduzidas são **executadas** com o runtime real sobre registros simulados. |
| `PhpGeneratorTest` | `php -l`, URLs e métodos, resolução de referências, várias integrações, determinismo, ausência de construções perigosas, escape de textos hostis, cabeçalhos, todas as `GenerationException`, URLs válidas e inválidas (seção 3.6). |
| `GeneratedCodeTest` | Script completo executado contra um **servidor HTTP local** (`php -S` em `127.0.0.1`, `Support/LocalHttpServer.php`): lista/objeto, `when`, `map`, PUT/PATCH, cabeçalhos por API, `env` ausente, status HTTP, JSON inválido, formato incompatível, falha de conexão, várias integrações, código de saída, inteiros grandes, segredos na query string fora dos logs (em sucesso e em falha), valores não finitos falhando só o próprio registro. |
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
| P6 | `mappings` vazio | Envia o registro de origem **inteiro**, com os mesmos campos e valores; a representação numérica pode mudar (inteiros acima de `PHP_INT_MAX` viram texto, decimais são reserializados pelo PHP). |
| P7 | Funções embutidas | Apenas `env("NOME")`, com nome literal; o `Analyzer` usa a mesma lista (`Analyzer::DEFAULT_FUNCTIONS`) e rejeita as demais (`upper`, `lower`, `now`...) como erro semântico. |
| P8 | Parâmetros de caminho | Não suportados: `{...}` em endpoint **usado** é `GenerationException`. Query string fixa no caminho é aceita. |
| P9 | Respostas em lista | Lista → item a item; objeto → um registro; outros formatos → erro. |
| P11 | `+` com textos | Concatenação se ao menos um operando for texto **em runtime**; soma se ambos forem números; outros casos → erro. |
| P12 | Alias omitido | `when`/`map` que usem campos **exigem** `as alias`, e os campos devem começar por ele. Sem campos (ou sem `when`/`map`), o alias é opcional. |
| G-1 | Métodos por posição | Origem: só `GET`. Destino: `POST`, `PUT`, `PATCH`. `DELETE` e `GET` como destino e não-`GET` como origem são `GenerationException`. |
| G-2 | Falha em um registro | Reportada; os demais registros continuam; código de saída final 1. |
| G-3 | Campos ausentes | Leitura devolve `null`; operações aritméticas/concatenação com `null` falham o registro. |
| G-4 | Lógicos | `&&`, `\|\|`, `!` e o resultado de `when` usam a conversão booleana do PHP (`null`, `0`, `""`, `"0"` são falsos). |
| G-5 | `env` vazia | Variável definida mas vazia é tratada como ausente. |
| G-6 | Inteiros JSON grandes | `JSON_BIGINT_AS_STRING`: dígitos preservados, mas o valor passa a ser **texto** no JSON encaminhado (seção 3.7). |
| G-7 | Query string nos logs | Ocultada por inteiro, sem lista de parâmetros sensíveis. |
| G-8 | Valores não finitos | Rejeitados ao ler campos, ao final de cada operação aritmética, na conversão do `map` e em literais. |

## 8. Limitações conhecidas

- Sem paginação, paralelismo, filas, *retry* ou *rate limiting*.
- Sem parâmetros de caminho nem query string dinâmica (P8).
- Sem `DELETE` (nem `GET`) como destino; origem apenas `GET`.
- Uma requisição por registro (sem envio em lote).
- A resposta do destino não é lida (só o status é verificado).
- `==` entre dois objetos JSON compara identidade, não conteúdo (listas são comparadas por valor).
- Inteiros JSON maiores que `PHP_INT_MAX` são encaminhados como texto (`"9223372036854775808"`),
  não podem ser convertidos para `int` nem usados com `-`, `*`, `/`, `%`; com `+`
  são concatenados como texto (seção 3.7). Não há aritmética de precisão arbitrária.
- O registro reenviado sem `map` tem os mesmos campos e valores, mas não é
  byte a byte idêntico ao recebido (espaços, ordem de escape e formato numérico podem mudar).
- A compatibilidade de `tests/Unit/AutoloadTest.php` com Windows foi corrigida
  por inspeção (normalização de caminhos), sem execução em Windows.
