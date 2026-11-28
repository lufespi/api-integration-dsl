# API Integration DSL

Linguagem de Domínio Específico (DSL) para descrever integrações entre APIs HTTP
e compilá-las para código PHP executável.

Projeto acadêmico da disciplina de **Linguagens Formais e Compiladores - UNISC**.

> **Estado atual:** fundação do projeto (estrutura, CLI, testes e documentação),
> gramática, Lexer, Parser, AST e Gerador de código PHP implementados
> ([docs/generator.md](docs/generator.md)). O pipeline ainda não está
> integrado à CLI. Ver
> [Estado de implementação](#estado-de-implementação).

## Objetivo acadêmico

Aplicar, em um compilador completo e de escopo reduzido, os conceitos da
disciplina:

- **análise léxica**: reconhecimento de tokens a partir do texto-fonte;
- **análise sintática**: validação por uma gramática livre de contexto (GLC)
  e construção de uma árvore sintática abstrata (AST);
- **geração de código**: tradução da AST para código PHP.

## A DSL em resumo

Um programa `.dsl` declara:

- **APIs**: identificador e endereço-base;
- **endpoints** de cada API: identificador, método HTTP e caminho;
- **integrações**: buscar dados em um endpoint de origem e enviá-los a um
  endpoint de destino.

O compilador gera um script PHP que, usando cURL, faz a requisição à origem,
interpreta a resposta JSON e encaminha os dados ao destino.

Cada integração pode ainda filtrar registros com uma condição (`when`) e montar
o registro de destino campo a campo, com tipos de dados (`map`):

```text
integration sincronizarAssinantes {
    from Crm.listContacts as contato;
    to Mailer.upsertSubscriber;
    when contato.email != null && contato.optIn;
    map {
        email: string = contato.email;
        age: int = 2026 - contato.birthYear;
    }
}
```

A gramática completa (GLC, precedência de operadores, análise LL(1) e erros
sintáticos) está em [docs/grammar.md](docs/grammar.md); exemplos em
[examples/](examples/).

## Tecnologias

- PHP 8.3 (puro, sem frameworks)
- Composer (dependências e autoload PSR-4, namespace `App\` → `src/`)
- PHPUnit 12
- Docker + Docker Compose (ambiente único de desenvolvimento)
- Git

## Arquitetura

```text
Arquivo .dsl -> Lexer -> Tokens -> Parser -> AST -> Analyzer -> PhpGenerator -> Arquivo PHP
```

| Componente | Responsabilidade |
|---|---|
| Lexer | Texto-fonte → tokens; erros léxicos |
| Parser | Tokens → AST; validação da gramática; erros sintáticos |
| AST | Representação estrutural de APIs, endpoints e integrações |
| Analyzer | AST → AST verificada; erros semânticos (nomes, referências, alias, funções) |
| Generator | AST → código PHP (cURL + JSON) |
| CLI | Coordena o pipeline e apresenta mensagens ao usuário |

Detalhes: [docs/architecture.md](docs/architecture.md) ·
contrato da AST: [docs/ast-contract.md](docs/ast-contract.md) ·
tokens: [docs/tokens.md](docs/tokens.md).

## Pré-requisitos

Apenas **Docker Desktop** (Windows/macOS) ou **Docker Engine + plugin Compose**
(Linux), e Git.

**Todo o desenvolvimento é feito via Docker**: PHP, Composer e PHPUnit rodam
somente dentro do contêiner `app`. Não é necessário — nem recomendado — usar
PHP ou Composer instalados na máquina; assim todos os integrantes usam
exatamente as mesmas versões.

## Instalação e uso

Os comandos são os mesmos no Linux (bash/zsh) e no Windows (PowerShell).

```bash
docker compose build                          # cria a imagem (PHP 8.3 CLI + Composer 2)
docker compose run --rm app php -v            # confere a versão do PHP
docker compose run --rm app composer install  # instala as dependências do composer.lock em vendor/
docker compose run --rm app composer test     # executa os testes
docker compose run --rm app php main.php --help
docker compose run --rm app php main.php examples/valid/exemplo1.dsl
```

Rode `composer install` de novo sempre que o `composer.lock` mudar (por
exemplo, depois de um `git pull`). Rode `docker compose build` de novo quando
`docker/Dockerfile` mudar.

Verificações extras, as mesmas que o CI executa:

```bash
docker compose run --rm app composer validate --strict      # composer.json válido e composer.lock sincronizado
docker compose run --rm app composer check-platform-reqs    # PHP e extensões exigidos pelas dependências
docker compose run --rm app php -m                          # lista as extensões disponíveis
```

A imagem oficial `php:8.3-cli` já inclui todas as extensões usadas pelo
projeto, pelo PHPUnit e pelo futuro código gerado: `curl`, `dom`, `json`,
`libxml`, `mbstring`, `tokenizer`, `xml` e `xmlwriter`. O Dockerfile só
acrescenta `git` e `unzip`, que o Composer usa para baixar e extrair pacotes.

Para adicionar ou atualizar dependências, também use o contêiner
(ex.: `docker compose run --rm app composer require --dev <pacote>`), após
acordo do grupo, e versione `composer.json` e `composer.lock` no mesmo commit.

O projeto é montado em `/app` dentro do contêiner (bind mount): edite os
arquivos normalmente no host. Há um único serviço (`app`), sem portas expostas.

O contêiner roda com um usuário não-root (`app`), com UID/GID configuráveis.

**Linux (Docker Engine + Compose):** se o seu usuário não tiver UID 1000
(confira com `id -u`), crie um `.env` antes do build. Assim `vendor/` e
`output/` pertencem ao seu usuário, e não ao root:

```bash
printf "UID=%s\nGID=%s\n" "$(id -u)" "$(id -g)" > .env
docker compose build
```

**Windows (Docker Desktop):**

- Use o Docker Desktop com o backend WSL 2 (padrão nas versões atuais).
- Não é preciso criar `.env`; os valores padrão funcionam.
- Para melhor desempenho do bind mount, clone o repositório dentro do sistema
  de arquivos do WSL (ex.: `~/projetos`, não `C:\...`) e rode os comandos no
  terminal do WSL. Pelo PowerShell os comandos também funcionam, porém mais
  lentos.
- O `.gitattributes` força finais de linha LF, então não é preciso ajustar o
  `core.autocrlf`.

**Se o build falhar com `Temporary failure resolving 'deb.debian.org'`** (ou o
`composer install` não alcançar o Packagist): o DNS que o Docker herdou da
máquina não responde dentro dos contêineres. É configuração da máquina, não do
projeto. No Linux, defina DNS públicos para o Docker e reinicie-o (isso
interrompe os contêineres em execução):

```bash
echo '{ "dns": ["1.1.1.1", "8.8.8.8"] }' | sudo tee /etc/docker/daemon.json
sudo systemctl restart docker
```

No Docker Desktop, o mesmo JSON vai em *Settings → Docker Engine*.

O Docker é o ambiente de **desenvolvimento** do compilador. O código PHP gerado
pelo compilador é um script autônomo e pode rodar em qualquer PHP com as
extensões `curl` e `json`, com ou sem Docker.

### Linha de comando

```text
docker compose run --rm app php main.php <arquivo.dsl>
docker compose run --rm app php main.php --help
```

| Código de saída | Significado |
|---|---|
| 0 | Sucesso ou ajuda exibida |
| 1 | Erro de compilação (léxico/sintático) — reservado |
| 2 | Uso incorreto ou arquivo de entrada inválido |
| 3 | Funcionalidade ainda não integrada |

Enquanto o pipeline não estiver integrado, a CLI valida o arquivo de entrada e
informa que a compilação ainda não está disponível (código 3). Nenhum arquivo é
gerado.

## Testes

```bash
docker compose run --rm app composer test               # todos os testes
docker compose run --rm app composer test:unit          # apenas unitários
docker compose run --rm app composer test:integration   # apenas integração (CLI ponta a ponta)
```

Os testes não acessam a rede nem APIs externas.

## Integração contínua (GitHub Actions)

O workflow [`.github/workflows/ci.yaml`](.github/workflows/ci.yaml) roda
automaticamente em todo **Pull Request para `main`** e em todo **push para
`main`**. O resultado aparece na aba *Checks* do PR e na aba *Actions* do
repositório.

O CI roda direto no runner Ubuntu do GitHub, sem Docker, com PHP 8.3 e as
mesmas extensões da imagem de desenvolvimento. Assim ele fica mais simples e
rápido; o Docker continua sendo o ambiente padrão local.

| Passo | Comando | Falha quando |
|---|---|---|
| 1. Checkout | — | — |
| 2. PHP 8.3 + Composer 2 | `setup-php` | a action não consegue preparar o PHP (raro; rode de novo) |
| 3. Validar Composer | `composer validate --strict` | `composer.json` inválido ou `composer.lock` desatualizado em relação a ele |
| 4. Instalar dependências | `composer install` | `composer.lock` incompatível com PHP 8.3 ou pacote indisponível |
| 5. Requisitos de plataforma | `composer check-platform-reqs` | falta extensão ou versão de PHP exigida por alguma dependência |
| 6. Testes | `composer test` | algum teste PHPUnit falhou, gerou erro, aviso ou foi marcado como arriscado |

O PR só deve ser integrado com o check **CI** verde. O workflow usa só
permissão de leitura (`contents: read`), não usa secrets e não publica nada.

**Como interpretar uma falha:**

1. No PR, clique em *Details* ao lado do check **CI** e abra o passo marcado
   com ❌. O log mostra o comando e a mensagem de erro.
2. Reproduza localmente com o mesmo comando no contêiner, por exemplo
   `docker compose run --rm app composer test`.
3. Causas mais comuns:
   - **passo 3:** alguém editou o `composer.json` sem atualizar o lock. Rode
     `docker compose run --rm app composer update --lock` e versione o
     `composer.lock`;
   - **passo 6:** leia o nome do teste e a asserção que falhou no log; o
     PHPUnit mostra o valor esperado e o obtido.
4. Corrija, faça push na mesma branch e o CI roda de novo sozinho.

## Estrutura de diretórios

```text
api-integration-dsl/
├── .github/workflows/ci.yaml  integração contínua (GitHub Actions)
├── docker/Dockerfile        imagem de desenvolvimento (PHP 8.3 CLI + Composer)
├── docs/                    arquitetura, contrato da AST, gramática, tokens
├── examples/
│   ├── valid/               programas .dsl válidos
│   └── invalid/
│       ├── lexical/         programas com erros léxicos
│       ├── syntactic/       programas com erros sintáticos
│       └── semantic/        programas com erros semânticos
├── src/
│   ├── Cli/                 CLI (App\Cli\Application)
│   ├── Lexer/               analisador léxico
│   ├── Parser/              analisador sintático
│   ├── Ast/                 nós da AST
│   ├── Semantic/            analisador semântico
│   ├── Generator/           gerador de código PHP
│   └── Exceptions/          exceções do compilador
├── tests/
│   ├── Unit/
│   ├── Integration/
│   ├── Fixtures/
│   └── Support/             auxiliares de teste (impressão de expressões)
├── output/                  código PHP gerado (não versionado)
├── main.php                 ponto de entrada da CLI
├── compose.yaml
├── composer.json
├── phpunit.xml
└── README.md
```

## Estado de implementação

| Item | Situação |
|---|---|
| Estrutura do projeto, Composer, PSR-4 | ✅ Pronto |
| PHPUnit e testes de infraestrutura | ✅ Pronto |
| CLI: ajuda, validação de argumentos, códigos de saída | ✅ Pronto |
| Ambiente Docker de desenvolvimento | ✅ Pronto |
| Integração contínua (GitHub Actions) | ✅ Configurada |
| Documentação de arquitetura e convenções | ✅ Pronto |
| Gramática (GLC) e especificação do Parser | ✅ Pronto ([docs/grammar.md](docs/grammar.md)) |
| Parser (descendente recursivo LL(1), erros sintáticos) | ✅ Pronto (`src/Parser`) |
| AST | ✅ Implementada (`src/Ast`); consumida pelo Generator sem alterações |
| Contrato de tokens (`Token`, `TokenType`) | ✅ Pronto (`src/Lexer`) |
| Lexer (escrito à mão, erros léxicos) | ✅ Pronto (`src/Lexer`, [docs/tokens.md](docs/tokens.md)) |
| Analisador semântico (`Analyzer`, erros semânticos) | ✅ Implementado (`src/Semantic`, [docs/semantic-analysis.md](docs/semantic-analysis.md)); aguarda validação do grupo |
| Generator (AST → PHP com cURL, `when`, `map`, cabeçalhos) | ✅ Pronto, com correções pós-code review (`src/Generator`, [docs/generator.md](docs/generator.md)); decisões provisórias aguardam o grupo |
| Integração do pipeline na CLI | ⏳ Não iniciado |
| Exemplos `.dsl` válidos e com erros sintáticos | ✅ Prontos (`examples/valid`, `examples/invalid/syntactic`) |
| Exemplos `.dsl` com erros léxicos | ✅ Prontos (`examples/invalid/lexical`) |
| Exemplos `.dsl` com erros semânticos | ✅ Prontos (`examples/invalid/semantic`) |

## Equipe e responsabilidades

| Papel | Responsabilidades | Integrante |
|---|---|---|
| Analisador léxico | Lexer, tokens, erros léxicos, `docs/tokens.md` | Bruno Comassetto |
| Analisador sintático | Parser, GLC, AST, erros sintáticos, `docs/grammar.md` | Paula Camilly da Silva |
| Gerador de código | Generator, integração das contribuições | Luis Fernando Souza Pinto |
| Integração e documentação | Testes integrados, CLI, exemplos, relatório | Pedro Augusto |
