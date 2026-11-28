# API Integration DSL

Linguagem de domínio específico (DSL) para descrever integrações entre APIs
HTTP e compilador que a traduz para um script PHP executável. Trabalho da
disciplina de **Linguagens Formais e Compiladores — UNISC**.

Um programa `.dsl` declara APIs, seus endpoints e integrações que buscam
registros em um endpoint de origem, filtram (`when`), transformam (`map`) e
enviam os dados a um endpoint de destino. O compilador aplica análise léxica,
sintática (LL(1)) e semântica e gera PHP que faz as requisições com cURL.

## Integrantes

| Integrante | Contribuição |
|---|---|
| Bruno Comassetto | Analisador léxico: Lexer, tokens, erros léxicos e [docs/tokens.md](docs/tokens.md) |
| Paula Camilly da Silva | Analisador sintático: gramática (GLC), Parser LL(1), AST, erros sintáticos e [docs/grammar.md](docs/grammar.md) |
| Luis Fernando Souza Pinto | Gerador de código PHP e integração das contribuições |
| Pedro Augusto | Analisador semântico, CLI, exemplos, testes integrados e relatório |

## Tecnologias

PHP 8.3 (sem frameworks), Composer (autoload PSR-4, `App\` → `src/`),
PHPUnit 12, Docker com Docker Compose e GitHub Actions.

## Arquitetura

```text
arquivo .dsl → Lexer → tokens → Parser → AST → Analyzer → PhpGenerator → output/<nome>.php
```

| Componente | Pasta | Responsabilidade |
|---|---|---|
| Lexer | `src/Lexer` | Texto-fonte → lista de tokens; erros léxicos |
| Parser | `src/Parser` | Tokens → AST, por descida recursiva LL(1); erros sintáticos com recuperação em modo pânico |
| AST | `src/Ast` | Nós imutáveis (`final readonly`) que representam APIs, endpoints, integrações e expressões |
| Analyzer | `src/Semantic` | Verifica nomes, referências, alias e funções sobre a AST; erros semânticos |
| Generator | `src/Generator` | AST → código PHP autônomo (cURL + JSON) |
| CLI | `main.php`, `src/Cli` | Valida argumentos, coordena o pipeline, grava a saída e define o código de saída |

Cada etapa conhece apenas a saída da anterior:

```php
$tokens  = (new Lexer($source))->tokenize();        // list<Token>
$program = (new Parser($tokens))->parse();          // App\Ast\Program
$program = (new Analyzer())->analyze($program);     // o mesmo Program, verificado
$php     = (new PhpGenerator())->generate($program); // string
```

Os erros do programa são subclasses de `App\Exceptions\CompilerException`, com
linha e coluna no fonte (`getSourceLine()`/`getSourceColumn()`):

| Exceção | Lançada por | Observação |
|---|---|---|
| `LexicalException` | Lexer | Para no primeiro erro |
| `SyntaxException` | Parser | Reúne todos os erros (`errors()`), graças à recuperação em modo pânico |
| `SemanticException` | Analyzer | Reúne todos os erros, ordenados por posição |
| `GenerationException` | Generator | Restrições da geração (métodos, URLs, cabeçalhos, parâmetros de caminho) |

Violações de contrato entre etapas (por exemplo, lista de tokens sem `EOF`)
não são erros do programa: lançam `InvalidArgumentException`/`LogicException`
e a CLI as relata como erro interno.

## Pré-requisitos

- **Docker** (recomendado): Docker Desktop ou Docker Engine com o plugin
  Compose, e Git.
- **PHP local** (alternativa): PHP 8.3+ com as extensões `mbstring`, `curl`,
  `dom`, `xml`, `xmlwriter` e `tokenizer`, e Composer 2.

O script gerado precisa apenas de PHP 8.3+ com `curl` e `json`.

## Instalação

Com Docker:

```bash
docker compose build
docker compose run --rm app composer install
```

No Linux, se o seu usuário não tiver UID 1000, crie o `.env` antes do build,
para que `vendor/` e `output/` pertençam a você:

```bash
printf "UID=%s\nGID=%s\n" "$(id -u)" "$(id -g)" > .env
```

Com PHP local:

```bash
composer install
```

## Uso

```bash
docker compose run --rm app php main.php examples/valid/exemplo1.dsl   # Docker
php main.php examples/valid/exemplo1.dsl                               # PHP local
php main.php --help
```

```text
Compilação concluída: examples/valid/exemplo1.dsl -> output/exemplo1.php
```

O PHP gerado é gravado em `output/<nome-do-arquivo>.php` de forma atômica
(arquivo temporário + renomeação). A compilação não executa o script nem faz
requisições HTTP. Em caso de erro, a mensagem vai para stderr, nenhum arquivo
é gravado e a saída de uma compilação anterior com o mesmo nome é removida:

```text
$ php main.php examples/invalid/syntactic/04-multiplos-erros.dsl
2 erros sintáticos encontrados:
  - Erro sintático na linha 11, coluna 24: esperado método HTTP (GET, POST, PUT, PATCH ou DELETE, em maiúsculas) no endpoint 'listUsers', encontrado: identificador 'get'
  - Erro sintático na linha 23, coluna 15: esperado tipo de dado (string, int, float ou bool) para o campo 'nome', encontrado: identificador 'text'
Nenhum arquivo PHP foi gerado.
```

| Código de saída | Significado |
|---|---|
| 0 | Compilação concluída ou ajuda exibida |
| 1 | Erro léxico, sintático, semântico ou de geração, ou falha de leitura/escrita |
| 2 | Uso incorreto ou arquivo de entrada inválido |

## Exemplos da DSL

```text
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

Em [examples/](examples/):

- `valid/`: `exemplo1.dsl` (integração mínima), `exemplo2.dsl` (cabeçalhos,
  `env` e `when`) e `exemplo3.dsl` (`map` com os quatro tipos e duas integrações);
- `invalid/lexical/`, `invalid/syntactic/` e `invalid/semantic/`: um erro por
  arquivo (ou vários, nos de múltiplos erros), com a mensagem esperada nas
  linhas `// Erro esperado:`, conferidas pelos testes.

A especificação formal está em [docs/grammar.md](docs/grammar.md) e a léxica em
[docs/tokens.md](docs/tokens.md).

## Execução do PHP gerado

```bash
CRM_TOKEN=... php output/exemplo3.php
docker compose run --rm -e CRM_TOKEN=... app php output/exemplo3.php
```

As variáveis usadas em `env("...")` devem estar definidas no ambiente. Os
endereços dos exemplos são fictícios (`example.com`); para uma execução real,
aponte `base` para um serviço existente. Saída ilustrativa:

```text
[sincronizarAssinantes] GET https://crm.example.com/v1/contacts -> PUT https://mailer.example.com/api/subscribers
[sincronizarAssinantes] 3 registro(s) recebido(s): 2 enviado(s), 1 ignorado(s) pela condição, 0 com falha.
Concluído: 2 integração(ões) sem falhas.
```

Erros vão para stderr como `[integração] ERRO: ...`. O script termina com 0
sem falhas e com 1 se qualquer integração falhar. Com a constante
`DSL_NO_AUTORUN` definida antes de incluí-lo, o script apenas declara suas
funções (usado nos testes).

O script contém, nesta ordem: a biblioteca de runtime (`dsl_http_request`,
`dsl_field`, `dsl_add`, ...), uma função `dsl_api_headers_N` por API usada, as
funções `dsl_condition_N`, `dsl_map_N` e `dsl_integration_N` de cada
integração e a chamada a `dsl_main()`. A mesma AST produz sempre o mesmo texto.

## Testes

```bash
docker compose run --rm app composer test               # todos
docker compose run --rm app composer test:unit
docker compose run --rm app composer test:integration   # CLI ponta a ponta
docker compose run --rm app composer validate --strict
docker compose run --rm app composer check-platform-reqs
```

Com PHP local, os mesmos comandos sem o prefixo `docker compose run --rm app`.
Os testes não acessam a rede externa: os scripts gerados são executados contra
um servidor HTTP local (`php -S` em `127.0.0.1`). O workflow
[.github/workflows/ci.yaml](.github/workflows/ci.yaml) executa a validação do
Composer e a suíte em todo pull request e push para `main`.

## Funcionalidades e limitações

### Execução de uma integração

1. Calcula os cabeçalhos da API de origem e os da API de destino; cada API só
   recebe os próprios cabeçalhos, então credenciais nunca passam de uma API a
   outra. Falha de configuração (ex.: `env` ausente ou vazia) interrompe a
   integração antes de qualquer requisição.
2. Faz `GET` em `baseUrl + path` e decodifica o JSON: um objeto é um registro;
   uma lista é um registro por item (cada item deve ser objeto).
3. Para cada registro, avalia `when`; monta o payload com `map` (ou envia o
   registro original, sem `map`) e o envia ao destino com `POST`, `PUT` ou
   `PATCH` e body JSON.
4. Falha em um registro é reportada e os demais continuam; falha na origem
   interrompe só aquela integração.

### Expressões

| DSL | Semântica em execução |
|---|---|
| `c.a.b` | Campo aninhado do registro; ausente (ou caminho que atravessa um não objeto) → `null` |
| `env("X")` | Variável de ambiente obrigatória; o nome é sempre literal |
| `&&`, `\|\|`, `!` | Operadores do PHP: curto-circuito e conversão booleana (`null`, `0`, `""`, `"0"` são falsos) |
| `==`, `!=` | Números pelo valor (`100 == 100.0`); demais valores estritamente (`"1" != 1`) |
| `<`, `<=`, `>`, `>=` | Dois números ou dois textos; outros pares são erro |
| `+` | Concatenação se algum operando for texto em execução; senão, soma |
| `-`, `*`, `/`, `%` | Exigem números; divisão por zero é erro; `%` com decimais usa `fmod` |
| `-x` | Exige número |

Cada nó vira uma chamada de função ou uma expressão entre parênteses, então a
precedência e a associatividade são exatamente as da AST. Em texto, decimais
usam a menor representação que preserva o valor (`100.0` → `"100"`) e
booleanos viram `"true"`/`"false"`. Resultados não finitos (`INF`, `NAN`) e
números JSON fora do intervalo de `float` são erro no registro.

### Conversões do `map`

| Tipo | Aceita | Rejeita |
|---|---|---|
| `string` | texto, número, booleano | `null`, lista, objeto |
| `int` | inteiro, decimal sem parte fracionária, texto inteiro | `3.7`, `"abc"`, `null`, booleano |
| `float` | número, texto numérico | `"abc"`, `null`, booleano, valor não finito |
| `bool` | booleano, `1`/`0`, `"true"`/`"false"`/`"1"`/`"0"` | outros valores |

Conversões que perderiam informação são erros, não truncamentos.

### HTTP e segurança

- Origem apenas `GET`; destino `POST`, `PUT` ou `PATCH`. Outros métodos em
  endpoints usados são `GenerationException`.
- `Content-Type: application/json` é adicionado ao body, exceto se a API já
  declarar `Content-Type`. Timeouts: 10 s de conexão e 30 s por requisição.
- Somente status `2xx` é sucesso; redirecionamentos não são seguidos; a
  verificação TLS do cURL é mantida e só `http`/`https` são aceitos.
- Inteiros JSON maiores que `PHP_INT_MAX` são lidos como texto, preservando
  os dígitos.
- Nenhum texto da AST vira código: strings e nomes são emitidos com
  `var_export`, e texto em comentários é neutralizado. Não há `eval`,
  `include` nem comandos de sistema no script gerado.
- Os logs ocultam a query string das URLs (`?[REDACTED]`) e nunca exibem
  valores de cabeçalhos ou de variáveis de ambiente. Valores de cabeçalho com
  quebra de linha são rejeitados.
- O Generator valida o endereço-base (`http`/`https`, host e porta válidos, sem
  credenciais, query string ou fragmento), o caminho (início com `/`, sem
  fragmento, espaços nem `{...}`) e os nomes de cabeçalho. Também repete, de
  forma defensiva, as verificações do Analyzer, para ASTs montadas sem ele.

### Limitações

- Sem parâmetros de caminho (`/users/{id}`) nem query string dinâmica; uma
  query fixa no caminho é aceita.
- Sem paginação, envio em lote, paralelismo, *retry* ou *rate limiting*; uma
  requisição por registro, e a resposta do destino não é lida.
- Os tipos do `map` não são verificados na compilação (`age: int = "abc"` só
  falha em execução).
- Inteiros JSON acima de `PHP_INT_MAX` são reenviados como texto e não
  participam de aritmética (com `+`, são concatenados).
- `==` entre dois objetos JSON compara identidade, não conteúdo.
- O registro reenviado sem `map` tem os mesmos campos e valores, mas não é
  byte a byte idêntico ao recebido.

## Estrutura

```text
docs/        gramática (grammar.md) e especificação léxica (tokens.md)
examples/    programas válidos e inválidos (lexical/, syntactic/, semantic/)
src/         Lexer/, Parser/, Ast/, Semantic/, Generator/, Cli/, Exceptions/
tests/       Unit/, Integration/, Fixtures/, Support/
output/      PHP gerado (não versionado)
docker/      imagem de desenvolvimento
main.php     ponto de entrada da CLI
```
