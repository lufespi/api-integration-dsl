# Arquitetura

Este documento descreve a organização do compilador **API Integration DSL** e
as fronteiras entre os componentes desenvolvidos por cada integrante.

> Itens marcados como **proposta** ainda dependem de validação do grupo.

## 1. Pipeline

```text
Arquivo .dsl
    |
    v
Lexer              (src/Lexer)       texto-fonte  -> lista de tokens
    |
    v
Tokens
    |
    v
Parser             (src/Parser)      tokens       -> AST (valida a gramática)
    |
    v
AST                (src/Ast)         representação estrutural do programa
    |
    v
PhpGenerator       (src/Generator)   AST          -> código PHP (string)
    |
    v
Arquivo PHP gerado (output/)
```

A **CLI** (`main.php` + `src/Cli`) coordena o pipeline: lê o arquivo, chama
cada etapa em ordem, grava o resultado em `output/` e traduz erros em mensagens
e códigos de saída.

Cada etapa conhece apenas a saída da etapa anterior. O Generator não lê tokens
nem texto-fonte; o Parser não gera código. Isso permite que cada integrante
desenvolva e teste seu componente de forma isolada.

## 2. Responsabilidades

| Componente | Entrada | Saída | Responsável por | Não é responsável por |
|---|---|---|---|---|
| **Lexer** | `string` (texto-fonte) | lista de tokens | reconhecer lexemas, descartar espaços/comentários, registrar linha/coluna, reportar erros léxicos | verificar a ordem dos tokens |
| **Parser** | lista de tokens | AST (`Program`) | aplicar a GLC, construir a AST, reportar erros sintáticos com posição | ler arquivos, gerar código |
| **AST** | — | — | representar APIs, endpoints e integrações de forma estrutural (contrato Parser → Generator) | lógica de análise ou geração |
| **Generator** | AST válida | `string` com código PHP | produzir código PHP com cURL que executa a integração | validar sintaxe, executar o código gerado |
| **CLI** | argumentos da linha de comando | arquivo em `output/`, mensagens, código de saída | validar argumentos, coordenar o pipeline, exibir erros | regras da linguagem |

## 3. Interfaces entre etapas (proposta)

As assinaturas abaixo servem de ponto de partida para a integração. Cada
responsável pode ajustá-las, desde que combine com quem consome sua saída.

```php
// Lexer
$tokens = (new Lexer($source))->tokenize();        // list<Token>

// Parser
$program = (new Parser($tokens))->parse();         // AST (ver docs/ast-contract.md)

// Generator
$php = (new PhpGenerator())->generate($program);   // string
```

O contrato detalhado da AST está em [`ast-contract.md`](ast-contract.md); os
tokens, em [`tokens.md`](tokens.md); a gramática, em [`grammar.md`](grammar.md).

## 4. Tratamento de erros

**Proposta:** Lexer e Parser lançam exceções específicas, definidas em
`src/Exceptions/`, contendo mensagem, linha e coluna. A CLI captura essas
exceções e encerra com o código de saída adequado.

Hierarquia sugerida (ainda **não implementada**, para não antecipar decisões dos
responsáveis):

```text
CompilerException                 (base, contém linha/coluna opcionais)
├── LexicalException              (ex.: caractere inválido, string não terminada)
└── SyntaxException               (ex.: token inesperado, bloco não fechado)
```

Códigos de saída da CLI (já implementados em `App\Cli\Application`):

| Código | Significado |
|---|---|
| 0 | Sucesso ou ajuda exibida |
| 1 | Erro de compilação (léxico/sintático) — reservado para a integração |
| 2 | Uso incorreto ou arquivo de entrada inválido |
| 3 | Funcionalidade ainda não integrada |

## 5. Gerador de código

O diretório `src/Generator/` está preparado, mas o gerador **não está
implementado**. Quando implementado, o gerador deverá:

1. Receber uma AST válida (já verificada pelo Parser).
2. Identificar as APIs e os endpoints envolvidos em cada integração.
3. Produzir código PHP que utilize a extensão cURL.
4. Realizar a requisição HTTP ao endpoint de origem.
5. Interpretar a resposta JSON (`json_decode`).
6. Encaminhar os dados ao endpoint de destino.
7. Produzir tratamento básico de erros (falha de conexão, status HTTP de erro,
   JSON inválido), encerrando o script gerado com mensagem e código de saída.

Diretrizes para o código gerado:

- Script PHP autônomo: depende apenas de PHP com as extensões `curl` e `json`;
  **não** depende do Composer, deste projeto nem do Docker.
- Gravado em `output/` (diretório ignorado pelo Git).
- O compilador **nunca executa** o código gerado nem faz requisições HTTP;
  a execução é uma decisão explícita do usuário.
- Testes do gerador comparam o código produzido (texto) ou sua validade
  sintática (`php -l`), sem acessar a rede.

## 6. Estrutura de diretórios

```text
docs/                 documentação técnica (arquitetura, gramática, tokens, AST)
examples/valid/       programas .dsl válidos (casos de demonstração e teste)
examples/invalid/     programas .dsl com erros, separados por etapa:
  lexical/              erros detectados pelo Lexer
  syntactic/            erros detectados pelo Parser
src/Cli/              entrada de linha de comando (coordenação do pipeline)
src/Lexer/            analisador léxico
src/Parser/           analisador sintático
src/Ast/              nós da AST (se o grupo optar por classes)
src/Generator/        gerador de código PHP
src/Exceptions/       exceções compartilhadas do compilador
tests/Unit/           testes unitários por componente
tests/Integration/    testes ponta a ponta (main.php em processo separado)
tests/Fixtures/       arquivos auxiliares de teste
output/               código PHP gerado (não versionado)
docker/               imagem de desenvolvimento
```

**Adaptação em relação ao plano inicial:** foi adicionado `src/Cli/`. A lógica
da CLI fica em uma classe (`App\Cli\Application`) que recebe as streams de
saída por parâmetro, o que permite testá-la sem abrir processos. `main.php`
apenas carrega o autoload e delega a essa classe.

## 7. Testes

- **Unitários** (`tests/Unit/`): cada componente testado isoladamente.
  Ex.: Lexer recebe string e retorna tokens; Generator recebe uma AST montada
  manualmente e retorna código.
- **Integração** (`tests/Integration/`): executa `main.php` como o usuário faria.
- Os arquivos em `examples/` devem ser usados como fixtures pelos testes de cada
  etapa (todo exemplo inválido deve falhar na etapa indicada pelo diretório).
- Nenhum teste acessa a rede ou APIs externas.

## 8. Ambiente

- **Desenvolvimento 100% via Docker**: imagem `php:8.3-cli` com Composer
  (`docker/Dockerfile`), um único serviço `app` (`compose.yaml`). PHP,
  Composer e PHPUnit são sempre executados no contêiner; ver README.
- PHPUnit 12 (compatível com PHP 8.3).
- `composer.json` fixa `config.platform.php = 8.3.0`, garantindo que o
  `composer.lock` corresponda ao PHP da imagem.
- **Integração contínua** (`.github/workflows/ci.yaml`): em todo PR e push para
  `main`, roda direto no runner Ubuntu com PHP 8.3 e as mesmas extensões da
  imagem, executando `composer validate --strict`, `composer install` (a partir
  do lock), `composer check-platform-reqs` e `composer test`. Permissão
  `contents: read`, sem secrets, sem deploy e sem publicação de artefatos.

## 9. Registro de decisões

| # | Decisão | Motivo |
|---|---|---|
| D1 | PHP puro, sem frameworks | Requisito da disciplina; foco no compilador |
| D2 | Lexer e Parser escritos à mão, sem bibliotecas de parsing | Objetivo acadêmico é implementar as análises |
| D3 | CLI em classe testável (`src/Cli`) | Testes sem processos; `main.php` mínimo |
| D4 | Códigos de saída distintos por tipo de falha | Facilita testes e scripts |
| D5 | CLI não simula compilação enquanto o pipeline não existe | Evitar resultados enganosos |
| D6 | PHPUnit 12 + plataforma fixada em PHP 8.3 | PHPUnit 13 exige PHP 8.4 |
| D7 | Finais de linha LF (`.gitattributes`) | Linha/coluna de erros iguais em Windows e Linux |
| D8 | Desenvolvimento 100% via Docker (PHP, Composer, PHPUnit) | Mesmas versões para os quatro integrantes, em Windows e Linux |
| D9 | Código gerado não depende de Docker | Deve rodar em qualquer PHP com `curl` e `json` |
| D10 | CI direto no runner Ubuntu (PHP 8.3 via `setup-php`), sem Docker | Mais simples e rápido; mesmos comandos e extensões do ambiente Docker |
