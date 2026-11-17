# API Integration DSL

Linguagem de Domínio Específico (DSL) para descrever integrações entre APIs HTTP
e compilá-las para código PHP executável.

Projeto acadêmico da disciplina de **Linguagens Formais e Compiladores - UNISC**.

> **Estado atual:** fundação do projeto (estrutura, CLI, testes e documentação).
> O Lexer, o Parser e o Gerador **ainda não foram implementados**. Ver
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

A sintaxe ainda está em definição. Há um esboço **não normativo** em
[docs/grammar.md](docs/grammar.md).

## Tecnologias

- PHP 8.3 (puro, sem frameworks)
- Composer (dependências e autoload PSR-4, namespace `App\` → `src/`)
- PHPUnit 12
- Docker + Docker Compose (ambiente único de desenvolvimento)
- Git

## Arquitetura

```text
Arquivo .dsl -> Lexer -> Tokens -> Parser -> AST -> PhpGenerator -> Arquivo PHP
```

| Componente | Responsabilidade |
|---|---|
| Lexer | Texto-fonte → tokens; erros léxicos |
| Parser | Tokens → AST; validação da gramática; erros sintáticos |
| AST | Representação estrutural de APIs, endpoints e integrações |
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
docker compose build                          # cria a imagem (PHP 8.3 CLI + Composer)
docker compose run --rm app php -v            # confere a versão do PHP
docker compose run --rm app composer install  # instala as dependências em vendor/
docker compose run --rm app composer test     # executa os testes
docker compose run --rm app php main.php --help
docker compose run --rm app php main.php examples/valid/exemplo1.dsl
```

Para adicionar ou atualizar dependências, também use o contêiner
(ex.: `docker compose run --rm app composer require --dev <pacote>`), após
acordo do grupo.

O projeto é montado em `/app` dentro do contêiner (bind mount): edite os
arquivos normalmente no host. Há um único serviço (`app`), sem portas expostas.

**Linux, usuário com UID diferente de 1000:** para que `vendor/` e `output/`
pertençam ao seu usuário, crie um `.env` antes do build:

```bash
printf "UID=%s\nGID=%s\n" "$(id -u)" "$(id -g)" > .env
docker compose build
```

**Windows:** para melhor desempenho do bind mount, prefira clonar o repositório
dentro do WSL 2. O `.gitattributes` mantém finais de linha LF em todas as
plataformas.

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

## Estrutura de diretórios

```text
api-integration-dsl/
├── docker/Dockerfile        imagem de desenvolvimento (PHP 8.3 CLI + Composer)
├── docs/                    arquitetura, contrato da AST, gramática, tokens
├── examples/
│   ├── valid/               programas .dsl válidos
│   └── invalid/
│       ├── lexical/         programas com erros léxicos
│       └── syntactic/       programas com erros sintáticos
├── src/
│   ├── Cli/                 CLI (App\Cli\Application)
│   ├── Lexer/               analisador léxico
│   ├── Parser/              analisador sintático
│   ├── Ast/                 nós da AST
│   ├── Generator/           gerador de código PHP
│   └── Exceptions/          exceções do compilador
├── tests/
│   ├── Unit/
│   ├── Integration/
│   └── Fixtures/
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
| Documentação de arquitetura e convenções | ✅ Pronto |
| Contrato da AST | 📝 Proposta em discussão |
| Tokens e gramática | 📝 Escopo preliminar |
| Lexer | ⏳ Não iniciado |
| Parser | ⏳ Não iniciado |
| Generator | ⏳ Não iniciado |
| Integração do pipeline na CLI | ⏳ Não iniciado |
| Exemplos `.dsl` | ⏳ Aguardando definição da sintaxe |

## Equipe e responsabilidades

| Papel | Responsabilidades | Integrante |
|---|---|---|
| Analisador léxico | Lexer, tokens, erros léxicos, `docs/tokens.md` | Bruno Comassetto |
| Analisador sintático | Parser, GLC, AST, erros sintáticos, `docs/grammar.md` | Paula Camilly da Silva |
| Gerador de código | Generator, integração das contribuições | Luis Fernando Souza Pinto |
| Integração e documentação | Testes integrados, CLI, exemplos, relatório | Pedro Augusto |
