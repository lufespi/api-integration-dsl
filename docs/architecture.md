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
Analyzer           (src/Semantic)    AST          -> mesma AST, com as regras de significado verificadas
    |
    v
PhpGenerator       (src/Generator)   AST          -> código PHP (string)
    |
    v
Arquivo PHP gerado (output/)
```

A **CLI** (`main.php` + `src/Cli`) coordena o pipeline: lê o arquivo, chama
cada etapa em ordem, grava o resultado em `output/<nome-do-arquivo>.php` e
traduz erros em mensagens e códigos de saída. A gravação é atômica
(temporário + renomeação); em caso de erro nenhum arquivo é gerado e um
`output/<nome>.php` de uma compilação anterior é removido. A CLI não executa o
PHP gerado nem faz requisições HTTP.

Cada etapa conhece apenas a saída da etapa anterior. O Generator não lê tokens
nem texto-fonte; o Parser não gera código. Isso permite que cada integrante
desenvolva e teste seu componente de forma isolada.

## 2. Responsabilidades

| Componente | Entrada | Saída | Responsável por | Não é responsável por |
|---|---|---|---|---|
| **Lexer** | `string` (texto-fonte) | lista de tokens | reconhecer lexemas, descartar espaços/comentários, registrar linha/coluna, reportar erros léxicos | verificar a ordem dos tokens |
| **Parser** | lista de tokens | AST (`Program`) | aplicar a GLC, construir a AST, reportar erros sintáticos com posição | ler arquivos, gerar código |
| **AST** | — | — | representar APIs, endpoints e integrações de forma estrutural (contrato Parser → Generator) | lógica de análise ou geração |
| **Analyzer** | AST sintaticamente válida | a mesma AST | verificar nomes únicos, referências `from`/`to`, alias e funções; reportar erros semânticos com posição ([`semantic-analysis.md`](semantic-analysis.md)) | validar sintaxe, gerar código |
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

// Analyzer
$program = (new Analyzer())->analyze($program);    // a mesma AST, ou SemanticException

// Generator
$php = (new PhpGenerator())->generate($program);   // string
```

O contrato detalhado da AST está em [`ast-contract.md`](ast-contract.md); os
tokens, em [`tokens.md`](tokens.md); a gramática, em [`grammar.md`](grammar.md).

## 4. Tratamento de erros

Lexer e Parser lançam exceções específicas, definidas em `src/Exceptions/`,
contendo mensagem, linha e coluna. A CLI captura essas exceções e encerra com
o código de saída adequado.

```text
CompilerException                 (base, contém linha/coluna opcionais)   ✅ implementada
├── LexicalException              (ex.: caractere inválido, texto não terminado)    ✅ implementada
├── SyntaxException               (ex.: token inesperado, bloco não fechado)   ✅ implementada
├── SemanticException             (ex.: API inexistente, nome duplicado, função desconhecida)   ✅ implementada (src/Semantic)
└── GenerationException           (ex.: método HTTP ou URL não suportados na geração)  ✅ implementada (src/Generator)
```

- `CompilerException` expõe `getSourceLine()`/`getSourceColumn()` (os nomes
  evitam conflito com `Exception::getLine()`, que é `final` e indica a linha
  do código PHP).
- `LexicalException` traz **um** erro: o Lexer para no primeiro, porque depois
  dele não há como saber onde o próximo token começa. A mensagem já vem
  formatada (ver [`tokens.md`](tokens.md), seção 5).
- `SyntaxException` reúne **todos** os erros sintáticos da análise
  (`errors()`, lista de `App\Parser\SyntaxError`), pois o Parser se recupera
  em modo pânico; a mensagem já vem formatada para o usuário (ver
  [`grammar.md`](grammar.md), seção 12). A CLI só precisa imprimir
  `getMessage()` em stderr e devolver `EXIT_COMPILATION_ERROR`.
- `SemanticException` reúne **todos** os erros semânticos (`errors()`, lista de
  `App\Semantic\SemanticError`), ordenados por posição no fonte; o formato da
  mensagem é o mesmo da `SyntaxException` (ver
  [`semantic-analysis.md`](semantic-analysis.md), seção 4).
- Violações de contrato entre etapas (ex.: lista de tokens sem `EOF`) não são
  erros do programa `.dsl`: o Parser lança `InvalidArgumentException` ou
  `LogicException`, tratadas pela CLI como erro interno.

Códigos de saída da CLI (`App\Cli\Application`):

| Código | Significado |
|---|---|
| 0 | Compilação concluída ou ajuda exibida |
| 1 | Erro de compilação (qualquer `CompilerException`: léxico, sintático, semântico ou de geração) ou falha de leitura/escrita |
| 2 | Uso incorreto ou arquivo de entrada inválido |

## 5. Gerador de código

**Implementado** em `src/Generator/`; detalhes, semântica de runtime,
verificações e decisões provisórias em [`generator.md`](generator.md).
Conforme previsto, o gerador deve:

1. Receber uma AST válida (já verificada pelo Parser e pelo Analyzer).
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
- Logs do script ocultam a query string das URLs (`?[REDACTED]`) e nunca
  exibem valores de cabeçalhos ou variáveis de ambiente.
- O compilador **nunca executa** o código gerado nem faz requisições HTTP;
  a execução é uma decisão explícita do usuário.
- Testes do gerador verificam o código produzido (texto e `php -l`), executam
  suas funções com dados simulados e rodam o script contra um servidor HTTP
  local (`127.0.0.1`), sem acessar a rede externa.

## 6. Estrutura de diretórios

```text
docs/                 documentação técnica (arquitetura, gramática, tokens, AST)
examples/valid/       programas .dsl válidos (casos de demonstração e teste)
examples/invalid/     programas .dsl com erros, separados por etapa:
  lexical/              erros detectados pelo Lexer
  syntactic/            erros detectados pelo Parser
  semantic/             erros detectados pelo Analyzer
src/Cli/              entrada de linha de comando (coordenação do pipeline)
src/Lexer/            analisador léxico
src/Parser/           analisador sintático
src/Ast/              nós da AST (se o grupo optar por classes)
src/Semantic/         analisador semântico
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
| D5 | CLI não simula compilação: sem `output/<nome>.php` em caso de erro, e o arquivo de uma compilação anterior é removido | Evitar resultados enganosos |
| D6 | PHPUnit 12 + plataforma fixada em PHP 8.3 | PHPUnit 13 exige PHP 8.4 |
| D7 | Finais de linha LF (`.gitattributes`) | Linha/coluna de erros iguais em Windows e Linux |
| D8 | Desenvolvimento 100% via Docker (PHP, Composer, PHPUnit) | Mesmas versões para os quatro integrantes, em Windows e Linux |
| D9 | Código gerado não depende de Docker | Deve rodar em qualquer PHP com `curl` e `json` |
| D10 | CI direto no runner Ubuntu (PHP 8.3 via `setup-php`), sem Docker | Mais simples e rápido; mesmos comandos e extensões do ambiente Docker |
| D11 | Parser descendente recursivo preditivo LL(1) | Gramática pequena e iniciada por palavras reservadas; código espelha a GLC; mensagens de erro precisas ([`grammar.md`](grammar.md), seção 7) |
| D12 | Recuperação de erros sintáticos em modo pânico, sincronizando em `api`/`integration` | Relatar vários erros independentes em uma execução, sem erros em cascata |
| D13 | AST em classes `readonly` com `toArray()` | Tipagem forte para o Generator, sem perder o formato de arrays proposto ([`ast-contract.md`](ast-contract.md)) |
| D14 | Contrato de tokens (`Token`, `TokenType`) fixado em `src/Lexer/` antes do Lexer | Permitir desenvolver e testar o Parser em paralelo; com o Lexer pronto, os testes do Parser passaram a usá-lo |
| D15 | Análise semântica em etapa própria (`Analyzer`) entre Parser e Generator, coletando todos os erros | O Generator assume uma AST com significado válido; erros do programa saem com linha e coluna, não como falhas na geração ([`semantic-analysis.md`](semantic-analysis.md)) |
| D16 | Funções embutidas do Analyzer iguais às do Generator (só `env("NOME")`); o Generator mantém suas verificações defensivas | Uma função aceita na análise nunca falha só na geração; restrições próprias da geração (métodos HTTP, URLs) continuam no Generator |
